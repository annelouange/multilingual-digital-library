import argparse
import json
import re
import time
from pathlib import Path

MODEL_ID = "microsoft/speecht5_tts"
VOCODER_ID = "microsoft/speecht5_hifigan"
SPEAKER_DATASET_ID = "Matthijs/cmu-arctic-xvectors"
MAX_CHARS = 450
PRELOAD_TEXT = "MULTILINGUAL DIGITAL LIBRARY narration is ready."
DEFAULT_PRELOAD_FILE = Path(__file__).resolve().parents[1] / "tmp" / "speecht5-preload.wav"


def chunk_text(text: str) -> list[str]:
    sentences = re.split(r"(?<=[.!?])\s+", text)
    chunks: list[str] = []
    current = ""
    for sentence in sentences:
        sentence = sentence.strip()
        if not sentence:
            continue
        if len(sentence) > MAX_CHARS:
            parts = [sentence[i:i + MAX_CHARS] for i in range(0, len(sentence), MAX_CHARS)]
        else:
            parts = [sentence]
        for part in parts:
            candidate = f"{current} {part}".strip()
            if len(candidate) <= MAX_CHARS:
                current = candidate
            else:
                if current:
                    chunks.append(current)
                current = part
    if current:
        chunks.append(current)
    return chunks


def load_speaker_embedding():
    import numpy as np
    import torch
    from datasets import load_dataset

    # SpeechT5 needs a 512-dimensional speaker embedding. Use the common CMU
    # Arctic x-vector example voice when available, with a neutral fallback so
    # narration still works offline after code checkout.
    try:
        embeddings = load_dataset(SPEAKER_DATASET_ID, split="validation")
        vector = np.asarray(embeddings[7306]["xvector"], dtype=np.float32)
    except Exception:
        vector = np.zeros(512, dtype=np.float32)
        vector[0] = 1.0
    return torch.tensor(vector).unsqueeze(0)


def load_speecht5(local_files_only: bool = False):
    from transformers import SpeechT5ForTextToSpeech, SpeechT5HifiGan, SpeechT5Processor

    processor = SpeechT5Processor.from_pretrained(MODEL_ID, local_files_only=local_files_only)
    model = SpeechT5ForTextToSpeech.from_pretrained(MODEL_ID, local_files_only=local_files_only)
    vocoder = SpeechT5HifiGan.from_pretrained(VOCODER_ID, local_files_only=local_files_only)
    speaker_embeddings = load_speaker_embedding()
    return processor, model, vocoder, speaker_embeddings


def synthesize_text(text: str, target: Path, local_files_only: bool = False) -> dict:
    import numpy as np
    from scipy.io.wavfile import write as write_wav

    processor, model, vocoder, speaker_embeddings = load_speecht5(local_files_only=local_files_only)
    pcm = synthesize_waveform(text, processor, model, vocoder, speaker_embeddings)

    target.parent.mkdir(parents=True, exist_ok=True)
    write_wav(str(target), 16000, pcm)
    return {
        "output": str(target),
        "bytes": target.stat().st_size,
        "provider": "speecht5",
        "model": MODEL_ID,
        "vocoder": VOCODER_ID,
    }


def synthesize_waveform(text: str, processor, model, vocoder, speaker_embeddings):
    import numpy as np
    import torch

    waveforms = []
    silence = np.zeros(2400, dtype=np.float32)
    for chunk in chunk_text(text):
        inputs = processor(text=chunk, return_tensors="pt")
        with torch.no_grad():
            speech = model.generate_speech(inputs["input_ids"], speaker_embeddings, vocoder=vocoder)
        waveforms.append(speech.cpu().numpy().astype(np.float32))
        waveforms.append(silence)

    if not waveforms:
        raise ValueError("No valid narration chunks were generated")

    audio = np.concatenate(waveforms)
    audio = np.clip(audio, -1.0, 1.0)
    return (audio * 32767).astype(np.int16)


def preload_status(preload_file: Path) -> dict:
    return {
        "file": str(preload_file),
        "ready": preload_file.is_file() and preload_file.stat().st_size > 500,
        "bytes": preload_file.stat().st_size if preload_file.is_file() else 0,
    }


def preload_manifest_file(preload_file: Path) -> Path:
    return preload_file.with_suffix(".json")


def read_preload_manifest(preload_file: Path) -> dict | None:
    manifest_file = preload_manifest_file(preload_file)
    if not manifest_file.is_file():
        return None
    try:
        return json.loads(manifest_file.read_text(encoding="utf-8"))
    except Exception:
        return None


def write_preload_manifest(preload_file: Path, result: dict) -> dict:
    payload = {
        "success": True,
        "status": "ready",
        "provider": "speecht5",
        "model": MODEL_ID,
        "vocoder": VOCODER_ID,
        "preload": preload_status(preload_file),
        "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "source": "actual_synthesis_preload",
        **result,
    }
    preload_manifest_file(preload_file).write_text(json.dumps(payload, indent=2), encoding="utf-8")
    return payload


def health_payload(preload_file: Path) -> dict:
    started = time.perf_counter()
    manifest = read_preload_manifest(preload_file)
    current_preload = preload_status(preload_file)
    payload = {
        "success": False,
        "provider": "speecht5",
        "model": MODEL_ID,
        "vocoder": VOCODER_ID,
        "preload": current_preload,
        "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "manifest": str(preload_manifest_file(preload_file)),
    }

    if manifest and manifest.get("model") == MODEL_ID and manifest.get("vocoder") == VOCODER_ID:
        payload["manifest_ready"] = bool(manifest.get("success"))
        payload["success"] = payload["manifest_ready"] and current_preload["ready"]
        payload["status"] = "ready" if payload["success"] else "preload_missing"
        payload["preloaded_at"] = manifest.get("checked_at")
    else:
        payload["manifest_ready"] = False
        payload["status"] = "preload_manifest_missing"

    payload["elapsed_seconds"] = round(time.perf_counter() - started, 3)
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate a WAV narration with Microsoft SpeechT5.")
    parser.add_argument("input_file", nargs="?")
    parser.add_argument("output_file", nargs="?")
    parser.add_argument("--lang", default="en")
    parser.add_argument("--health", action="store_true", help="Check cached SpeechT5 readiness and preload audio.")
    parser.add_argument("--preload", action="store_true", help="Load SpeechT5 and synthesize the warmup WAV.")
    parser.add_argument("--preload-file", default=str(DEFAULT_PRELOAD_FILE))
    args = parser.parse_args()

    preload_file = Path(args.preload_file)

    if args.health:
        print(json.dumps(health_payload(preload_file)))
        return 0

    if args.preload:
        try:
            result = synthesize_text(PRELOAD_TEXT, preload_file, local_files_only=False)
            payload = write_preload_manifest(preload_file, result)
            print(json.dumps({
                **payload,
            }))
            return 0
        except Exception as exc:
            print(json.dumps({"success": False, "status": "preload_failed", "error": str(exc)}))
            return 1

    if args.lang != "en":
        print(json.dumps({"success": False, "error": "SpeechT5 narration is currently configured for English only"}))
        return 2
    if not args.input_file or not args.output_file:
        print(json.dumps({"success": False, "error": "input_file and output_file are required"}))
        return 2

    source = Path(args.input_file)
    target = Path(args.output_file)
    text = source.read_text(encoding="utf-8").strip()
    if not text:
        print(json.dumps({"success": False, "error": "Narration text is empty"}))
        return 2

    try:
        result = synthesize_text(text, target)
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1

    print(json.dumps({
        "success": True,
        **result,
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
