import math
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import torch
import uvicorn
import whisper
from fastapi import FastAPI, File, Form, HTTPException, UploadFile


MODEL_NAME = os.getenv("MDL_WHISPER_MODEL", "tiny")
MAX_UPLOAD_BYTES = 10 * 1024 * 1024
LANGUAGE_MAP = {
    "en": "en",
    "fr": "fr",
    "rw": "rw",
    "sw": "sw",
}
LANGUAGE_PROMPTS = {
    "en": "MULTILINGUAL DIGITAL LIBRARY. Search books by title, author, topic, course, faculty, or department.",
    "fr": "Bibliotheque numerique Rwanda Library. Rechercher des livres par titre, auteur, sujet, cours, faculte ou departement.",
    "rw": "Isomero rya Rwanda Library. Shaka ibitabo ukoresheje umutwe, umwanditsi, isomo, fakilite cyangwa ishami.",
    "sw": "Maktaba ya kidijitali ya Rwanda Library. Tafuta vitabu kwa kichwa, mwandishi, somo, kozi, kitivo au idara.",
}

app = FastAPI(title="Multilingual Digital Library Open-Vocabulary STT")
torch.set_num_threads(max(1, min(4, torch.get_num_threads())))
model = whisper.load_model(MODEL_NAME, device="cpu")


def normalize_language(language: str) -> str:
    language = (language or "en").lower().strip()
    return LANGUAGE_MAP.get(language, "en")


def effective_model_language(language: str) -> str:
    if MODEL_NAME.endswith(".en"):
        return "en"
    return normalize_language(language)


def library_prompt(language: str) -> str:
    return LANGUAGE_PROMPTS.get(normalize_language(language), LANGUAGE_PROMPTS["en"])


def normalize_browser_audio(source: Path, target: Path) -> None:
    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        raise RuntimeError("FFmpeg is required to decode microphone audio")
    completed = subprocess.run(
        [
            ffmpeg,
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-i",
            str(source),
            "-af",
            "silenceremove=start_periods=1:start_duration=0.03:start_threshold=-45dB",
            "-ac",
            "1",
            "-ar",
            "16000",
            "-t",
            "8",
            str(target),
        ],
        capture_output=True,
        text=True,
        timeout=25,
    )
    if completed.returncode != 0 or not target.exists() or target.stat().st_size <= 44:
        raise RuntimeError(completed.stderr.strip() or "Audio conversion failed")


@app.get("/health")
def health():
    multilingual = not MODEL_NAME.endswith(".en")
    return {
        "success": True,
        "status": "ready",
        "model": f"whisper-{MODEL_NAME}",
        "language": "multilingual" if multilingual else "en",
        "supported_languages": list(LANGUAGE_MAP.keys()) if multilingual else ["en"],
        "task": "open_vocabulary_speech_to_text",
        "device": "cpu",
        "note": "Use a multilingual Whisper checkpoint such as tiny/base/small for French, Kinyarwanda, and Kiswahili. *.en checkpoints are English-only.",
    }


@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...), language: str = Form("en")):
    requested_language = normalize_language(language)
    payload = await audio.read()
    if not payload:
        raise HTTPException(status_code=422, detail="The microphone recording is empty")
    if len(payload) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="The microphone recording is too large")

    suffix = Path(audio.filename or "voice.webm").suffix or ".webm"
    with tempfile.TemporaryDirectory(prefix="mdl-whisper-stt-") as directory:
        source = Path(directory) / f"recording{suffix}"
        normalized = Path(directory) / "normalized.wav"
        source.write_bytes(payload)
        try:
            normalize_browser_audio(source, normalized)
            result = model.transcribe(
                str(normalized),
                language=effective_model_language(requested_language),
                task="transcribe",
                fp16=False,
                temperature=0,
                beam_size=1,
                condition_on_previous_text=False,
                initial_prompt=library_prompt(requested_language),
            )
        except Exception as error:
            raise HTTPException(status_code=422, detail=str(error)) from error

    transcript = " ".join(str(result.get("text", "")).strip().split())
    segments = result.get("segments") or []
    average_log_probability = (
        sum(float(segment.get("avg_logprob", -10)) for segment in segments) / len(segments)
        if segments else -10
    )
    confidence = max(0.0, min(1.0, math.exp(average_log_probability)))
    return {
        "success": bool(transcript),
        "transcription": transcript,
        "confidence": confidence,
        "confidence_type": "average_token_probability_estimate",
        "language": requested_language,
        "model_language": effective_model_language(requested_language),
        "model": f"whisper-{MODEL_NAME}",
    }


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=5001, log_level="info")