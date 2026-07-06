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


def embed_text(text):
    if not text:
        return [0.0] * EMBEDDING_DIM
    model = get_model()
    return model.encode(text).tolist()


def embed_query(query):
    return embed_text(query)
