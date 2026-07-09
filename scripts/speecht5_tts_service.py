import base64
import io
import time

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from scipy.io.wavfile import write as write_wav

from speecht5_synthesize import MODEL_ID, VOCODER_ID, load_speecht5, synthesize_waveform


MAX_CHARS = 1000

app = FastAPI(title="Multilingual Digital Library SpeechT5 TTS Service")


class TtsRequest(BaseModel):
    text: str
    language: str = "en"


started_at = time.time()
processor = None
model = None
vocoder = None
speaker_embeddings = None
warmup_error = None


def ensure_model_loaded():
    global processor, model, vocoder, speaker_embeddings, warmup_error
    if model is not None:
        return
    try:
        processor, model, vocoder, speaker_embeddings = load_speecht5(local_files_only=False)
        synthesize_waveform("Rwanda Library narration is ready.", processor, model, vocoder, speaker_embeddings)
        warmup_error = None
    except Exception as exc:
        warmup_error = str(exc)
        raise


@app.on_event("startup")
def startup() -> None:
    ensure_model_loaded()


@app.get("/health")
def health():
    ready = model is not None and warmup_error is None
    return {
        "status": "ready" if ready else "not_ready",
        "success": ready,
        "provider": "speecht5_service",
        "model": MODEL_ID,
        "vocoder": VOCODER_ID,
        "uptime_seconds": round(time.time() - started_at, 2),
        "error": warmup_error,
    }


@app.post("/synthesize")
def synthesize(payload: TtsRequest):
    text = payload.text.strip()
    if payload.language != "en":
        raise HTTPException(status_code=422, detail="SpeechT5 narration is configured for English only")
    if not text:
        raise HTTPException(status_code=422, detail="Narration text is empty")
    if len(text) > MAX_CHARS:
        raise HTTPException(status_code=422, detail=f"Narration text must be {MAX_CHARS} characters or shorter")

    try:
        ensure_model_loaded()
        started = time.perf_counter()
        audio = synthesize_waveform(text, processor, model, vocoder, speaker_embeddings)
        buffer = io.BytesIO()
        write_wav(buffer, 16000, audio)
        wav_bytes = buffer.getvalue()
        return {
            "success": True,
            "provider": "speecht5_service",
            "model": MODEL_ID,
            "mime_type": "audio/wav",
            "audio_base64": base64.b64encode(wav_bytes).decode("ascii"),
            "bytes": len(wav_bytes),
            "elapsed_seconds": round(time.perf_counter() - started, 3),
        }
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=5007, log_level="info")
