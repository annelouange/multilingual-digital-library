#!/usr/bin/env python3
"""Real LibriSpeech fine-tuning for the Rwanda Library Transformer STT service.

This script intentionally trains only on real manifest audio/transcript pairs.
It does not create synthetic noise data and it does not use LSTM/RNN layers.

Default CPU behavior is conservative:
- load facebook/wav2vec2-base-960h pretrained Transformer CTC weights;
- freeze the Wav2Vec2 encoder and train the CTC projection head;
- save a candidate checkpoint;
- evaluate on untouched LibriSpeech dev/test rows;
- promote to transformer_model/ only when the candidate passes the threshold.

The running STT service keeps using the already-loaded model until it is
restarted, so this script can run while the project stays online.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import random
import shutil
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

import librosa
import numpy as np
import torch
from sklearn.metrics import roc_auc_score
from torch.utils.data import DataLoader, Dataset
from transformers import Wav2Vec2ForCTC, Wav2Vec2Processor


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE_MODEL = "facebook/wav2vec2-base-960h"
DEFAULT_TRAIN_MANIFEST = ROOT / "speech_datasets" / "manifests" / "train.jsonl"
DEFAULT_DEV_MANIFEST = ROOT / "speech_datasets" / "manifests" / "dev.jsonl"
DEFAULT_TEST_MANIFEST = ROOT / "speech_datasets" / "manifests" / "test.jsonl"
DEFAULT_OUTPUT_DIR = ROOT / "transformer_model_candidate"
DEFAULT_PROMOTE_DIR = ROOT / "transformer_model"
METRICS_PATH = ROOT / "models" / "stt" / "transformer_metrics.json"
COMMON_VOICE_MANIFEST = ROOT / "speech_datasets" / "manifests" / "common_voice_en_train.jsonl"


@dataclass
class Sample:
    audio_filepath: str
    text: str
    dataset: str = "LibriSpeech"
    source_split: str = ""


def normalize_text(text: str) -> str:
    return " ".join(str(text or "").upper().strip().split())


def display_path(path: str | Path) -> str:
    resolved = Path(path).resolve()
    try:
        return str(resolved.relative_to(ROOT)).replace("\\", "/")
    except ValueError:
        return str(resolved)


def load_manifest(path: Path, limit: int, seed: int) -> list[Sample]:
    rows: list[Sample] = []
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            raw = json.loads(line)
            audio_path = Path(raw["audio_filepath"])
            text = normalize_text(raw["text"])
            if audio_path.is_file() and text:
                rows.append(
                    Sample(
                        audio_filepath=str(audio_path),
                        text=text,
                        dataset=raw.get("dataset", "LibriSpeech"),
                        source_split=raw.get("source_split", ""),
                    )
                )
    random.Random(seed).shuffle(rows)
    return rows[:limit] if limit > 0 else rows


def load_manifests(paths: list[str], limit: int, seed: int) -> list[Sample]:
    loaded: list[Sample] = []
    for path in paths:
        manifest_path = Path(path)
        if manifest_path.exists():
            loaded.extend(load_manifest(manifest_path, 0, seed + len(loaded)))
    random.Random(seed).shuffle(loaded)
    return loaded[:limit] if limit > 0 else loaded


def prepare_common_voice_manifest(limit: int, seed: int) -> Path | None:
    """Create a Mozilla Common Voice English manifest when HF access is available.

    Common Voice datasets can require an accepted Hugging Face dataset license.
    If the download is blocked, the function returns None and training continues
    with local LibriSpeech manifests.
    """
    if COMMON_VOICE_MANIFEST.exists():
        return COMMON_VOICE_MANIFEST
    try:
        from datasets import Audio, load_dataset
    except Exception as error:
        print(json.dumps({"event": "common_voice_unavailable", "error": str(error)}), flush=True)
        return None

    dataset = None
    errors = []
    for dataset_name in (
        "mozilla-foundation/common_voice_17_0",
        "mozilla-foundation/common_voice_16_1",
        "mozilla-foundation/common_voice_15_0",
        "mozilla-foundation/common_voice_13_0",
        "mozilla-foundation/common_voice_11_0",
    ):
        try:
            dataset = load_dataset(dataset_name, "en", split=f"train[:{max(1, limit)}]")
            dataset = dataset.cast_column("audio", Audio(sampling_rate=16000))
            print(json.dumps({"event": "common_voice_loaded", "dataset": dataset_name}, ensure_ascii=False), flush=True)
            break
        except Exception as error:
            errors.append({"dataset": dataset_name, "error": str(error)})
    if dataset is None:
        print(json.dumps({"event": "common_voice_unavailable", "errors": errors}, ensure_ascii=False), flush=True)
        return None

    audio_dir = ROOT / "speech_datasets" / "CommonVoice" / "en"
    audio_dir.mkdir(parents=True, exist_ok=True)
    COMMON_VOICE_MANIFEST.parent.mkdir(parents=True, exist_ok=True)
    rows = []
    for index, sample in enumerate(dataset):
        sentence = normalize_text(sample.get("sentence", ""))
        audio = sample.get("audio") or {}
        array = audio.get("array")
        if not sentence or array is None:
            continue
        target = audio_dir / f"common_voice_en_{index:05d}.wav"
        import soundfile as sf

        sf.write(str(target), np.asarray(array, dtype=np.float32), 16000)
        rows.append(
            {
                "audio_filepath": str(target),
                "text": sentence,
                "dataset": "Mozilla Common Voice",
                "source_split": "train",
            }
        )

    with COMMON_VOICE_MANIFEST.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")
    print(json.dumps({"event": "common_voice_manifest_created", "samples": len(rows)}), flush=True)
    return COMMON_VOICE_MANIFEST if rows else None


class ManifestSpeechDataset(Dataset):
    def __init__(self, samples: list[Sample], processor: Wav2Vec2Processor, max_audio_seconds: float):
        self.samples = samples
        self.processor = processor
        self.max_audio_seconds = max_audio_seconds

    def __len__(self) -> int:
        return len(self.samples)

    def __getitem__(self, index: int) -> dict:
        sample = self.samples[index]
        audio, _ = librosa.load(sample.audio_filepath, sr=16000, mono=True)
        max_samples = int(16000 * self.max_audio_seconds)
        if max_samples > 0 and len(audio) > max_samples:
            audio = audio[:max_samples]

        inputs = self.processor(audio, sampling_rate=16000)
        labels = self.processor.tokenizer(sample.text).input_ids
        return {
            "input_values": inputs.input_values[0],
            "labels": labels,
            "text": sample.text,
            "audio_filepath": sample.audio_filepath,
        }


class DataCollatorCTC:
    def __init__(self, processor: Wav2Vec2Processor):
        self.processor = processor

    def __call__(self, features: list[dict]) -> dict:
        input_features = [{"input_values": feature["input_values"]} for feature in features]
        label_features = [{"input_ids": feature["labels"]} for feature in features]
        batch = self.processor.pad(input_features, padding=True, return_tensors="pt")
        labels_batch = self.processor.pad(labels=label_features, padding=True, return_tensors="pt")
        labels = labels_batch["input_ids"].masked_fill(labels_batch.attention_mask.ne(1), -100)
        batch["labels"] = labels
        batch["texts"] = [feature["text"] for feature in features]
        batch["audio_filepaths"] = [feature["audio_filepath"] for feature in features]
        return batch


def edit_counts(reference: list[str], hypothesis: list[str]) -> dict[str, int]:
    previous = [(j, 0, j, 0) for j in range(len(hypothesis) + 1)]
    for i, ref_item in enumerate(reference, start=1):
        current = [(i, i, 0, 0)]
        for j, hyp_item in enumerate(hypothesis, start=1):
            if ref_item == hyp_item:
                cost, deletions, insertions, substitutions = previous[j - 1]
                current.append((cost, deletions, insertions, substitutions))
            else:
                delete = (previous[j][0] + 1, previous[j][1] + 1, previous[j][2], previous[j][3])
                insert = (current[j - 1][0] + 1, current[j - 1][1], current[j - 1][2] + 1, current[j - 1][3])
                substitute = (
                    previous[j - 1][0] + 1,
                    previous[j - 1][1],
                    previous[j - 1][2],
                    previous[j - 1][3] + 1,
                )
                current.append(min(delete, insert, substitute, key=lambda item: item[0]))
        previous = current
    cost, deletions, insertions, substitutions = previous[-1]
    return {
        "distance": cost,
        "deletions": deletions,
        "insertions": insertions,
        "substitutions": substitutions,
    }


def evaluate_samples(
    model: Wav2Vec2ForCTC,
    processor: Wav2Vec2Processor,
    samples: list[Sample],
    max_audio_seconds: float,
    device: torch.device,
) -> dict:
    model.eval()
    word_errors = 0
    word_total = 0
    char_errors = 0
    char_total = 0
    word_insertions = 0
    word_deletions = 0
    word_substitutions = 0
    exact = 0
    auc_true = []
    auc_scores = []
    examples = []
    started = time.time()

    with torch.inference_mode():
        for sample in samples:
            audio, _ = librosa.load(sample.audio_filepath, sr=16000, mono=True)
            max_samples = int(16000 * max_audio_seconds)
            if max_samples > 0 and len(audio) > max_samples:
                audio = audio[:max_samples]
            inputs = processor(audio, sampling_rate=16000, return_tensors="pt")
            input_values = inputs.input_values.to(device)
            logits = model(input_values).logits
            probabilities = torch.softmax(logits, dim=-1)
            predicted_ids = torch.argmax(logits, dim=-1)
            prediction = normalize_text(processor.batch_decode(predicted_ids)[0])
            reference = normalize_text(sample.text)

            ref_words = reference.split()
            hyp_words = prediction.split()
            word_counts = edit_counts(ref_words, hyp_words)
            char_counts = edit_counts(list(reference), list(prediction))
            word_errors += word_counts["distance"]
            word_insertions += word_counts["insertions"]
            word_deletions += word_counts["deletions"]
            word_substitutions += word_counts["substitutions"]
            word_total += len(ref_words)
            char_errors += char_counts["distance"]
            char_total += len(reference)
            exact += int(reference == prediction)

            reference_ids = set(processor.tokenizer(reference).input_ids)
            token_scores = probabilities.max(dim=1).values.squeeze(0).detach().cpu().numpy()
            vocab_size = len(token_scores)
            for token_id in range(vocab_size):
                if token_id == processor.tokenizer.pad_token_id:
                    continue
                auc_true.append(1 if token_id in reference_ids else 0)
                auc_scores.append(float(token_scores[token_id]))

            if len(examples) < 8:
                examples.append(
                    {
                        "reference": reference,
                        "prediction": prediction,
                        "source_split": sample.source_split,
                    }
                )

    wer = word_errors / max(1, word_total)
    cer = char_errors / max(1, char_total)
    sentence_accuracy = exact / max(1, len(samples))
    true_positives = max(0, word_total - word_deletions - word_substitutions)
    false_positives = word_insertions + word_substitutions
    false_negatives = word_deletions + word_substitutions
    precision = true_positives / max(1, true_positives + false_positives)
    recall = true_positives / max(1, true_positives + false_negatives)
    f1 = 2 * precision * recall / max(1e-12, precision + recall)
    roc_auc = None
    roc_auc_note = "Token-presence ROC-AUC over the CTC vocabulary; primary STT quality is still WER/CER/F1."
    if len(set(auc_true)) == 2:
        roc_auc = float(roc_auc_score(auc_true, auc_scores))
    return {
        "samples": len(samples),
        "wer": wer,
        "cer": cer,
        "word_accuracy_percent": max(0.0, 100.0 * (1.0 - wer)),
        "sentence_exact_accuracy_percent": 100.0 * sentence_accuracy,
        "word_precision_percent": 100.0 * precision,
        "word_recall_percent": 100.0 * recall,
        "word_f1_percent": 100.0 * f1,
        "word_error_counts": {
            "insertions": word_insertions,
            "deletions": word_deletions,
            "substitutions": word_substitutions,
        },
        "roc_auc": roc_auc,
        "roc_auc_note": roc_auc_note,
        "elapsed_seconds": round(time.time() - started, 2),
        "examples": examples,
    }


def set_training_mode(model: Wav2Vec2ForCTC, mode: str) -> None:
    if mode == "full":
        for parameter in model.parameters():
            parameter.requires_grad = True
        return
    for parameter in model.parameters():
        parameter.requires_grad = False
    for parameter in model.lm_head.parameters():
        parameter.requires_grad = True


def train(args: argparse.Namespace) -> dict:
    started = time.time()
    random.seed(args.seed)
    torch.manual_seed(args.seed)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    train_manifests = [args.train_manifest]
    if args.include_common_voice:
        common_voice_manifest = prepare_common_voice_manifest(args.common_voice_samples, args.seed)
        if common_voice_manifest is not None:
            train_manifests.append(str(common_voice_manifest))

    train_samples = load_manifests(train_manifests, args.max_train_samples, args.seed)
    dev_samples = load_manifest(Path(args.dev_manifest), args.max_eval_samples, args.seed + 1)
    test_samples = load_manifest(Path(args.test_manifest), args.max_test_samples, args.seed + 2)
    if not train_samples:
        raise RuntimeError("No train samples were loaded from the manifest")
    if not test_samples:
        raise RuntimeError("No test samples were loaded from the manifest")

    processor = Wav2Vec2Processor.from_pretrained(args.base_model)
    model = Wav2Vec2ForCTC.from_pretrained(
        args.base_model,
        ctc_loss_reduction="mean",
        ctc_zero_infinity=True,
        pad_token_id=processor.tokenizer.pad_token_id,
    ).to(device)
    set_training_mode(model, args.train_mode)

    trainable = sum(parameter.numel() for parameter in model.parameters() if parameter.requires_grad)
    total = sum(parameter.numel() for parameter in model.parameters())

    collator = DataCollatorCTC(processor)
    train_dataset = ManifestSpeechDataset(train_samples, processor, args.max_audio_seconds)
    loader = DataLoader(train_dataset, batch_size=args.batch_size, shuffle=True, collate_fn=collator)
    optimizer = torch.optim.AdamW((p for p in model.parameters() if p.requires_grad), lr=args.learning_rate)

    model.train()
    losses = []
    deadline = started + (args.time_budget_minutes * 60)
    step = 0
    epochs_started = 0
    epochs_completed = 0
    training_examples_processed = 0
    target_steps = args.max_steps
    if args.epochs is not None:
        target_steps = max(1, math.ceil(len(loader) * args.epochs))

    while step < target_steps and time.time() < deadline:
        epochs_started += 1
        batches_processed_this_epoch = 0
        for batch in loader:
            if step >= target_steps or time.time() >= deadline:
                break
            optimizer.zero_grad(set_to_none=True)
            outputs = model(
                input_values=batch["input_values"].to(device),
                attention_mask=batch.get("attention_mask", None).to(device)
                if batch.get("attention_mask", None) is not None
                else None,
                labels=batch["labels"].to(device),
            )
            loss = outputs.loss
            if not torch.isfinite(loss):
                raise RuntimeError(f"Non-finite loss at step {step + 1}: {loss.item()}")
            loss.backward()
            optimizer.step()
            step += 1
            batches_processed_this_epoch += 1
            training_examples_processed += int(batch["input_values"].shape[0])
            losses.append(float(loss.detach().cpu()))
            print(json.dumps({"event": "train_step", "step": step, "loss": losses[-1]}, ensure_ascii=False), flush=True)
        if batches_processed_this_epoch == len(loader):
            epochs_completed += 1

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    model.save_pretrained(output_dir)
    processor.save_pretrained(output_dir)

    dev_result = evaluate_samples(model, processor, dev_samples, args.max_audio_seconds, device)
    test_result = evaluate_samples(model, processor, test_samples, args.max_audio_seconds, device)
    aggregate_word_accuracy = (dev_result["word_accuracy_percent"] + test_result["word_accuracy_percent"]) / 2
    aggregate_sentence_accuracy = (
        dev_result["sentence_exact_accuracy_percent"] + test_result["sentence_exact_accuracy_percent"]
    ) / 2
    aggregate_word_f1 = (dev_result["word_f1_percent"] + test_result["word_f1_percent"]) / 2
    aggregate_roc_auc = None
    if dev_result.get("roc_auc") is not None and test_result.get("roc_auc") is not None:
        aggregate_roc_auc = (float(dev_result["roc_auc"]) + float(test_result["roc_auc"])) / 2

    promoted = False
    if args.promote and aggregate_word_accuracy >= args.promote_min_word_accuracy:
        promote_dir = Path(args.promote_dir)
        if promote_dir.exists():
            backup = promote_dir.with_name(f"{promote_dir.name}_backup_{int(time.time())}")
            shutil.move(str(promote_dir), str(backup))
        shutil.copytree(output_dir, promote_dir)
        promoted = True

    result = {
        "model_path": display_path(args.promote_dir if promoted else args.output_dir),
        "candidate_path": display_path(output_dir),
        "base_model": args.base_model,
        "model_type": "Wav2Vec2 Transformer encoder with CTC head",
        "dataset": "LibriSpeech real local manifests",
        "train_manifest": [display_path(path) for path in train_manifests],
        "dev_manifest": display_path(args.dev_manifest),
        "test_manifest": display_path(args.test_manifest),
        "epochs_started": epochs_started,
        "epochs_completed": epochs_completed,
        "effective_epochs": training_examples_processed / len(train_samples),
        "optimizer_steps": step,
        "training_examples_processed": training_examples_processed,
        "learning_rate": args.learning_rate,
        "batch_size": args.batch_size,
        "requested_epochs": args.epochs,
        "max_steps": target_steps,
        "time_budget_minutes": args.time_budget_minutes,
        "train_mode": args.train_mode,
        "trainable_parameters": trainable,
        "total_parameters": total,
        "train_samples": len(train_samples),
        "dev": dev_result,
        "test": test_result,
        "overall_word_accuracy_percent": aggregate_word_accuracy,
        "overall_sentence_exact_accuracy_percent": aggregate_sentence_accuracy,
        "overall_word_f1_percent": aggregate_word_f1,
        "overall_token_presence_roc_auc": aggregate_roc_auc,
        "overall_wer_percent": 100.0 - aggregate_word_accuracy,
        "losses": losses,
        "elapsed_minutes": round((time.time() - started) / 60, 2),
        "production_ready": aggregate_word_accuracy >= args.promote_min_word_accuracy,
        "promoted_to_transformer_model": promoted,
        "warning": "CPU bounded fine-tuning run; full LibriSpeech fine-tuning requires substantially more time or a GPU.",
    }

    metrics_target = Path(args.metrics_path)
    if args.preserve_metrics_unless_promoted and not promoted and metrics_target.exists():
        metrics_target = metrics_target.with_name(f"{metrics_target.stem}_candidate{metrics_target.suffix}")
        result["metrics_preserved"] = True
        result["preserved_metrics_path"] = display_path(args.metrics_path)
        result["candidate_metrics_path"] = display_path(metrics_target)
    else:
        result["metrics_preserved"] = False
    metrics_target.parent.mkdir(parents=True, exist_ok=True)
    metrics_target.write_text(json.dumps(result, indent=2), encoding="utf-8")
    print(json.dumps({"event": "complete", **result}, ensure_ascii=False, indent=2), flush=True)
    return result


def parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fine-tune Wav2Vec2 Transformer CTC on real LibriSpeech manifests")
    parser.add_argument("--base-model", default=DEFAULT_BASE_MODEL)
    parser.add_argument("--train-manifest", default=str(DEFAULT_TRAIN_MANIFEST))
    parser.add_argument("--dev-manifest", default=str(DEFAULT_DEV_MANIFEST))
    parser.add_argument("--test-manifest", default=str(DEFAULT_TEST_MANIFEST))
    parser.add_argument("--output-dir", default=str(DEFAULT_OUTPUT_DIR))
    parser.add_argument("--promote-dir", default=str(DEFAULT_PROMOTE_DIR))
    parser.add_argument("--metrics-path", default=str(METRICS_PATH))
    parser.add_argument("--max-train-samples", type=int, default=64)
    parser.add_argument("--max-eval-samples", type=int, default=12)
    parser.add_argument("--max-test-samples", type=int, default=20)
    parser.add_argument("--max-audio-seconds", type=float, default=8.0)
    parser.add_argument("--max-steps", type=int, default=12)
    parser.add_argument("--epochs", type=float, default=None)
    parser.add_argument("--batch-size", type=int, default=1)
    parser.add_argument("--learning-rate", type=float, default=1e-5)
    parser.add_argument("--time-budget-minutes", type=float, default=55.0)
    parser.add_argument("--train-mode", choices=["head", "full"], default="head")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--promote", action="store_true")
    parser.add_argument("--promote-min-word-accuracy", type=float, default=75.0)
    parser.add_argument("--preserve-metrics-unless-promoted", action="store_true")
    parser.add_argument("--include-common-voice", action="store_true")
    parser.add_argument("--common-voice-samples", type=int, default=80)
    return parser.parse_args(argv)


if __name__ == "__main__":
    train(parse_args())
