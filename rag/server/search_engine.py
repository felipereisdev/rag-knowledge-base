"""TF-IDF search engine for knowledge base entries."""
import re
import math
from collections import Counter


def tokenize(text):
    """Tokenize: lowercase, split on non-alphanumeric, filter short tokens."""
    if not text:
        return []
    text = text.lower()
    tokens = re.findall(r"[a-z0-9_]{2,}", text)
    return tokens


class TFIDFIndex:
    """In-memory TF-IDF index over knowledge entries.

    Indexes the combination of title, content, and tags for each entry.
    Supports filtering results by category and tags.
    """

    def __init__(self):
        self.entries = []
        self.doc_freq = Counter()
        self.tf = []
        self.total_entries = 0

    def add_entry(self, entry_id, title, content, category, tags):
        # Combine title (weighted 2x), content, and tags into one document
        title_tokens = tokenize(title) * 2  # title weight doubled
        content_tokens = tokenize(content)
        tag_tokens = []
        for tag in (tags or []):
            tag_tokens.extend(tokenize(tag))

        all_tokens = title_tokens + content_tokens + tag_tokens
        if not all_tokens:
            return

        tf = Counter(all_tokens)
        self.tf.append(tf)
        self.entries.append({
            "id": entry_id,
            "title": title,
            "content": content,
            "category": category,
            "tags": tags or [],
            "tokens": all_tokens,
        })
        for term in tf:
            self.doc_freq[term] += 1
        self.total_entries += 1

    def finalize(self):
        N = max(self.total_entries, 1)
        self.idf = {term: 1 + math.log(N / df) for term, df in self.doc_freq.items()}

    def search(self, query, top_k=5, category=None, tags=None):
        """Search the index. Returns list of result dicts sorted by score."""
        query_tokens = tokenize(query)
        if not query_tokens or self.total_entries == 0:
            return []

        query_tf = Counter(query_tokens)

        scores = []
        for i, entry in enumerate(self.entries):
            # Apply category filter
            if category and entry["category"] != category:
                continue

            # Apply tag filter (entry must have ALL specified tags)
            if tags:
                entry_tags_lower = set(t.lower() for t in entry["tags"])
                if not all(t.lower() in entry_tags_lower for t in tags):
                    continue

            score = 0.0
            chunk_tf = self.tf[i]
            for term, qtf in query_tf.items():
                if term in chunk_tf and term in self.idf:
                    tf_val = chunk_tf[term]
                    idf_val = self.idf[term]
                    score += tf_val * idf_val * qtf

            if score > 0:
                doc_len = len(entry["tokens"])
                if doc_len > 0:
                    score = score / math.sqrt(doc_len)
                scores.append((i, score))

        scores.sort(key=lambda x: -x[1])

        results = []
        for idx, score in scores[:top_k]:
            entry = self.entries[idx]
            results.append({
                "id": entry["id"],
                "title": entry["title"],
                "content": entry["content"],
                "category": entry["category"],
                "tags": entry["tags"],
                "score": round(score, 4),
            })
        return results


def build_index_from_entries(entries):
    """Build a TF-IDF index from a list of entry dicts."""
    index = TFIDFIndex()
    for e in entries:
        index.add_entry(
            e["id"],
            e["title"],
            e["content"],
            e.get("category", "insight"),
            e.get("tags", []),
        )
    index.finalize()
    return index
