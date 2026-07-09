import argparse
import json
from pathlib import Path

from gtts import gTTS


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate an MP3 narration with gTTS.")
    parser.add_argument("input_file")
    parser.add_argument("output_file")
    parser.add_argument("--lang", default="en")
    parser.add_argument("--slow", action="store_true")
    args = parser.parse_args()

    source = Path(args.input_file)
    target = Path(args.output_file)
    text = source.read_text(encoding="utf-8").strip()
    if not text:
        print(json.dumps({"success": False, "error": "Narration text is empty"}))
        return 2

    target.parent.mkdir(parents=True, exist_ok=True)
    try:
        gTTS(text=text, lang=args.lang, slow=args.slow).save(str(target))
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1

    print(json.dumps({
        "success": True,
        "output": str(target),
        "bytes": target.stat().st_size,
        "provider": "gTTS",
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
