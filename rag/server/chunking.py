"""Split entry content into chunks that fit the embedding model's context.

The default embedding model truncates at 128 tokens (~500 chars), so entries
are embedded one chunk at a time. Chunking is character-based on purpose: it
must not depend on the (heavy, lazily-loaded) embedding model.
"""
import re

MAX_CHARS = 600
OVERLAP = 100


def chunk_text(text, max_chars=MAX_CHARS, overlap=OVERLAP):
    """Split text into chunks of at most max_chars.

    Packs whole paragraphs together while they fit; paragraphs longer than
    max_chars are split at word boundaries with `overlap` chars of overlap.
    Returns [] for empty/whitespace text.
    """
    text = text.strip()
    if not text:
        return []
    chunks = []
    current = ""
    for para in re.split(r"\n\s*\n", text):
        para = para.strip()
        if not para:
            continue
        if len(para) > max_chars:
            if current:
                chunks.append(current)
                current = ""
            chunks.extend(_split_long(para, max_chars, overlap))
        elif current and len(current) + 2 + len(para) > max_chars:
            chunks.append(current)
            current = para
        else:
            current = f"{current}\n\n{para}" if current else para
    if current:
        chunks.append(current)
    return chunks


def _split_long(text, max_chars, overlap):
    chunks = []
    start = 0
    while start < len(text):
        end = start + max_chars
        if end < len(text):
            space = text.rfind(" ", start, end)
            if space > start:
                end = space
        chunk = text[start:end].strip()
        if chunk:
            chunks.append(chunk)
        if end >= len(text):
            break
        start = max(end - overlap, start + 1)
    return chunks
