#!/usr/bin/env python3
"""Convert DOCX files to PDF and extract readable text from library documents."""

from __future__ import annotations

import argparse
import html
import json
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree


WORD_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS = {"w": WORD_NS}

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8")


def docx_blocks(path: Path) -> list[dict[str, str]]:
    with zipfile.ZipFile(path) as archive:
        document = ElementTree.fromstring(archive.read("word/document.xml"))

    body = document.find("w:body", NS)
    if body is None:
        return []

    blocks: list[dict[str, str]] = []
    for element in body:
        element_type = element.tag.rsplit("}", 1)[-1]
        if element_type == "p":
            text = "".join(node.text or "" for node in element.findall(".//w:t", NS)).strip()
            if not text:
                continue
            style_node = element.find("./w:pPr/w:pStyle", NS)
            style = style_node.get(f"{{{WORD_NS}}}val", "") if style_node is not None else ""
            blocks.append({"type": "heading" if style.lower().startswith("heading") else "paragraph", "text": text})
        elif element_type == "tbl":
            for row in element.findall("./w:tr", NS):
                cells = []
                for cell in row.findall("./w:tc", NS):
                    cell_text = " ".join(
                        "".join(node.text or "" for node in paragraph.findall(".//w:t", NS)).strip()
                        for paragraph in cell.findall(".//w:p", NS)
                    ).strip()
                    cells.append(cell_text)
                text = " | ".join(cell for cell in cells if cell)
                if text:
                    blocks.append({"type": "paragraph", "text": text})
    return blocks


def extract_text(path: Path) -> str:
    extension = path.suffix.lower()
    if extension == ".txt":
        return path.read_text(encoding="utf-8", errors="replace")
    if extension == ".docx":
        return "\n\n".join(block["text"] for block in docx_blocks(path))
    if extension == ".pdf":
        from PyPDF2 import PdfReader

        reader = PdfReader(str(path))
        return "\n\n".join((page.extract_text() or "").strip() for page in reader.pages).strip()
    raise ValueError(f"Text extraction is not supported for {extension or 'this file type'}")


def extract_page_text(path: Path, page_number: int) -> dict[str, object]:
    extension = path.suffix.lower()
    if page_number < 1:
        raise ValueError("Page number must be 1 or higher")

    if extension == ".pdf":
        import fitz

        document = fitz.open(path)
        try:
            total_pages = document.page_count
            if total_pages < 1:
                raise ValueError("The PDF does not contain pages")
            page_number = min(page_number, total_pages)
            page = document.load_page(page_number - 1)
            text = page.get_text("text").strip()
            has_visual_content = bool(text or page.get_images(full=True) or page.get_drawings())
            return {
                "text": text,
                "page": page_number,
                "total_pages": total_pages,
                "is_blank": not has_visual_content,
            }
        finally:
            document.close()

    if extension in {".txt", ".docx"}:
        text = extract_text(path)
        chunk_size = 2500
        pages = [text[index : index + chunk_size] for index in range(0, len(text), chunk_size)] or [""]
        total_pages = len(pages)
        page_number = min(page_number, total_pages)
        text = pages[page_number - 1].strip()
        return {"text": text, "page": page_number, "total_pages": total_pages, "is_blank": text == ""}

    raise ValueError(f"Page extraction is not supported for {extension or 'this file type'}")


def convert_docx_to_pdf(source: Path, target: Path) -> None:
    from reportlab.lib.enums import TA_CENTER
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import mm
    from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer

    blocks = docx_blocks(source)
    if not blocks:
        raise ValueError("The Word document does not contain readable text")

    target.parent.mkdir(parents=True, exist_ok=True)
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        "DocumentTitle",
        parent=styles["Heading1"],
        alignment=TA_CENTER,
        fontSize=18,
        leading=22,
        spaceAfter=10,
    )
    heading_style = ParagraphStyle(
        "DocumentHeading",
        parent=styles["Heading2"],
        fontSize=14,
        leading=18,
        spaceBefore=8,
        spaceAfter=5,
    )
    body_style = ParagraphStyle(
        "DocumentBody",
        parent=styles["BodyText"],
        fontSize=11,
        leading=16,
        spaceAfter=7,
    )

    story = []
    first_heading = True
    for block in blocks:
        text = html.escape(block["text"]).replace("\n", "<br/>")
        if block["type"] == "heading":
            story.append(Paragraph(text, title_style if first_heading else heading_style))
            first_heading = False
        else:
            story.append(Paragraph(text, body_style))
        story.append(Spacer(1, 2 * mm))

    document = SimpleDocTemplate(
        str(target),
        pagesize=A4,
        rightMargin=20 * mm,
        leftMargin=20 * mm,
        topMargin=18 * mm,
        bottomMargin=18 * mm,
        title=source.stem,
        author="MULTILINGUAL DIGITAL LIBRARY",
    )
    document.build(story)


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    convert_parser = subparsers.add_parser("convert")
    convert_parser.add_argument("source")
    convert_parser.add_argument("target")

    extract_parser = subparsers.add_parser("extract")
    extract_parser.add_argument("source")
    extract_parser.add_argument("--max-chars", type=int, default=500000)

    page_parser = subparsers.add_parser("extract-page")
    page_parser.add_argument("source")
    page_parser.add_argument("--page", type=int, default=1)
    page_parser.add_argument("--max-chars", type=int, default=3000)

    args = parser.parse_args()
    source = Path(args.source).resolve()
    if not source.is_file():
        raise FileNotFoundError(f"Document not found: {source}")

    if args.command == "convert":
        target = Path(args.target).resolve()
        if source.suffix.lower() != ".docx":
            raise ValueError("The fallback converter supports DOCX files only")
        convert_docx_to_pdf(source, target)
        result = {"success": True, "pdf_path": str(target), "size": target.stat().st_size}
    elif args.command == "extract":
        text = extract_text(source)
        normalized = "\n".join(line.rstrip() for line in text.splitlines()).strip()
        result = {
            "success": True,
            "text": normalized[: args.max_chars],
            "characters": len(normalized),
            "truncated": len(normalized) > args.max_chars,
        }
    else:
        page = extract_page_text(source, args.page)
        normalized = "\n".join(line.rstrip() for line in str(page["text"]).splitlines()).strip()
        result = {
            "success": True,
            "text": normalized[: args.max_chars],
            "characters": len(normalized),
            "truncated": len(normalized) > args.max_chars,
            "page": page["page"],
            "total_pages": page["total_pages"],
            "is_blank": bool(page.get("is_blank", False)),
        }

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}, ensure_ascii=False))
        raise SystemExit(1)
