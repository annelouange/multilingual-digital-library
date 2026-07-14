from __future__ import annotations

import re
from dataclasses import dataclass
from typing import List


@dataclass
class ValidationReport:
    valid: bool
    confidence: float
    problems: List[str]


class TranslationValidator:
    def validate(
        self,
        source_text: str,
        translated_text: str,
        target_language: str | None = None,
    ) -> ValidationReport:
        problems: List[str] = []
        source_clean = source_text.strip()
        target_clean = translated_text.strip()

        if not target_clean:
            problems.append("Translation output is empty.")
        if len(target_clean) < max(10, len(source_clean) * 0.20):
            problems.append("Translation is unexpectedly short.")
        if len(target_clean) > max(80, len(source_clean) * 4):
            problems.append("Translation is unexpectedly long.")
        if self._has_excessive_repetition(target_clean):
            problems.append("Translation contains excessive repetition.")

        missing_numbers = self._missing_numbers(source_clean, target_clean)
        if missing_numbers:
            problems.append(f"Numbers missing from translation: {missing_numbers}")
        if source_clean == target_clean and len(source_clean) > 20:
            problems.append("Output appears untranslated.")

        language_problem = self._language_problem(target_clean, target_language)
        if language_problem:
            problems.append(language_problem)

        confidence = max(0.0, 1.0 - (len(problems) * 0.2))
        return ValidationReport(valid=len(problems) == 0, confidence=confidence, problems=problems)

    @staticmethod
    def _missing_numbers(source: str, target: str) -> List[str]:
        source_numbers = set(re.findall(r"\d+(?:[.,]\d+)?", source))
        target_numbers = set(re.findall(r"\d+(?:[.,]\d+)?", target))
        return sorted(source_numbers - target_numbers)

    @staticmethod
    def _has_excessive_repetition(text: str) -> bool:
        words = text.lower().split()
        if len(words) < 20:
            return False
        repeated_triplets = 0
        for index in range(len(words) - 5):
            if words[index : index + 3] == words[index + 3 : index + 6]:
                repeated_triplets += 1
        return repeated_triplets >= 2

    @staticmethod
    def _language_problem(text: str, target_language: str | None) -> str:
        if not target_language or len(text) < 24:
            return ""
        try:
            from langdetect import detect

            detected = detect(text)
        except Exception:
            return ""
        if target_language in {"en", "fr"} and detected != target_language:
            return f"Detected language is {detected}, expected {target_language}."
        return ""
