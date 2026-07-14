"""Fine-tune OPUS translation models from a private CSV or Excel sheet.

Examples:
  accelerate launch scripts/train_opus_translation.py --direction fr-en --input data/my_translations.csv
  accelerate launch scripts/train_opus_translation.py --direction en-fr --input data/my_translations.xlsx
"""

from __future__ import annotations

import argparse
import json
import math
import os
import shutil
import inspect
from pathlib import Path
from typing import Any

import pandas as pd
import torch
from datasets import Dataset, DatasetDict
from transformers import (
    AutoModelForSeq2SeqLM,
    AutoTokenizer,
    DataCollatorForSeq2Seq,
    Seq2SeqTrainer,
    Seq2SeqTrainingArguments,
    set_seed,
)


DIRECTION_CONFIG = {
    "en-fr": {
        "model_checkpoint": "Helsinki-NLP/opus-mt-en-fr",
        "source_lang": "en",
        "target_lang": "fr",
        "source_column": "english_text",
        "target_column": "french_text",
    },
    "fr-en": {
        "model_checkpoint": "Helsinki-NLP/opus-mt-fr-en",
        "source_lang": "fr",
        "target_lang": "en",
        "source_column": "french_text",
        "target_column": "english_text",
    },
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fine-tune Helsinki OPUS translation models from CSV/XLSX data.")
    parser.add_argument("--input", required=True, help="Path to my_translations.csv or my_translations.xlsx.")
    parser.add_argument("--direction", choices=sorted(DIRECTION_CONFIG), default="fr-en")
    parser.add_argument("--output-dir", default="", help="Training output folder. Defaults to models/translation/opus-<direction>.")
    parser.add_argument("--cache-dir", default="models/translation/cache", help="Hugging Face cache folder for model/data artifacts.")
    parser.add_argument("--source-column", default="", help="Override source text column.")
    parser.add_argument("--target-column", default="", help="Override target text column.")
    parser.add_argument("--model-checkpoint", default="", help="Override base checkpoint.")
    parser.add_argument("--max-source-length", type=int, default=256)
    parser.add_argument("--max-target-length", type=int, default=256)
    parser.add_argument("--epochs", type=float, default=3)
    parser.add_argument("--learning-rate", type=float, default=2e-5)
    parser.add_argument("--train-batch-size", type=int, default=16)
    parser.add_argument("--eval-batch-size", type=int, default=16)
    parser.add_argument("--weight-decay", type=float, default=0.01)
    parser.add_argument("--save-total-limit", type=int, default=3)
    parser.add_argument("--logging-steps", type=int, default=50)
    parser.add_argument("--eval-ratio", type=float, default=0.1)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--max-rows", type=int, default=0, help="Optional cap for test runs. 0 uses all rows.")
    parser.add_argument("--min-free-gb", type=float, default=8, help="Abort if output drive has less free space.")
    parser.add_argument("--resume-from-checkpoint", default="", help="Checkpoint path or 'true'.")
    parser.add_argument("--dry-run", action="store_true", help="Load data/model tokenizer and print config without training.")
    return parser.parse_args()


def ensure_disk_space(path: Path, min_free_gb: float) -> None:
    path.mkdir(parents=True, exist_ok=True)
    usage = shutil.disk_usage(path)
    free_gb = usage.free / (1024 ** 3)
    if free_gb < min_free_gb:
        raise RuntimeError(
            f"Only {free_gb:.2f} GB free at {path}. "
            f"Need at least {min_free_gb:.2f} GB. Choose another --output-dir/--cache-dir or free disk space."
        )


def read_spreadsheet(path: Path) -> pd.DataFrame:
    if not path.exists():
        raise FileNotFoundError(f"Training file not found: {path}")
    suffix = path.suffix.lower()
    if suffix == ".csv":
        return pd.read_csv(path)
    if suffix in {".xlsx", ".xls"}:
        return pd.read_excel(path)
    raise ValueError("Input must be a .csv, .xlsx, or .xls file.")


def build_dataset(df: pd.DataFrame, source_column: str, target_column: str, source_lang: str, target_lang: str) -> DatasetDict:
    missing = [column for column in (source_column, target_column) if column not in df.columns]
    if missing:
        raise ValueError(f"Missing required column(s): {', '.join(missing)}. Available columns: {', '.join(df.columns)}")

    clean = df[[source_column, target_column]].dropna().copy()
    clean[source_column] = clean[source_column].astype(str).str.strip()
    clean[target_column] = clean[target_column].astype(str).str.strip()
    clean = clean[(clean[source_column] != "") & (clean[target_column] != "")]
    if len(clean) < 2:
        raise ValueError("Need at least two non-empty translation rows.")

    formatted_data = [
        {"translation": {source_lang: row[source_column], target_lang: row[target_column]}}
        for _, row in clean.iterrows()
    ]
    full_dataset = Dataset.from_list(formatted_data)
    test_size = 1 if len(full_dataset) < 10 else None
    if test_size is not None:
        return full_dataset.train_test_split(test_size=test_size, seed=42)
    return full_dataset.train_test_split(test_size=0.1, seed=42)


def simple_exact_match(eval_preds: Any, tokenizer: Any) -> dict[str, float]:
    predictions, labels = eval_preds
    if isinstance(predictions, tuple):
        predictions = predictions[0]
    labels = labels.copy()
    labels[labels == -100] = tokenizer.pad_token_id
    decoded_preds = tokenizer.batch_decode(predictions, skip_special_tokens=True)
    decoded_labels = tokenizer.batch_decode(labels, skip_special_tokens=True)
    total = max(1, len(decoded_labels))
    exact = sum(
        pred.strip().casefold() == label.strip().casefold()
        for pred, label in zip(decoded_preds, decoded_labels)
    )
    return {"exact_match": exact / total}


def main() -> None:
    args = parse_args()
    config = DIRECTION_CONFIG[args.direction]
    source_lang = config["source_lang"]
    target_lang = config["target_lang"]
    source_column = args.source_column or config["source_column"]
    target_column = args.target_column or config["target_column"]
    model_checkpoint = args.model_checkpoint or config["model_checkpoint"]
    output_dir = Path(args.output_dir or f"models/translation/opus-{args.direction}").resolve()
    cache_dir = Path(args.cache_dir).resolve()

    ensure_disk_space(output_dir, args.min_free_gb)
    ensure_disk_space(cache_dir, max(2.0, min(args.min_free_gb, 4.0)))
    os.environ.setdefault("HF_HOME", str(cache_dir))
    os.environ.setdefault("TRANSFORMERS_CACHE", str(cache_dir / "transformers"))
    os.environ.setdefault("HF_DATASETS_CACHE", str(cache_dir / "datasets"))
    set_seed(args.seed)

    df = read_spreadsheet(Path(args.input))
    if args.max_rows and args.max_rows > 0:
        df = df.head(args.max_rows)
    raw_datasets = build_dataset(df, source_column, target_column, source_lang, target_lang)

    tokenizer = AutoTokenizer.from_pretrained(model_checkpoint, cache_dir=str(cache_dir))
    model = AutoModelForSeq2SeqLM.from_pretrained(model_checkpoint, cache_dir=str(cache_dir))

    def preprocess(batch: dict[str, list[dict[str, str]]]) -> dict[str, Any]:
        sources = [item[source_lang] for item in batch["translation"]]
        targets = [item[target_lang] for item in batch["translation"]]
        model_inputs = tokenizer(sources, max_length=args.max_source_length, truncation=True)
        labels = tokenizer(text_target=targets, max_length=args.max_target_length, truncation=True)
        model_inputs["labels"] = labels["input_ids"]
        return model_inputs

    tokenized = raw_datasets.map(
        preprocess,
        batched=True,
        remove_columns=raw_datasets["train"].column_names,
        desc="Tokenizing translation pairs",
    )

    summary = {
        "direction": args.direction,
        "model_checkpoint": model_checkpoint,
        "source_language": source_lang,
        "target_language": target_lang,
        "source_column": source_column,
        "target_column": target_column,
        "train_rows": len(tokenized["train"]),
        "eval_rows": len(tokenized["test"]),
        "output_dir": str(output_dir),
        "cache_dir": str(cache_dir),
        "cuda": torch.cuda.is_available(),
        "gpu_count": torch.cuda.device_count(),
    }
    print(json.dumps(summary, indent=2))
    if args.dry_run:
        return

    training_kwargs = dict(
        output_dir=str(output_dir),
        save_strategy="epoch",
        learning_rate=args.learning_rate,
        per_device_train_batch_size=args.train_batch_size,
        per_device_eval_batch_size=args.eval_batch_size,
        weight_decay=args.weight_decay,
        save_total_limit=args.save_total_limit,
        num_train_epochs=args.epochs,
        predict_with_generate=True,
        fp16=torch.cuda.is_available(),
        logging_steps=args.logging_steps,
        ddp_find_unused_parameters=False,
        report_to="none",
        load_best_model_at_end=False,
    )
    signature = inspect.signature(Seq2SeqTrainingArguments.__init__).parameters
    if "evaluation_strategy" in signature:
        training_kwargs["evaluation_strategy"] = "epoch"
    else:
        training_kwargs["eval_strategy"] = "epoch"
    training_args = Seq2SeqTrainingArguments(**training_kwargs)

    data_collator = DataCollatorForSeq2Seq(tokenizer=tokenizer, model=model)
    trainer_kwargs = dict(
        model=model,
        args=training_args,
        train_dataset=tokenized["train"],
        eval_dataset=tokenized["test"],
        data_collator=data_collator,
        compute_metrics=lambda preds: simple_exact_match(preds, tokenizer),
    )
    trainer_signature = inspect.signature(Seq2SeqTrainer.__init__).parameters
    if "tokenizer" in trainer_signature:
        trainer_kwargs["tokenizer"] = tokenizer
    else:
        trainer_kwargs["processing_class"] = tokenizer
    trainer = Seq2SeqTrainer(**trainer_kwargs)

    resume: bool | str = False
    if args.resume_from_checkpoint:
        resume = True if args.resume_from_checkpoint.lower() == "true" else args.resume_from_checkpoint

    trainer.train(resume_from_checkpoint=resume)
    trainer.save_model(str(output_dir / "final"))
    tokenizer.save_pretrained(str(output_dir / "final"))
    metrics = trainer.evaluate()
    (output_dir / "training_summary.json").write_text(json.dumps({**summary, "metrics": metrics}, indent=2), encoding="utf-8")


if __name__ == "__main__":
    main()
