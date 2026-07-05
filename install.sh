#!/usr/bin/env bash
set -e

# ──────────────────────────────────────────────────────────
# RAG Knowledge Base - Installer
# Adds RAG instructions to CLAUDE.md and AGENTS.md for a project
# ──────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAG_DIR="$SCRIPT_DIR/rag"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

usage() {
    echo "Usage: $0 [project-path]"
    echo ""
    echo "Installs RAG Knowledge Base instructions into a project's"
    echo "CLAUDE.md (Claude) and AGENTS.md (Codex/OpenCode)."
    echo ""
    echo "Arguments:"
    echo "  project-path    Path to the project (default: current directory)"
    echo ""
    echo "Options:"
    echo "  --help          Show this help message"
    echo "  --dry-run       Show what would be written without modifying files"
    echo "  --db-only       Initialize the database only (no config files)"
    exit 1
}

DRY_RUN=false
DB_ONLY=false
PROJECT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --help) usage ;;
        --dry-run) DRY_RUN=true; shift ;;
        --db-only) DB_ONLY=true; shift ;;
        *) PROJECT_PATH="$1"; shift ;;
    esac
done

# Default to current directory if no path given
PROJECT_PATH="${PROJECT_PATH:-$(pwd)}"
PROJECT_PATH="$(cd "$PROJECT_PATH" 2>/dev/null && pwd || echo "$PROJECT_PATH")"

if [ ! -d "$PROJECT_PATH" ]; then
    echo -e "${YELLOW}Error: '$PROJECT_PATH' is not a valid directory.${NC}"
    exit 1
fi

PROJECT_NAME="$(basename "$PROJECT_PATH")"
PROJECT_ID="$(echo "$PROJECT_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/-/g' | sed 's/--*/-/g' | sed 's/^-//;s/-$//')"

# Detect project type
PYTHON_FILES=$(find "$PROJECT_PATH" -maxdepth 3 -name '*.py' 2>/dev/null | head -5)
JS_FILES=$(find "$PROJECT_PATH" -maxdepth 3 -name '*.js' -o -name '*.ts' 2>/dev/null | head -5)
GO_FILES=$(find "$PROJECT_PATH" -maxdepth 3 -name '*.go' 2>/dev/null | head -5)

if [ -f "$PROJECT_PATH/manage.py" ]; then
    PROJECT_TYPE="python-django"
    PROJECT_DESC="Django web application"
elif [ -f "$PROJECT_PATH/requirements.txt" ] && [ -n "$PYTHON_FILES" ]; then
    PROJECT_TYPE="python-generic"
    PROJECT_DESC="Python application"
elif [ -f "$PROJECT_PATH/go.mod" ]; then
    PROJECT_TYPE="go"
    PROJECT_DESC="Go application"
elif [ -f "$PROJECT_PATH/Cargo.toml" ]; then
    PROJECT_TYPE="rust"
    PROJECT_DESC="Rust application"
elif [ -f "$PROJECT_PATH/package.json" ] && [ -f "$PROJECT_PATH/next.config.js" -o -f "$PROJECT_PATH/next.config.ts" ] 2>/dev/null; then
    PROJECT_TYPE="node-next"
    PROJECT_DESC="Next.js application"
elif [ -f "$PROJECT_PATH/package.json" ] && [ -n "$JS_FILES" ]; then
    PROJECT_TYPE="node-generic"
    PROJECT_DESC="Node.js/TypeScript application"
elif [ -n "$PYTHON_FILES" ]; then
    PROJECT_TYPE="python-generic"
    PROJECT_DESC="Python application"
elif [ -n "$GO_FILES" ]; then
    PROJECT_TYPE="go"
    PROJECT_DESC="Go application"
elif [ -n "$JS_FILES" ]; then
    PROJECT_TYPE="node-generic"
    PROJECT_DESC="Node.js/TypeScript application"
else
    PROJECT_TYPE=""
    PROJECT_DESC="Unknown"
fi

# ── Print summary ──────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║      RAG Knowledge Base - Installer         ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}Project:${NC}     $PROJECT_NAME ($PROJECT_ID)"
echo -e "${GREEN}Path:${NC}       $PROJECT_PATH"
echo -e "${GREEN}Type:${NC}       ${PROJECT_TYPE:-unknown} ($PROJECT_DESC)"
echo ""

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Dry run — no files will be modified.${NC}"
    echo ""
fi

# ── RAG block for AGENTS.md / CLAUDE.md ───────────────────

RAG_BLOCK="# RAG Knowledge Base\n#\n# This project has a RAG knowledge base. Before answering questions about the codebase,\n# use the RAG tools to search for relevant context.\n#\n# - Project ID: $PROJECT_ID\n# - Project name: $PROJECT_NAME\n# - Project type: ${PROJECT_TYPE:-unknown}\n# - DB: ~/.rag/knowledge.db\n#\n# How to use:\n#   - rag_store_knowledge to save business rules, design decisions, architecture notes\n#   - rag_import_document to import .md/.txt files\n#   - rag_open_approval_ui to review pending entries\n#   - rag_search to query the knowledge base\n#\n# Triggers:\n# - User asks about code/business rules/architecture → call rag_search first\n# - User explains a rule/decision/constraint → call rag_store_knowledge\n# - User points to a .md/.txt doc → call rag_import_document"

RAG_BLOCK_SHORT="# Knowledge Base RAG — Project: $PROJECT_ID ($PROJECT_NAME)\n# Use rag_search before answering questions about business rules or architecture.\n# Store knowledge with rag_store_knowledge and import docs with rag_import_document."

# ── AGENTS.md (Codex / OpenCode) ──────────────────────────
AGENTS_FILE="$PROJECT_PATH/AGENTS.md"
AGENTS_NEEDED=false

if [ "$DB_ONLY" = false ]; then
    if [ -f "$AGENTS_FILE" ]; then
        if grep -q "RAG Knowledge Base" "$AGENTS_FILE" 2>/dev/null; then
            echo -e "${YELLOW}  AGENTS.md:${NC} RAG block already present (skipping)"
        else
            AGENTS_NEEDED=true
            if [ "$DRY_RUN" = false ]; then
                echo "" >> "$AGENTS_FILE"
                echo -e "$RAG_BLOCK" >> "$AGENTS_FILE"
                echo "" >> "$AGENTS_FILE"
                echo -e "${GREEN}  AGENTS.md:${NC} RAG instructions added"
            else
                echo -e "${BLUE}  AGENTS.md:${NC} would add RAG instructions"
            fi
        fi
    else
        AGENTS_NEEDED=true
        if [ "$DRY_RUN" = false ]; then
            echo -e "$RAG_BLOCK" > "$AGENTS_FILE"
            echo "" >> "$AGENTS_FILE"
            echo -e "${GREEN}  AGENTS.md:${NC} created with RAG instructions"
        else
            echo -e "${BLUE}  AGENTS.md:${NC} would create file with RAG instructions"
        fi
    fi

    # ── CLAUDE.md (Claude) ────────────────────────────────
    CLAUDE_FILE="$PROJECT_PATH/CLAUDE.md"

    if [ -f "$CLAUDE_FILE" ]; then
        if grep -q "RAG Knowledge Base" "$CLAUDE_FILE" 2>/dev/null; then
            echo -e "${YELLOW}  CLAUDE.md:${NC} RAG block already present (skipping)"
        else
            if [ "$DRY_RUN" = false ]; then
                echo "" >> "$CLAUDE_FILE"
                echo -e "$RAG_BLOCK" >> "$CLAUDE_FILE"
                echo "" >> "$CLAUDE_FILE"
                echo -e "${GREEN}  CLAUDE.md:${NC} RAG instructions added"
            else
                echo -e "${BLUE}  CLAUDE.md:${NC} would add RAG instructions"
            fi
        fi
    else
        if [ "$DRY_RUN" = false ]; then
            echo -e "$RAG_BLOCK" > "$CLAUDE_FILE"
            echo "" >> "$CLAUDE_FILE"
            echo -e "${GREEN}  CLAUDE.md:${NC} created with RAG instructions"
        else
            echo -e "${BLUE}  CLAUDE.md:${NC} would create file with RAG instructions"
        fi
    fi

    # ── .cursorrules (Cursor) ─────────────────────────────
    CURSOR_FILE="$PROJECT_PATH/.cursorrules"
    CURSOR_BLOCK="# RAG Knowledge Base\n#\n# This project has a RAG knowledge base. Start the MCP server and use the scripts:\n#   python3 $RAG_DIR/server/main.py\n#   python3 $RAG_DIR/scripts/search.py --query \"<question>\" --project $PROJECT_ID\n#   python3 $RAG_DIR/scripts/store.py --project $PROJECT_ID --title \"<title>\" --content \"<content>\" --category <category>\n#   python3 $RAG_DIR/scripts/import.py --file <path> --project $PROJECT_ID\n#\n# Project ID: $PROJECT_ID\n# Approval UI at http://127.0.0.1:8765"

    if [ -f "$CURSOR_FILE" ]; then
        if grep -q "RAG Knowledge Base" "$CURSOR_FILE" 2>/dev/null; then
            echo -e "${YELLOW}  .cursorrules:${NC} RAG block already present (skipping)"
        else
            if [ "$DRY_RUN" = false ]; then
                echo "" >> "$CURSOR_FILE"
                echo -e "$CURSOR_BLOCK" >> "$CURSOR_FILE"
                echo "" >> "$CURSOR_FILE"
                echo -e "${GREEN}  .cursorrules:${NC} RAG instructions added"
            else
                echo -e "${BLUE}  .cursorrules:${NC} would add RAG instructions"
            fi
        fi
    else
        if [ "$DRY_RUN" = false ]; then
            echo -e "$CURSOR_BLOCK" > "$CURSOR_FILE"
            echo "" >> "$CURSOR_FILE"
            echo -e "${GREEN}  .cursorrules:${NC} created with RAG instructions"
        else
            echo -e "${BLUE}  .cursorrules:${NC} would create file with RAG instructions"
        fi
    fi
fi

# ── Initialize RAG database ───────────────────────────────
if [ "$DRY_RUN" = false ]; then
    python3 "$RAG_DIR/server/main.py" &
    RAG_PID=$!
    sleep 1
    kill "$RAG_PID" 2>/dev/null || true

    # Register the project in the database
    python3 -c "
import sys
sys.path.insert(0, '$RAG_DIR/server')
import db
db.init_db()
db.upsert_project('$PROJECT_ID', '$PROJECT_NAME', '$PROJECT_PATH', '$PROJECT_DESC', '${PROJECT_TYPE:-unknown}')
print('  Database: project registered')
"
    echo -e "${GREEN}  Database:${NC} initialized at ~/.rag/knowledge.db"
else
    echo -e "${BLUE}  Database:${NC} would be initialized with project '$PROJECT_ID'"
fi

echo ""
if [ "$DRY_RUN" = false ]; then
    echo -e "${GREEN}Installation complete!${NC}"
    echo ""
    echo -e "Next steps:"
    echo -e "  1. Open a new conversation in your assistant"
    echo -e "  2. Call ${CYAN}rag_store_knowledge${NC} to save business rules or design decisions"
    echo -e "  3. Call ${CYAN}rag_import_document${NC} to import documentation or notes"
    echo -e "  4. Call ${CYAN}rag_open_approval_ui${NC} to review pending entries"
    echo -e "  5. Call ${CYAN}rag_search${NC} to query the knowledge base"
    echo -e ""
    echo -e "For Claude/Cursor without MCP support:"
    echo -e "  python3 $RAG_DIR/scripts/import.py --file README.md --project $PROJECT_ID"
    echo -e "  python3 $RAG_DIR/scripts/search.py --query \"<question>\" --project $PROJECT_ID"
else
    echo -e "Dry run complete. Run without --dry-run to apply changes."
fi
echo ""
