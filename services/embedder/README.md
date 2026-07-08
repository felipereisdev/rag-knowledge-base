# RAG Embedder Sidecar

Minimal FastAPI service that generates embeddings using sentence-transformers.
Exposes an OpenAI-compatible `/v1/embeddings` endpoint so the Laravel AI SDK
can call it via the `openai-compatible` driver.

## Model

- `paraphrase-multilingual-mpnet-base-v2` (768 dimensions)
- Normalized embeddings (cosine similarity = dot product)

## Endpoints

- `POST /v1/embeddings` — generate embeddings (OpenAI-compatible)
- `GET /v1/models` — list available models
- `GET /health` — health check

## Local development

```bash
cd services/embedder
pip install -r requirements.txt
python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('paraphrase-multilingual-mpnet-base-v2')"
uvicorn server:app --host 0.0.0.0 --port 8001
```

## Docker

```bash
docker build -t rag-embedder .
docker run -p 8001:8000 -e RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2 rag-embedder
```

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `RAG_EMBEDDING_MODEL` | `paraphrase-multilingual-mpnet-base-v2` | sentence-transformers model name |
| `RAG_EMBEDDING_DIM` | `768` | Embedding dimension (informational) |
