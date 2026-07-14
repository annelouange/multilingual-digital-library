from __future__ import annotations

import re
from typing import List

from transformers import PreTrainedTokenizerBase


class TranslationChunker:
    def __init__(
        self,
        tokenizer: PreTrainedTokenizerBase,
        target_tokens: int = 350,
        maximum_tokens: int = 450,
    ) -> None:
        self.tokenizer = tokenizer
        self.target_tokens = target_tokens
        self.maximum_tokens = maximum_tokens

    def count_tokens(self, text: str) -> int:
        return len(
            self.tokenizer.encode(
                text,
                add_special_tokens=True,
                truncation=False,
            )
        )

    def create_chunks(self, paragraphs: List[str]) -> List[str]:
        chunks: List[str] = []
        current_parts: List[str] = []
        current_tokens = 0

        for paragraph in paragraphs:
            paragraph = paragraph.strip()
            if not paragraph:
                continue

            paragraph_tokens = self.count_tokens(paragraph)
            if paragraph_tokens > self.maximum_tokens:
                if current_parts:
                    chunks.append("\n\n".join(current_parts))
                    current_parts = []
                    current_tokens = 0
                chunks.extend(self._split_oversized_paragraph(paragraph))
                continue

            if current_parts and current_tokens + paragraph_tokens > self.target_tokens:
                chunks.append("\n\n".join(current_parts))
                current_parts = []
                current_tokens = 0

            current_parts.append(paragraph)
            current_tokens += paragraph_tokens

        if current_parts:
            chunks.append("\n\n".join(current_parts))

        return chunks

    def _split_oversized_paragraph(self, paragraph: str) -> List[str]:
        sentences = self._basic_sentence_split(paragraph)
        chunks: List[str] = []
        current: List[str] = []

        for sentence in sentences:
            candidate = " ".join(current + [sentence])
            if current and self.count_tokens(candidate) > self.target_tokens:
                chunks.append(" ".join(current))
                current = [sentence]
            else:
                current.append(sentence)

        if current:
            chunks.append(" ".join(current))

        return chunks

    @staticmethod
    def _basic_sentence_split(text: str) -> List[str]:
        return [part.strip() for part in re.split(r"(?<=[.!?])\s+", text) if part.strip()]
