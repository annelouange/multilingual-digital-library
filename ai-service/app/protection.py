from __future__ import annotations

import re
from typing import Dict, Tuple


PROTECTED_PATTERNS = [
    r"```[\s\S]*?```",
    r"</?[\w:-]+(?:\s+[^<>]*)?>",
    r"https?://\S+",
    r"\b[\w.+-]+@[\w.-]+\.\w+\b",
    r"\bISBN(?:-1[03])?:?\s*[0-9Xx-]+\b",
]


def protect_special_content(text: str) -> Tuple[str, Dict[str, str]]:
    placeholders: Dict[str, str] = {}
    protected_text = text
    index = 0

    for pattern in PROTECTED_PATTERNS:
        while True:
            match = re.search(pattern, protected_text, flags=re.IGNORECASE)
            if not match:
                break

            placeholder = f"ZXQPLACEHOLDERA{index}ZXQ"
            placeholders[placeholder] = match.group(0)
            protected_text = (
                protected_text[: match.start()]
                + placeholder
                + protected_text[match.end() :]
            )
            index += 1

    return protected_text, placeholders


def restore_special_content(translated_text: str, placeholders: Dict[str, str]) -> str:
    result = translated_text
    for index, (placeholder, original_value) in enumerate(placeholders.items()):
        result = result.replace(placeholder, original_value)
        result = result.replace(placeholder.lower(), original_value)
        result = re.sub(re.escape(placeholder).replace("ZXQ", r"Z\s*X\s*Q"), original_value, result, flags=re.IGNORECASE)
        result = re.sub(rf"Z\s*X\s*Q?\s*PLACEHOLDERA\s*{index}\s*Z\s*X\s*Q?", original_value, result, flags=re.IGNORECASE)
    return result


def create_checksum(text: str) -> str:
    import hashlib

    return hashlib.sha256(text.encode("utf-8")).hexdigest()
