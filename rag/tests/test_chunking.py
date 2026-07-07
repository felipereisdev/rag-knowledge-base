"""Tests for content chunking."""


class TestChunkText:
    def test_empty_text_returns_no_chunks(self):
        import chunking
        assert chunking.chunk_text("") == []
        assert chunking.chunk_text("   \n\n  ") == []

    def test_short_text_is_one_chunk(self):
        import chunking
        assert chunking.chunk_text("hello world") == ["hello world"]

    def test_packs_paragraphs_up_to_max_chars(self):
        import chunking
        p1 = "a" * 200
        p2 = "b" * 200
        p3 = "c" * 300
        chunks = chunking.chunk_text(f"{p1}\n\n{p2}\n\n{p3}", max_chars=450)
        assert chunks == [f"{p1}\n\n{p2}", p3]

    def test_long_paragraph_is_split_with_overlap(self):
        import chunking
        words = " ".join(f"word{i}" for i in range(300))  # ~2300 chars, no \n\n
        chunks = chunking.chunk_text(words, max_chars=600, overlap=100)
        assert len(chunks) >= 3
        assert all(len(c) <= 600 for c in chunks)
        # Overlap: the start of chunk N+1 repeats the tail of chunk N
        assert chunks[1].split()[0] in chunks[0]

    def test_all_chunks_within_limit_and_nonempty(self):
        import chunking
        text = "Intro paragraph.\n\n" + ("x" * 2000) + "\n\nOutro."
        chunks = chunking.chunk_text(text, max_chars=600, overlap=100)
        assert chunks
        assert all(c.strip() for c in chunks)
        assert all(len(c) <= 600 for c in chunks)
