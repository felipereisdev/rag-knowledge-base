"""RAG engine: file scanning, chunking, and semantic search via TF-IDF."""

import os
import fnmatch
import re
import json
import math
import hashlib
from collections import Counter, defaultdict

# File extensions we index by default
SUPPORTED_EXTENSIONS = {
    ".py", ".js", ".ts", ".tsx", ".jsx", ".java", ".kt", ".swift",
    ".go", ".rs", ".rb", ".php", ".c", ".h", ".cpp", ".hpp", ".cs",
    ".md", ".txt", ".rst", ".yaml", ".yml", ".json", ".toml",
    ".sql", ".sh", ".bash", ".zsh", ".fish", ".ps1",
    ".html", ".css", ".scss", ".vue", ".svelte",
    ".dockerfile", ".env", ".cfg", ".ini", ".conf", ".proto",
    ".graphql", ".gql", ".terraform", ".tf", ".tfvars", ".hcl",
}

# Directories always skipped (unconditionally)
SKIP_DIRS = {
    ".git", ".svn", ".hg",
    "__pycache__", ".mypy_cache", ".pytest_cache", ".cache",
    ".venv", "venv", "env", ".env",
    "node_modules", ".next", ".nuxt",
    "dist", "build", ".build", "target",
    ".tox", ".eggs", "egg-info",
    ".idea", ".vscode",
    "coverage", ".coverage", "htmlcov",
    ".terraform",
    "Pods",
}

# Files auto-skipped by name (lock files, generated manifests, etc.)
LOW_VALUE_FILES = {
    "package-lock.json", "yarn.lock", "pnpm-lock.yaml", "bun.lockb",
    "Gemfile.lock", "poetry.lock", "Cargo.lock", "composer.lock",
    "go.sum",
    ".DS_Store", "Thumbs.db",
    ".gitignore", ".gitkeep", ".gitattributes",
    ".dockerignore", ".editorconfig",
    "LICENSE", "LICENSE.txt", "LICENSE.md", "COPYING",
    ".prettierrc", ".eslintrc", ".babelrc", ".stylelintrc",
}

# Glob patterns for files that are likely generated or low-value
LOW_VALUE_PATTERNS = [
    "*.min.js", "*.min.css",
    "*.bundle.js", "*.chunk.js",
    "*_pb2.py", "*_pb2_grpc.py", "*.pb.swift", "*.pb.go",
    "*.pb.h", "*.pb.cc",
    "*.generated.*", "*.gen.*",
    "*-generated.*", "*.g.dart",
    "*_grpc.pb.go", "*_grpc.swift",
    "*.bundle.min.js",
]

# Test file patterns
TEST_PATTERNS = [
    "*.test.js", "*.test.ts", "*.test.tsx",
    "*.spec.js", "*.spec.ts", "*.spec.tsx",
    "*_test.go", "*_test.py", "*_tests.py",
    "test_*.py", "test_*.js", "test_*.ts",
]

MAX_FILE_SIZE = 512 * 1024  # 512KB
CHUNK_SIZE = 1500  # characters
CHUNK_OVERLAP = 200  # characters


# ---------------------------------------------------------------------------
# Project type detection
# ---------------------------------------------------------------------------

INDICATOR_FILES = {
    "python-django": {"requirements.txt", "manage.py", "setup.py", "Pipfile"},
    "python-fastapi": {"requirements.txt", "pyproject.toml", "setup.py"},
    "python-flask": {"requirements.txt", "app.py", "wsgi.py"},
    "node-express": {"package.json", "app.js", "server.js", "index.js"},
    "node-next": {"package.json", "next.config.js", "next.config.ts"},
    "node-nest": {"package.json", "nest-cli.json"},
    "go": {"go.mod"},
    "rust": {"Cargo.toml"},
    "java-spring": {"pom.xml", "build.gradle", "gradlew"},
    "kotlin": {"build.gradle.kts", "settings.gradle.kts"},
    "swift": {"Package.swift", ".xcodeproj", "*.xcworkspace"},
    "react": {"package.json", "App.jsx", "App.tsx", "vite.config.ts"},
    "vue": {"package.json", "vue.config.js", "nuxt.config.ts"},
    "terraform": {"*.tf", "*.tfvars"},
    "elixir": {"mix.exs"},
}

# Default scan strategies per project type
SCAN_PATTERNS = {
    "python-django": {
        "include": ["**/apps/**/*.py", "**/api/**/*.py", "**/models/**/*.py", "**/views/**/*.py",
                    "**/serializers/**/*.py", "**/urls.py", "**/settings*.py",
                    "**/*.md", "**/*.rst"],
        "exclude": ["**/migrations/**", "**/tests/**", "**/test_*.py", "**/management/**"],
        "skip_tests": True,
    },
    "python-fastapi": {
        "include": ["**/app/**/*.py", "**/routers/**/*.py", "**/models/**/*.py",
                    "**/schemas/**/*.py", "**/services/**/*.py", "**/dependencies/**/*.py",
                    "**/main.py", "**/config.py", "**/*.md"],
        "exclude": [],
        "skip_tests": True,
    },
    "node-express": {
        "include": ["**/routes/**/*.js", "**/routes/**/*.ts",
                    "**/controllers/**/*.js", "**/controllers/**/*.ts",
                    "**/models/**/*.js", "**/models/**/*.ts",
                    "**/services/**/*.js", "**/services/**/*.ts",
                    "**/middleware/**/*.js", "**/middleware/**/*.ts",
                    "**/app.js", "**/app.ts", "**/server.js",
                    "**/config/**/*.js", "**/config/**/*.ts",
                    "**/*.md"],
        "exclude": ["**/node_modules/**", "**/dist/**"],
        "skip_tests": True,
    },
    "node-next": {
        "include": ["**/app/**/*.ts", "**/app/**/*.tsx",
                    "**/pages/**/*.ts", "**/pages/**/*.tsx",
                    "**/components/**/*.ts", "**/components/**/*.tsx",
                    "**/lib/**/*.ts", "**/utils/**/*.ts",
                    "**/api/**/*.ts",
                    "**/*.md"],
        "exclude": ["**/node_modules/**", "**/.next/**"],
        "skip_tests": True,
    },
    "go": {
        "include": ["**/*.go", "**/*.md"],
        "exclude": ["**/vendor/**"],
        "skip_tests": True,
    },
    "rust": {
        "include": ["**/src/**/*.rs", "**/*.md"],
        "exclude": [],
        "skip_tests": True,
    },
    "react": {
        "include": ["**/src/**/*.ts", "**/src/**/*.tsx", "**/src/**/*.js", "**/src/**/*.jsx",
                    "**/components/**/*.tsx", "**/pages/**/*.tsx",
                    "**/hooks/**/*.ts", "**/utils/**/*.ts",
                    "**/services/**/*.ts", "**/*.md"],
        "exclude": ["**/node_modules/**", "**/dist/**", "**/build/**"],
        "skip_tests": True,
    },
}

# Generic fallback (covers anything not matched above)
GENERIC_PATTERNS = {
    "include": ["**/src/**/*.py", "**/src/**/*.js", "**/src/**/*.ts",
                "**/lib/**/*.py", "**/lib/**/*.js", "**/lib/**/*.ts",
                "**/app/**/*.py", "**/app/**/*.js", "**/app/**/*.ts",
                "**/api/**/*.py", "**/api/**/*.js", "**/api/**/*.ts",
                "**/models/**/*.py", "**/models/**/*.js", "**/models/**/*.ts",
                "**/routes/**/*.js", "**/routes/**/*.ts",
                "**/controllers/**/*.py", "**/controllers/**/*.js", "**/controllers/**/*.ts",
                "**/services/**/*.py", "**/services/**/*.js", "**/services/**/*.ts",
                "**/*.py", "**/main.py",
                "**/app.js", "**/index.js", "**/server.js",
                "**/config/**/*", "**/*.md"],
    "exclude": ["**/tests/**", "**/test_*", "**/migrations/**", "**/vendor/**"],
    "skip_tests": True,
}


def detect_project_type(root_path):
    """Detect the project type by looking for indicator files.

    Returns (type_name, description) or (None, "Unknown").
    """
    root_path = os.path.abspath(root_path)

    # Collect all filenames in the root
    try:
        root_files = set(os.listdir(root_path))
    except OSError:
        return None, "Unknown"

    # Check for indicator files, both exact and pattern
    best_match = None
    best_score = 0

    for proj_type, indicators in INDICATOR_FILES.items():
        score = 0
        for ind in indicators:
            if "*" in ind:
                # Glob match
                if any(fnmatch.fnmatch(f, ind) for f in root_files):
                    score += 1
            else:
                if ind in root_files:
                    score += 1
        if score > best_score:
            best_score = score
            best_match = proj_type

    if best_match and best_score >= 1:
        # Human-readable descriptions
        descs = {
            "python-django": "Django web application",
            "python-fastapi": "FastAPI application",
            "python-flask": "Flask application",
            "node-express": "Express.js Node application",
            "node-next": "Next.js application",
            "node-nest": "NestJS application",
            "go": "Go application",
            "rust": "Rust application",
            "java-spring": "Java Spring application",
            "kotlin": "Kotlin application",
            "swift": "Swift/iOS application",
            "react": "React frontend application",
            "vue": "Vue.js/Nuxt application",
            "terraform": "Terraform infrastructure",
            "elixir": "Elixir application",
        }
        return best_match, descs.get(best_match, best_match.replace("-", " ").title())

    # Fallback: check common languages by file presence
    has_py = any(f.endswith(".py") for f in root_files) or _has_files_recursive(root_path, "*.py", 2)
    has_js = any(f.endswith(".js") for f in root_files) or _has_files_recursive(root_path, "*.js", 2)
    has_ts = any(f.endswith(".ts") for f in root_files) or _has_files_recursive(root_path, "*.ts", 2)
    has_go = any(f.endswith(".go") for f in root_files) or _has_files_recursive(root_path, "*.go", 2)
    has_rs = any(f.endswith(".rs") for f in root_files) or _has_files_recursive(root_path, "*.rs", 2)
    has_md = any(f.endswith(".md") for f in root_files)

    if has_py:
        return "python-generic", "Python application"
    if has_ts or has_js:
        return "node-generic", "Node.js/TypeScript application"
    if has_go:
        return "go-generic", "Go application"
    if has_rs:
        return "rust-generic", "Rust application"
    if has_md:
        return "docs", "Documentation project"

    return None, "Unknown"


def _has_files_recursive(root, pattern, max_depth=2):
    """Check if any files matching a glob pattern exist within max_depth directories."""
    root_len = len(os.path.abspath(root).split(os.sep))
    for dirpath, dirnames, filenames in os.walk(root):
        depth = len(os.path.abspath(dirpath).split(os.sep)) - root_len
        if depth > max_depth:
            dirnames[:] = []
            continue
        if any(fnmatch.fnmatch(f, pattern) for f in filenames):
            return True
    return False


def get_scan_strategy(project_type):
    """Get recommended scan patterns for a project type."""
    if project_type in SCAN_PATTERNS:
        return SCAN_PATTERNS[project_type]
    return GENERIC_PATTERNS


# ---------------------------------------------------------------------------
# File scanning
# ---------------------------------------------------------------------------

def _matches_any(fname, patterns):
    """Check if filename matches any glob pattern."""
    if not patterns:
        return False
    return any(fnmatch.fnmatch(fname, p) for p in patterns)


def _classify_file(rel_path, fname):
    """Classify a file by its role in the project.

    Returns one of: source, config, docs, test, script, web, data, generated.
    """
    path_lower = rel_path.lower()
    name_lower = fname.lower()
    ext = os.path.splitext(fname)[1].lower()

    # Detect generated files first (highest priority for skipping)
    if _matches_any(fname, LOW_VALUE_PATTERNS):
        return "generated"

    # Detect test files by path and name patterns
    path_parts = path_lower.split("/")
    if any(seg in path_parts for seg in ("tests", "spec", "__tests__", "test", "fixtures")):
        if _matches_any(fname, TEST_PATTERNS) or ext in (".py", ".js", ".ts", ".go", ".rs", ".java"):
            return "test"
    if _matches_any(fname, TEST_PATTERNS):
        return "test"

    # Detect config files
    if fname in (".env", ".env.example", "docker-compose.yml", "docker-compose.yaml"):
        return "config"
    if name_lower in ("config.json", "config.yaml", "config.yml", "settings.json",
                       "pyproject.toml", "setup.py", "setup.cfg"):
        return "config"
    if ext in (".json", ".yaml", ".yml", ".toml", ".ini", ".cfg", ".conf", ".env",
               ".tf", ".tfvars", ".hcl"):
        return "config"

    # Detect docs
    if ext in (".md", ".txt", ".rst"):
        return "docs"

    # Detect scripts
    if ext in (".sh", ".bash", ".zsh", ".fish", ".ps1"):
        return "script"

    # Detect web assets
    if ext in (".html", ".css", ".scss", ".vue", ".svelte"):
        return "web"

    # Detect SQL/data
    if ext == ".sql":
        return "data"

    # Everything else with a supported ext is source code
    return "source"


def scan_project(root_path, extensions=None, max_files=500,
                 include_patterns=None, exclude_patterns=None,
                 skip_generated=True, skip_tests=False, skip_low_value=True):
    """Scan a project directory and return list of files to index with metadata."""
    exts = extensions or SUPPORTED_EXTENSIONS
    files = []
    root_path = os.path.abspath(root_path)

    for dirpath, dirnames, filenames in os.walk(root_path):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS and not d.startswith(".")]

        for fname in sorted(filenames):
            ext = os.path.splitext(fname)[1].lower()
            if not ext and fname in ("Dockerfile", "Makefile", "Rakefile", "Gemfile"):
                ext = fname.lower()
            if ext not in exts:
                continue

            full_path = os.path.join(dirpath, fname)
            rel_path = os.path.relpath(full_path, root_path)

            if skip_low_value and fname in LOW_VALUE_FILES:
                continue

            file_type = _classify_file(rel_path, fname)
            if skip_generated and file_type == "generated":
                continue
            if skip_tests and file_type == "test":
                continue

            if include_patterns and not _matches_any(rel_path, include_patterns):
                continue

            if exclude_patterns and _matches_any(rel_path, exclude_patterns):
                continue

            try:
                size = os.path.getsize(full_path)
                if size > MAX_FILE_SIZE:
                    continue
            except OSError:
                continue

            files.append({
                "rel_path": rel_path,
                "full_path": full_path,
                "size": size,
                "type": file_type,
            })

            if len(files) >= max_files:
                return files

    return files


def read_file_content(filepath):
    """Read file content, attempting UTF-8 then latin-1."""
    try:
        with open(filepath, "r", encoding="utf-8") as f:
            return f.read()
    except UnicodeDecodeError:
        try:
            with open(filepath, "r", encoding="latin-1") as f:
                return f.read()
        except Exception:
            return ""


def chunk_text(text, chunk_size=CHUNK_SIZE, overlap=CHUNK_OVERLAP):
    """Split text into overlapping chunks, trying to break on natural boundaries."""
    if len(text) <= chunk_size:
        return [text] if text.strip() else []

    chunks = []
    start = 0
    while start < len(text):
        end = start + chunk_size

        if end < len(text):
            search_start = start + int(chunk_size * 0.8)
            for boundary in ["\n\n", "\n", ". ", "; ", "}"]:
                idx = text.rfind(boundary, search_start, end)
                if idx != -1:
                    end = idx + len(boundary)
                    break

        chunk = text[start:end].strip()
        if chunk:
            chunks.append(chunk)

        if end >= len(text):
            break
        start = max(end - overlap, start + 1)

    return chunks


def tokenize(text):
    """Simple tokenizer: lowercase, split on non-alphanumeric, filter short tokens."""
    text = text.lower()
    tokens = re.findall(r"[a-z0-9_]{2,}", text)
    return tokens


# ---------------------------------------------------------------------------
# TF-IDF Search
# ---------------------------------------------------------------------------

class TFIDFIndex:
    """In-memory TF-IDF index for a project's indexed chunks."""

    def __init__(self):
        self.chunks = []
        self.doc_freq = Counter()
        self.tf = []
        self.total_chunks = 0

    def add_chunk(self, chunk_id, content, rel_path):
        tokens = tokenize(content)
        if not tokens:
            return

        tf = Counter(tokens)
        self.tf.append(tf)
        self.chunks.append({
            "id": chunk_id,
            "content": content,
            "rel_path": rel_path,
            "tokens": tokens,
        })
        for term in tf:
            self.doc_freq[term] += 1
        self.total_chunks += 1

    def finalize(self):
        N = max(self.total_chunks, 1)
        self.idf = {term: math.log(N / df) for term, df in self.doc_freq.items()}

    def search(self, query, top_k=5):
        query_tokens = tokenize(query)
        if not query_tokens or self.total_chunks == 0:
            return []

        query_tf = Counter(query_tokens)

        scores = []
        for i, chunk in enumerate(self.chunks):
            score = 0.0
            chunk_tf = self.tf[i]
            for term, qtf in query_tf.items():
                if term in chunk_tf and term in self.idf:
                    tf_val = chunk_tf[term]
                    idf_val = self.idf[term]
                    score += tf_val * idf_val * qtf

            if score > 0:
                chunk_len = len(chunk["tokens"])
                if chunk_len > 0:
                    score = score / math.sqrt(chunk_len)
                scores.append((i, score))

        scores.sort(key=lambda x: -x[1])
        results = []
        for idx, score in scores[:top_k]:
            chunk = self.chunks[idx]
            results.append({
                "chunk_id": chunk["id"],
                "content": chunk["content"],
                "rel_path": chunk["rel_path"],
                "score": round(score, 4),
            })
        return results


def build_index_from_chunks(chunks_data):
    """Build a TF-IDF index from a list of chunk dicts."""
    index = TFIDFIndex()
    for c in chunks_data:
        index.add_chunk(c["id"], c["content"], c.get("rel_path", ""))
    index.finalize()
    return index
