from contextlib import asynccontextmanager
import time

import torch
from fastapi import FastAPI, HTTPException

from app.config import settings
from app.model_manager import model_manager
from app.schemas import SegmentReport, TranslationRequest, TranslationResponse
from app.translator import translation_service


started_at = time.time()


@asynccontextmanager
async def lifespan(app: FastAPI):
    if settings.preload_models:
        model_manager.load_all_models()
    yield
    if torch.cuda.is_available():
        torch.cuda.empty_cache()


app = FastAPI(
    title="Smart Digital Library AI Service",
    version="1.0.0",
    lifespan=lifespan,
)


@app.get("/health")
def health_check():
    model_health = model_manager.health()
    return {
        "success": True,
        "status": "ready",
        "provider": "opus+nllb",
        "models": model_health["configured_models"],
        "loaded_models": model_health["loaded_models"],
        "device": model_health["device"],
        "supported_languages": ["en", "fr", "rw", "sw"],
        "quality_modes": ["fast", "balanced", "accurate"],
        "preload_models": model_health["preload_models"],
        "uptime_seconds": round(time.time() - started_at, 2),
        "error": None,
    }


@app.post("/translate", response_model=TranslationResponse)
def translate_text(request: TranslationRequest):
    if request.source_language == request.target_language:
        return TranslationResponse(
            success=True,
            source_language=request.source_language,
            target_language=request.target_language,
            translated_text=request.text.strip(),
            provider="none",
            model="identity",
            segments=1,
            validation=[SegmentReport(order=1, status="translated", confidence=1.0, problems=[])],
        )

    text = request.text.strip()
    if len(text) > settings.maximum_text_characters:
        raise HTTPException(status_code=413, detail=f"Text exceeds {settings.maximum_text_characters} characters.")

    try:
        outputs = translation_service.translate_large_text(
            text,
            request.source_language,
            request.target_language,
            request.quality_mode,
        )
    except ValueError as error:
        raise HTTPException(status_code=400, detail=str(error)) from error
    except RuntimeError as error:
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
        raise HTTPException(status_code=503, detail=f"Translation engine temporarily unavailable: {error}") from error

    return TranslationResponse(
        success=True,
        source_language=request.source_language,
        target_language=request.target_language,
        translated_text="\n\n".join(output.translated_text for output in outputs),
        provider=outputs[0].provider if outputs else "none",
        model=outputs[0].model if outputs else "none",
        segments=len(outputs),
        validation=[
            SegmentReport(
                order=index,
                status="translated" if output.validation.valid else "needs_review",
                confidence=output.validation.confidence,
                problems=output.validation.problems,
            )
            for index, output in enumerate(outputs, start=1)
        ],
    )


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8001)
