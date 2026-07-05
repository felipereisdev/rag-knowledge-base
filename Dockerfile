FROM python:3.12-slim

WORKDIR /app

COPY rag/ /app/rag/

RUN pip3 install --no-cache-dir pytest

ENV PYTHONUNBUFFERED=1
ENV PYTHONPATH=/app/rag/server

EXPOSE 8765

CMD ["python3", "rag/server/main.py"]
