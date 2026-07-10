// stubs/client/opencode/plugin/rag.ts
// RAG memory plugin for opencode. Talks to the RAG server's /hooks/* endpoints.
import type { Plugin } from "@opencode-ai/plugin"

const RAG_URL = "http://localhost:8090"
const RAG_TOKEN = ""
const INJECT_ON_START = false // set true to inject the approved-knowledge digest
const CONDENSE = true

async function ragPost(endpoint: string, body: unknown): Promise<string> {
  try {
    const res = await fetch(`${RAG_URL}/hooks/${endpoint}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${RAG_TOKEN}` },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(4000),
    })
    if (!res.ok) return ""
    return await res.text()
  } catch {
    return ""
  }
}

const CONDENSE_INSTRUCTION =
  "Before you finish: judge whether this session produced durable knowledge (a decision, rule, architecture note, non-obvious fix, or convention). If not, stop. If yes: call rag_search to dedup, then condense into one or more entries (title, Markdown content, category, entities/relations) and call rag_store_knowledge (it lands in pending)."

export const RagMemory: Plugin = async ({ client, directory }) => {
  const condensed = new Set<string>()

  return {
    event: async ({ event }) => {
      if (event.type === "session.created") {
        await ragPost("ensure-project", { cwd: directory })
      }
      if (CONDENSE && event.type === "session.idle") {
        const id = (event as any).properties?.sessionID
        if (id && !condensed.has(id)) {
          condensed.add(id)
          await client.session.prompt({
            path: { id },
            body: { parts: [{ type: "text", text: CONDENSE_INSTRUCTION }] },
          })
        }
      }
    },

    "chat.message": async (_input, output) => {
      const textParts = (output.parts ?? []).filter((p: any) => p.type === "text")
      const text = textParts.map((p: any) => p.text).join(" ").trim()
      if (text.length < 8) return
      const hits = await ragPost("search", { cwd: directory, query: text })
      if (hits) {
        // Mutate the existing text part instead of pushing a bare one: a pushed
        // { type, text } lacks the id/sessionID/messageID keys opencode's part
        // schema now requires, which rejects the whole user message on save.
        const target = textParts[textParts.length - 1]
        if (target) target.text += `\n\n[RAG] Relevant prior knowledge:\n${hits}`
      }
    },

    "experimental.chat.system.transform": async (_input, output) => {
      if (!INJECT_ON_START) return
      const digest = await ragPost("digest", { cwd: directory })
      if (digest) output.system.push(`<rag-approved-knowledge>\n${digest}\n</rag-approved-knowledge>`)
    },
  }
}
