from pathlib import Path
import os


BASE_DIR = Path(__file__).resolve().parent.parent


def _env(key: str, default: str) -> str:
    return os.getenv(f"AI_{key}", os.getenv(key, default))


def _env_int(key: str, default: int) -> int:
    try:
        return int(_env(key, str(default)))
    except ValueError:
        return default


def _env_bool(key: str, default: bool) -> bool:
    value = _env(key, "true" if default else "false").strip().lower()
    return value in {"1", "true", "yes", "on"}


class Settings:
    en_fr_model_path: str = _env("EN_FR_MODEL_PATH", "Helsinki-NLP/opus-mt-en-fr")
    fr_en_model_path: str = _env("FR_EN_MODEL_PATH", r"D:\mdl-translation-models\opus-fr-en\final")
    nllb_model_path: str = _env("NLLB_MODEL_PATH", "facebook/nllb-200-distilled-600M")
    preload_models: bool = _env_bool("PRELOAD_MODELS", False)
    maximum_text_characters: int = _env_int("MAXIMUM_TEXT_CHARACTERS", 100000)
    target_input_tokens: int = _env_int("TARGET_INPUT_TOKENS", 350)
    maximum_input_tokens: int = _env_int("MAXIMUM_INPUT_TOKENS", 450)
    maximum_new_tokens: int = _env_int("MAXIMUM_NEW_TOKENS", 768)
    default_beam_size: int = _env_int("DEFAULT_BEAM_SIZE", 4)
    request_timeout_seconds: int = _env_int("REQUEST_TIMEOUT_SECONDS", 120)


settings = Settings()
