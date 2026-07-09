import base64
import io
import os
from functools import lru_cache

import numpy as np
import torch
import uvicorn
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from scipy.io import wavfile
from transformers import AutoTokenizer, VitsModel


LANGUAGE_MODELS = {
    "en": os.getenv("MMS_TTS_EN_MODEL", "facebook/mms-tts-eng"),
    "fr": os.getenv("MMS_TTS_FR_MODEL", "facebook/mms-tts-fra"),
    "rw": os.getenv("MMS_TTS_RW_MODEL", "facebook/mms-tts-kin"),
    "sw": os.getenv("MMS_TTS_SW_MODEL", "facebook/mms-tts-swh"),
}
MAX_TEXT_CHARS = int(os.getenv("MMS_TTS_MAX_TEXT_CHARS", "700"))
DEVICE = "cuda" if torch.cuda.is_available() and os.getenv("MMS_TTS_DEVICE", "auto") != "cpu" else "cpu"

app = FastAPI(title="Multilingual Digital Library MMS Multilingual TTS Service")
torch.set_num_threads(max(1, min(4, torch.get_num_threads())))


class TtsRequest(BaseModel):
    text: str
    language: str = "en"


def normalize_language(language: str) -> str:
    code = (language or "en").lower().strip()
    return code if code in LANGUAGE_MODELS else "en"


@lru_cache(maxsize=4)
def load_model(language: str):
    model_id = LANGUAGE_MODELS[normalize_language(language)]
    tokenizer = AutoTokenizer.from_pretrained(model_id)
    model = VitsModel.from_pretrained(model_id).to(DEVICE)
    model.eval()
    return model_id, tokenizer, model


def clean_text(text: str) -> str:
    return " ".join(str(text or "").replace("\x00", " ").split())[:MAX_TEXT_CHARS]


@app.get("/health")
def health():
    return {
        "success": True,
        "status": "ready",
        "provider": "mms_tts",
        "device": DEVICE,
        "supported_languages": list(LANGUAGE_MODELS.keys()),
        "models": LANGUAGE_MODELS,
        "lazy_load": True,
        "max_text_chars": MAX_TEXT_CHARS,
    }


@app.post("/synthesize")
def synthesize(payload: TtsRequest):
    language = normalize_language(payload.language)
    text = clean_text(payload.text)
    if not text:
        raise HTTPException(status_code=422, detail="Text is required")

    try:
        model_id, tokenizer, model = load_model(language)
        inputs = tokenizer(text, return_tensors="pt")
        inputs = {key: value.to(DEVICE) for key, value in inputs.items()}
        with torch.inference_mode():
            waveform = model(**inputs).waveform.squeeze().detach().cpu().numpy().astype(np.float32)
        buffer = io.BytesIO()
        wavfile.write(buffer, rate=int(model.config.sampling_rate), data=waveform)
    except Exception as error:
        raise HTTPException(status_code=500, detail=str(error)) from error

    audio = buffer.getvalue()
    return {
        "success": True,
        "provider": "mms_tts",
        "model": model_id,
        "language": language,
        "mime_type": "audio/wav",
        "sample_rate": int(model.config.sampling_rate),
        "bytes": len(audio),
        "audio_base64": base64.b64encode(audio).decode("ascii"),
    }


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=5009, log_level="info")