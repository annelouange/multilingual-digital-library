import math
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import librosa
import numpy as np
import soundfile as sf
import torch
import uvicorn
from fastapi import FastAPI, File, HTTPException, UploadFile
from transformers import Wav2Vec2ForCTC, Wav2Vec2Processor


MODEL_CANDIDATES = [
    Path("transformer_model"),
    Path("transformer_librispeech_model"),
]
BASE_MODEL = os.getenv("MDL_TRANSFORMER_MODEL", "facebook/wav2vec2-base-960h")
MAX_UPLOAD_BYTES = 12 * 1024 * 1024

app = FastAPI(title="Multilingual Digital Library Transformer English STT")
torch.set_num_threads(max(1, min(4, torch.get_num_threads())))


def resolve_model_source() -> str:
    for candidate in MODEL_CANDIDATES:
        if (candidate / "config.json").exists():
            return str(candidate)
    return BASE_MODEL


MODEL_SOURCE = resolve_model_source()
processor = Wav2Vec2Processor.from_pretrained(MODEL_SOURCE)
model = Wav2Vec2ForCTC.from_pretrained(MODEL_SOURCE)
model.eval()


def warmup_model() -> None:
    sample = np.zeros(16000, dtype=np.float32)
    inputs = processor(sample, sampling_rate=16000, return_tensors="pt")
    with torch.inference_mode():
        model(**inputs)


warmup_model()


def normalize_browser_audio(source: Path, target: Path) -> None:
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg:
        completed = subprocess.run(
            [
                ffmpeg,
                "-hide_banner",
                "-loglevel",
                "error",
                "-y",
                "-i",
                str(source),
                "-ac",
                "1",
                "-ar",
                "16000",
                "-t",
                "10",
                str(target),
            ],
            capture_output=True,
            text=True,
            timeout=25,
        )
        if completed.returncode == 0 and target.exists() and target.stat().st_size > 44:
            return
    audio, _ = librosa.load(str(source), sr=16000, mono=True)
    import soundfile as sf
    sf.write(str(target), audio, 16000)


def confidence_from_logits(logits: torch.Tensor) -> float:
    probabilities = torch.softmax(logits, dim=-1)
    token_confidence = probabilities.max(dim=-1).values.mean().item()
    return max(0.0, min(1.0, float(token_confidence)))


@app.get("/health")
def health():
    return {
        "status": "ready",
        "model": "transformer-wav2vec2-ctc",
        "base_model": BASE_MODEL,
        "model_path": MODEL_SOURCE,
        "parameters": model.num_parameters(),
        "language": "en",
        "task": "open_vocabulary_speech_to_text",
    }


@app.get("/model/info")
def model_info():
    return health()


@app.post("/transcribe")
@app.post("/api/stt/transcribe")
async def transcribe(audio: UploadFile = File(None), file: UploadFile = File(None)):
    upload = file or audio
    if upload is None:
        raise HTTPException(status_code=422, detail="Audio file is required")
    payload = await upload.read()
    if not payload:
        raise HTTPException(status_code=422, detail="Audio file is empty")
    if len(payload) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="Audio file is too large")

    suffix = Path(upload.filename or "voice.webm").suffix or ".webm"
    with tempfile.TemporaryDirectory(prefix="mdl-transformer-stt-") as directory:
        source = Path(directory) / f"recording{suffix}"
        normalized = Path(directory) / "normalized.wav"
        source.write_bytes(payload)
        try:
            normalize_browser_audio(source, normalized)
            audio_data, sample_rate = sf.read(str(normalized), dtype="float32")
            if sample_rate != 16000:
                audio_data = librosa.resample(audio_data, orig_sr=sample_rate, target_sr=16000)
            if audio_data.ndim > 1:
                audio_data = audio_data.mean(axis=1)
            inputs = processor(audio_data, sampling_rate=16000, return_tensors="pt")
            with torch.inference_mode():
                logits = model(**inputs).logits
            predicted_ids = torch.argmax(logits, dim=-1)
            transcription = " ".join(processor.batch_decode(predicted_ids)[0].strip().split())
            confidence = confidence_from_logits(logits)
        except Exception as error:
            raise HTTPException(status_code=422, detail=str(error)) from error

    return {
        "success": bool(transcription),
        "transcription": transcription,
        "predicted_text": transcription,
        "confidence": confidence if math.isfinite(confidence) else 0.0,
        "model": "transformer-wav2vec2-ctc",
        "model_path": MODEL_SOURCE,
        "language": "en",
    }


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=5006, log_level="info")
