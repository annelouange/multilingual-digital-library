from __future__ import annotations

from dataclasses import dataclass
from typing import List

import torch

from app.chunker import TranslationChunker
from app.config import settings
from app.model_manager import LoadedTranslationModel, model_manager
from app.protection import protect_special_content, restore_special_content
from app.validator import TranslationValidator, ValidationReport


NLLB_LANGUAGE_MAP = {
    "en": "eng_Latn",
    "fr": "fra_Latn",
    "rw": "kin_Latn",
    "sw": "swh_Latn",
}


@dataclass
class TranslationOutput:
    original_text: str
    translated_text: str
    source_language: str
    target_language: str
    provider: str
    model: str
    validation: ValidationReport


class TranslationService:
    def __init__(self) -> None:
        self.validator = TranslationValidator()

    def count_tokens(self, text: str, source_language: str, target_language: str) -> int:
        loaded = self._model_for(source_language, target_language)
        return len(loaded.tokenizer.encode(text, add_special_tokens=True, truncation=False))

    def split_text(
        self,
        text: str,
        source_language: str,
        target_language: str,
        target_tokens: int | None = None,
        maximum_tokens: int | None = None,
    ) -> List[str]:
        loaded = self._model_for(source_language, target_language)
        chunker = TranslationChunker(
            loaded.tokenizer,
            target_tokens=target_tokens or settings.target_input_tokens,
            maximum_tokens=maximum_tokens or settings.maximum_input_tokens,
        )
        blocks = [block.strip() for block in text.splitlines() if block.strip()]
        if len(blocks) <= 1:
            blocks = [paragraph.strip() for paragraph in text.split("\n\n") if paragraph.strip()]
        return chunker.create_chunks(blocks or [text.strip()])

    def translate_chunk(
        self,
        text: str,
        source_language: str,
        target_language: str,
        beam_size: int = 4,
    ) -> TranslationOutput:
        loaded = self._model_for(source_language, target_language)
        translated_text = self._translate_with_loaded_model(
            loaded,
            text,
            source_language,
            target_language,
            beam_size,
        )
        report = self.validator.validate(text, translated_text, target_language)
        return TranslationOutput(
            original_text=text,
            translated_text=translated_text,
            source_language=source_language,
            target_language=target_language,
            provider=loaded.provider,
            model=loaded.model_name,
            validation=report,
        )

    def translate_with_retries(
        self,
        text: str,
        source_language: str,
        target_language: str,
        quality_mode: str = "balanced",
    ) -> TranslationOutput:
        attempts = self._attempts_for_quality(quality_mode)
        last_output: TranslationOutput | None = None
        last_error = ""

        for attempt in attempts:
            try:
                output = self.translate_chunk(
                    text,
                    source_language,
                    target_language,
                    beam_size=attempt["beam_size"],
                )
                last_output = output
                if output.validation.valid or attempt.get("accept_partial"):
                    return output
                last_error = "; ".join(output.validation.problems)
            except RuntimeError as error:
                last_error = str(error)
                if "out of memory" in last_error.lower() and torch.cuda.is_available():
                    torch.cuda.empty_cache()

        if last_output:
            return last_output
        raise RuntimeError(f"Translation failed after {len(attempts)} attempts: {last_error}")

    def translate_large_text(
        self,
        text: str,
        source_language: str,
        target_language: str,
        quality_mode: str = "balanced",
    ) -> List[TranslationOutput]:
        if quality_mode == "fast":
            chunks = self.split_text(text, source_language, target_language, 180, 240)
        elif quality_mode == "accurate":
            chunks = self.split_text(text, source_language, target_language, 220, 320)
        else:
            chunks = self.split_text(text, source_language, target_language, 240, 340)

        results: List[TranslationOutput] = []
        for chunk in chunks:
            results.append(self.translate_with_retries(chunk, source_language, target_language, quality_mode))
        return results

    def _translate_with_loaded_model(
        self,
        loaded: LoadedTranslationModel,
        text: str,
        source_language: str,
        target_language: str,
        beam_size: int,
    ) -> str:
        protected_text, placeholders = protect_special_content(text)
        tokenizer = loaded.tokenizer
        model = loaded.model

        if loaded.provider == "nllb":
            tokenizer.src_lang = NLLB_LANGUAGE_MAP[source_language]

        inputs = tokenizer(
            protected_text,
            return_tensors="pt",
            padding=True,
            truncation=True,
            max_length=settings.maximum_input_tokens,
        ).to(loaded.device)

        generate_kwargs = {
            "num_beams": beam_size,
            "max_new_tokens": settings.maximum_new_tokens,
            "early_stopping": True,
            "no_repeat_ngram_size": 3,
            "repetition_penalty": 1.1,
        }
        if loaded.provider == "nllb":
            generate_kwargs["forced_bos_token_id"] = tokenizer.convert_tokens_to_ids(NLLB_LANGUAGE_MAP[target_language])

        with torch.inference_mode():
            generated_tokens = model.generate(**inputs, **generate_kwargs)

        translated_text = tokenizer.batch_decode(generated_tokens, skip_special_tokens=True)[0]
        translated_text = self._repair_mojibake(translated_text)
        return restore_special_content(translated_text, placeholders)

    @staticmethod
    def _repair_mojibake(text: str) -> str:
        if "Ã" not in text and "Â" not in text:
            return text
        try:
            repaired = text.encode("latin1").decode("utf-8")
        except UnicodeError:
            return text
        return repaired if repaired.count("Ã") <= text.count("Ã") else text

    @staticmethod
    def _model_for(source_language: str, target_language: str) -> LoadedTranslationModel:
        try:
            return model_manager.get_model(source_language, target_language)
        except ValueError:
            if source_language in NLLB_LANGUAGE_MAP and target_language in NLLB_LANGUAGE_MAP:
                return model_manager.get_nllb_model()
            raise

    @staticmethod
    def _attempts_for_quality(quality_mode: str) -> list[dict]:
        if quality_mode == "fast":
            return [
                {"beam_size": 2},
                {"beam_size": 1, "accept_partial": True},
            ]
        if quality_mode == "accurate":
            return [
                {"beam_size": 5},
                {"beam_size": 4},
                {"beam_size": 3, "accept_partial": True},
            ]
        return [
            {"beam_size": 4},
            {"beam_size": 3},
            {"beam_size": 2, "accept_partial": True},
        ]


translation_service = TranslationService()
