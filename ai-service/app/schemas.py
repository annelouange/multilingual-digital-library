from typing import Literal

from pydantic import BaseModel, Field


SupportedLanguage = Literal["en", "fr", "rw", "sw"]
QualityMode = Literal["fast", "balanced", "accurate"]


class TranslationRequest(BaseModel):
    text: str = Field(min_length=1, max_length=2_000_000)
    source_language: SupportedLanguage = "en"
    target_language: SupportedLanguage
    quality_mode: QualityMode = "balanced"


class SegmentReport(BaseModel):
    order: int
    status: str
    confidence: float
    problems: list[str] = []


class TranslationResponse(BaseModel):
    success: bool = True
    source_language: str
    target_language: str
    translated_text: str
    provider: str
    model: str
    segments: int
    validation: list[SegmentReport]
