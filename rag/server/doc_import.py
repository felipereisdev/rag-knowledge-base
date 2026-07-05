"""Document import: parse markdown and text files into knowledge entries."""
import os
import re


def parse_markdown(text):
    """Parse markdown into knowledge entries.

    Splits on H1/H2 headers. Each section becomes an entry with the
    header as title and the body as content. Supports YAML frontmatter
    for category and tags.
    """
    category = "insight"
    tags = []

    # Parse frontmatter
    fm_match = re.match(r"^---\n(.*?)\n---\n", text, re.DOTALL)
    if fm_match:
        frontmatter = fm_match.group(1)
        text = text[fm_match.end():]

        for line in frontmatter.split("\n"):
            if line.startswith("category:"):
                category = line.split(":", 1)[1].strip()
            elif line.startswith("tags:"):
                tag_str = line.split(":", 1)[1].strip()
                tags = [t.strip().lower() for t in tag_str.split(",") if t.strip()]

    # Split on headers
    header_pattern = re.compile(r"^(#{1,2})\s+(.+)$", re.MULTILINE)
    sections = []
    current_title = None
    current_body = []
    found_first_header = False

    for line in text.split("\n"):
        match = header_pattern.match(line)
        if match:
            if found_first_header and current_body:
                body = "\n".join(current_body).strip()
                if body:
                    sections.append((current_title, body))
            current_title = match.group(2).strip()
            current_body = []
            found_first_header = True
        else:
            current_body.append(line)

    # Last section
    if found_first_header and current_body:
        body = "\n".join(current_body).strip()
        if body:
            sections.append((current_title, body))

    # No headers found — treat whole file as one entry
    if not sections:
        body = text.strip()
        if body:
            sections.append(("Untitled", body))

    entries = []
    for title, body in sections:
        entries.append({
            "title": title,
            "content": body,
            "category": category,
            "tags": list(tags),
        })
    return entries


def parse_text_file(text):
    """Parse a plain text file into a single knowledge entry."""
    body = text.strip()
    if not body:
        return []
    return [{
        "title": "Untitled",
        "content": body,
        "category": "insight",
        "tags": [],
    }]


def import_document(db, project_id, filepath, category="insight", tags=None):
    """Import a .md or .txt file into the knowledge base.

    Returns a list of created entry IDs.
    """
    if not os.path.isfile(filepath):
        raise FileNotFoundError(f"File not found: {filepath}")

    with open(filepath, "r", encoding="utf-8") as f:
        content = f.read()

    ext = os.path.splitext(filepath)[1].lower()
    if ext == ".md":
        entries = parse_markdown(content)
    elif ext == ".txt":
        entries = parse_text_file(content)
    else:
        # Try markdown first, fall back to text
        entries = parse_markdown(content)

    entry_ids = []
    for entry in entries:
        entry_category = category if category != "insight" else entry.get("category", "insight")
        entry_tags = tags if tags is not None else entry.get("tags", [])
        eid = db.store_knowledge_entry(
            project_id=project_id,
            title=entry["title"],
            content=entry["content"],
            category=entry_category,
            source="import",
            tags=entry_tags,
        )
        entry_ids.append(eid)

    return entry_ids
