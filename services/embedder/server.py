from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer
import os

app = FastAPI(title="RAG Embedder", version="1.0.0")
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

MODEL_NAME = os.environ.get("RAG_EMBEDDING_MODEL", "paraphrase-multilingual-mpnet-base-v2")
MODEL_DIM = int(os.environ.get("RAG_EMBEDDING_DIM", "768"))
model = SentenceTransformer(MODEL_NAME)


class EmbeddingRequest(BaseModel):
    input: str | list[str]
    model: str | None = None
    dimensions: int | None = None


class EmbeddingData(BaseModel):
    object: str = "embedding"
    index: int
    embedding: list[float]


class EmbeddingResponse(BaseModel):
    object: str = "list"
    data: list[EmbeddingData]
    model: str
    usage: dict


@app.get("/v1/models")
def list_models():
    return {"object": "list", "data": [{"id": MODEL_NAME, "object": "model"}]}


@app.post("/v1/embeddings")
def create_embeddings(req: EmbeddingRequest):
    inputs = req.input if isinstance(req.input, list) else [req.input]
    embeddings = model.encode(inputs, normalize_embeddings=True).tolist()
    return EmbeddingResponse(
        data=[
            EmbeddingData(index=i, embedding=e)
            for i, e in enumerate(embeddings)
        ],
        model=MODEL_NAME,
        usage={"prompt_tokens": 0, "total_tokens": 0},
    )


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME, "dim": MODEL_DIM}
