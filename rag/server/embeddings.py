"""Sentence-transformers embedding model wrapper. Model loads lazily on first use."""

import logging
import os

EMBEDDING_DIM = int(os.environ.get("RAG_EMBEDDING_DIM", "768"))
MODEL_NAME = os.environ.get(
    "RAG_EMBEDDING_MODEL",
    "paraphrase-multilingual-mpnet-base-v2",
)

_model = None


def get_model():
    global _model
    if _model is None:
        from sentence_transformers import SentenceTransformer

        logging.getLogger("sentence_transformers").setLevel(logging.WARNING)
        _model = SentenceTransformer(MODEL_NAME)
    return _model


EMBED_URL = os.environ.get("RAG_EMBED_URL", "http://127.0.0.1:8000/api/embed")

# True in the process that runs the admin API (set by api.start_api_server):
# that process must embed with its own model or requests would loop back to it.
serving_locally = False


def _remote_enabled():
    return os.environ.get("RAG_EMBED_REMOTE", "1") != "0"


def _embed_remote(texts):
    """POST texts to the shared /api/embed endpoint. None on any failure."""
    try:
        import httpx
        resp = httpx.post(EMBED_URL, json={"texts": texts}, timeout=60.0)
        if resp.status_code != 200:
            return None
        data = resp.json()
        if data.get("model") != MODEL_NAME or data.get("dim") != EMBEDDING_DIM:
            return None
        return data["embeddings"]
    except Exception:
        return None


def embed_local(text):
    if not text:
        return [0.0] * EMBEDDING_DIM
    model = get_model()
    return model.encode(text).tolist()


def embed_text(text):
    if not text:
        return [0.0] * EMBEDDING_DIM
    if not serving_locally and _remote_enabled():
        vecs = _embed_remote([text])
        if vecs:
            return vecs[0]
    return embed_local(text)


def embed_query(query):
    return embed_text(query)
