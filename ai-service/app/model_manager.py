from __future__ import annotations

import time
from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Dict, Tuple

import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

from app.config import settings


@dataclass
class LoadedTranslationModel:
    tokenizer: object
    model: object
    device: torch.device
    model_name: str
    provider: str
    load_seconds: float


class ModelManager:
    def __init__(self) -> None:
        self._models: Dict[Tuple[str, str], LoadedTranslationModel] = {}
        self._lock = Lock()
        self._model_paths = {
            ("en", "fr"): settings.en_fr_model_path,
            ("fr", "en"): settings.fr_en_model_path,
        }

    def configured_models(self) -> Dict[str, str]:
        return {f"{source}-{target}": model for (source, target), model in self._model_paths.items()}

    def load_all_models(self) -> None:
        for source_language, target_language in self._model_paths:
            self.get_model(source_language, target_language)

    def get_model(self, source_language: str, target_language: str) -> LoadedTranslationModel:
        language_pair = (source_language.lower(), target_language.lower())
        if language_pair not in self._model_paths:
            raise ValueError(f"Unsupported OPUS language pair: {source_language} -> {target_language}")

        if language_pair not in self._models:
            with self._lock:
                if language_pair not in self._models:
                    self._models[language_pair] = self._load_model(self._model_paths[language_pair], "opus")

        return self._models[language_pair]

    def get_nllb_model(self) -> LoadedTranslationModel:
        language_pair = ("nllb", "multilingual")
        if language_pair not in self._models:
            with self._lock:
                if language_pair not in self._models:
                    self._models[language_pair] = self._load_model(settings.nllb_model_path, "nllb")
        return self._models[language_pair]

    @staticmethod
    def _load_model(model_path: str, provider: str) -> LoadedTranslationModel:
        started = time.time()
        device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        local_only = Path(model_path).exists()
        tokenizer = AutoTokenizer.from_pretrained(model_path, local_files_only=local_only)
        model = AutoModelForSeq2SeqLM.from_pretrained(model_path, local_files_only=local_only)
        model.to(device)
        model.eval()
        for parameter in model.parameters():
            parameter.requires_grad = False
        return LoadedTranslationModel(
            tokenizer=tokenizer,
            model=model,
            device=device,
            model_name=model_path,
            provider=provider,
            load_seconds=round(time.time() - started, 3),
        )

    def health(self) -> dict:
        loaded = {f"{source}-{target}": model.model_name for (source, target), model in self._models.items()}
        return {
            "device": "cuda" if torch.cuda.is_available() else "cpu",
            "configured_models": self.configured_models(),
            "loaded_models": loaded,
            "preload_models": settings.preload_models,
        }


model_manager = ModelManager()
