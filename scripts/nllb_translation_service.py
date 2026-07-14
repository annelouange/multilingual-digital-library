import os
import re
import time
from functools import lru_cache
from pathlib import Path
from typing import List

import torch
import uvicorn
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer


MODEL_NAME = os.getenv("NLLB_MODEL", "facebook/nllb-200-distilled-600M")
OPUS_FR_EN_MODEL = os.getenv("OPUS_FR_EN_MODEL", r"D:\mdl-translation-models\opus-fr-en\final")
OPUS_EN_FR_MODEL = os.getenv("OPUS_EN_FR_MODEL", "")
MAX_TEXT_CHARS = int(os.getenv("NLLB_MAX_TEXT_CHARS", "20000"))
MAX_CHUNK_CHARS = int(os.getenv("NLLB_MAX_CHUNK_CHARS", "900"))
MAX_NEW_TOKENS = int(os.getenv("NLLB_MAX_NEW_TOKENS", "512"))

LANGUAGE_MAP = {
    "en": "eng_Latn",
    "fr": "fra_Latn",
}

app = FastAPI(title="Multilingual Library NLLB Translation Service")
started_at = time.time()


class TranslateRequest(BaseModel):
    text: str = Field(..., min_length=1)
    source_language: str = "en"
    target_language: str


def device_name() -> str:
    return "cuda" if torch.cuda.is_available() else "cpu"


@lru_cache(maxsize=1)
def load_model():
    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_NAME)
    model.to(device_name())
    model.eval()
    return tokenizer, model


@lru_cache(maxsize=4)
def load_opus_model(model_name: str):
    tokenizer = AutoTokenizer.from_pretrained(model_name)
    model = AutoModelForSeq2SeqLM.from_pretrained(model_name)
    model.to(device_name())
    model.eval()
    return tokenizer, model


def opus_model_for(source_language: str, target_language: str) -> str:
    if source_language == "fr" and target_language == "en" and OPUS_FR_EN_MODEL:
        return OPUS_FR_EN_MODEL
    if source_language == "en" and target_language == "fr" and OPUS_EN_FR_MODEL:
        return OPUS_EN_FR_MODEL
    return ""


def model_is_configured(model_name: str) -> bool:
    return bool(model_name and (Path(model_name).exists() or "/" in model_name))


def normalize_language(code: str) -> str:
    value = (code or "").strip().lower()
    if value not in LANGUAGE_MAP:
        raise HTTPException(status_code=422, detail=f"Unsupported language: {code}")
    return value


def split_text(text: str) -> List[str]:
    text = re.sub(r"\s+\n", "\n", text.strip())
    paragraphs = [part.strip() for part in re.split(r"\n{2,}", text) if part.strip()]
    chunks: List[str] = []
    for paragraph in paragraphs or [text.strip()]:
        current = ""
        sentences = re.split(r"(?<=[.!?])\s+", paragraph)
        for sentence in sentences:
            if not sentence:
                continue
            candidate = f"{current} {sentence}".strip()
            if len(candidate) <= MAX_CHUNK_CHARS:
                current = candidate
                continue
            if current:
                chunks.append(current)
            while len(sentence) > MAX_CHUNK_CHARS:
                chunks.append(sentence[:MAX_CHUNK_CHARS])
                sentence = sentence[MAX_CHUNK_CHARS:]
            current = sentence
        if current:
            chunks.append(current)
    return chunks


def translate_chunk(text: str, source_language: str, target_language: str) -> str:
    opus_model = opus_model_for(source_language, target_language)
    if opus_model and (Path(opus_model).exists() or "/" in opus_model):
        tokenizer, model = load_opus_model(opus_model)
        inputs = tokenizer(text, return_tensors="pt", truncation=True, max_length=512).to(model.device)
        with torch.inference_mode():
            generated = model.generate(**inputs, max_new_tokens=MAX_NEW_TOKENS)
        return tokenizer.batch_decode(generated, skip_special_tokens=True)[0]

    tokenizer, model = load_model()
    source_nllb = LANGUAGE_MAP[source_language]
    target_nllb = LANGUAGE_MAP[target_language]
    tokenizer.src_lang = source_nllb
    inputs = tokenizer(text, return_tensors="pt", truncation=True, max_length=512).to(model.device)
    forced_bos_token_id = tokenizer.convert_tokens_to_ids(target_nllb)
    with torch.inference_mode():
        generated = model.generate(
            **inputs,
            forced_bos_token_id=forced_bos_token_id,
            max_new_tokens=MAX_NEW_TOKENS,
        )
    return tokenizer.batch_decode(generated, skip_special_tokens=True)[0]


@app.get("/health")
def health():
    opus_models = {
        "fr-en": OPUS_FR_EN_MODEL if model_is_configured(OPUS_FR_EN_MODEL) else None,
        "en-fr": OPUS_EN_FR_MODEL if model_is_configured(OPUS_EN_FR_MODEL) else None,
    }
    return {
        "success": True,
        "status": "ready",
        "provider": "opus+nllb",
        "model": MODEL_NAME,
        "opus_models": opus_models,
        "device": device_name(),
        "supported_languages": sorted(LANGUAGE_MAP.keys()),
        "uptime_seconds": round(time.time() - started_at, 2),
        "error": None,
    }


@app.post("/translate")
def translate(payload: TranslateRequest):
    source_language = normalize_language(payload.source_language)
    target_language = normalize_language(payload.target_language)
    text = payload.text.strip()
    if len(text) > MAX_TEXT_CHARS:
        raise HTTPException(status_code=413, detail=f"Text exceeds {MAX_TEXT_CHARS} characters")
    if source_language == target_language:
        return {
            "success": True,
            "translated_text": text,
            "provider": "nllb",
            "model": MODEL_NAME,
            "source_language": source_language,
            "target_language": target_language,
            "chunks": 1,
        }
    chunks = split_text(text)
    translated = [translate_chunk(chunk, source_language, target_language) for chunk in chunks]
    provider = "opus" if opus_model_for(source_language, target_language) else "nllb"
    model_name = opus_model_for(source_language, target_language) or MODEL_NAME
    return {
        "success": True,
        "translated_text": "\n\n".join(translated),
        "provider": provider,
        "model": model_name,
        "source_language": source_language,
        "target_language": target_language,
        "chunks": len(chunks),
    }


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=int(os.getenv("NLLB_TRANSLATION_PORT", "5008")))
