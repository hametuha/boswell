/**
 * Boswell-specific MCP tools.
 *
 * Bridges to Boswell REST API endpoints (boswell/v1/*).
 */

import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { findSite, getSiteNames, type SiteConfig } from "../config.js";
import { wpFetch } from "../client.js";

/** Response shape for GET boswell/v1/context. */
interface BoswellContext {
  site_name: string;
  site_url: string;
  personas: Array<{ id: string; name: string; persona: string }>;
  memory: string;
}

/** Response shape for POST boswell/v1/comment. */
interface BoswellComment {
  comment_id: number;
  content: string;
  post_id: number;
  author: string;
}

/** Response shape for GET boswell/v1/memory. */
interface BoswellMemory {
  memory: string;
  updated_at: string;
  sections: string[];
}

/** Response shape for POST boswell/v1/memory/entry. */
interface BoswellMemoryEntry {
  memory: string;
  updated_at: string;
  section: string;
}

const SiteParam = z
  .string()
  .min(1)
  .describe("Target site name or URL fragment");

function resolveSite(query: string): SiteConfig | string {
  const site = findSite(query);
  if (!site) {
    return `Site "${query}" not found. Available: ${getSiteNames().join(", ") || "(none)"}`;
  }
  return site;
}

function errorResponse(message: string) {
  return {
    content: [{ type: "text" as const, text: `Error: ${message}` }],
    isError: true,
  };
}

export function registerBoswellTools(server: McpServer): void {
  // ─── Get Context ────────────────────────────────────────────────
  server.registerTool(
    "boswell_get_context",
    {
      title: "Get Site Context",
      description:
        "IMPORTANT: Call this BEFORE writing content or commenting. " +
        "Returns site info, all personas with their style guides, and shared memory.",
      inputSchema: z.object({ site: SiteParam }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const ctx = await wpFetch<BoswellContext>(
          site,
          "/boswell/v1/context"
        );

        const parts = [
          `# ${ctx.site_name}`,
          `URL: ${ctx.site_url}`,
          "",
          `## Personas (${ctx.personas.length})`,
        ];

        for (const p of ctx.personas) {
          parts.push("", `### ${p.name} (${p.id})`, "", p.persona);
        }

        parts.push("", "## Memory", "", ctx.memory);

        return { content: [{ type: "text", text: parts.join("\n") }] };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  // ─── Comment ────────────────────────────────────────────────────
  server.registerTool(
    "boswell_comment",
    {
      title: "Generate AI Comment",
      description:
        "Trigger AI comment generation on a post as a specific persona. " +
        "The comment is generated server-side by the Boswell plugin using wp-ai-client.",
      inputSchema: z.object({
        site: SiteParam,
        persona_id: z.string().min(1).describe("Persona ID to comment as"),
        post_id: z.number().int().positive().describe("Post ID to comment on"),
        parent_id: z
          .number()
          .int()
          .optional()
          .default(0)
          .describe("Parent comment ID for replies (0 = top-level)"),
      }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const result = await wpFetch<BoswellComment>(
          site,
          "/boswell/v1/comment",
          {
            method: "POST",
            body: JSON.stringify({
              persona_id: params.persona_id,
              post_id: params.post_id,
              parent_id: params.parent_id,
            }),
          }
        );

        const text = [
          `Comment #${result.comment_id} posted by ${result.author}`,
          `  Post ID: ${result.post_id}`,
          "",
          result.content,
        ].join("\n");

        return { content: [{ type: "text", text }] };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  // ─── Get Memory ─────────────────────────────────────────────────
  server.registerTool(
    "boswell_get_memory",
    {
      title: "Get Boswell Memory",
      description: "Retrieve Boswell's shared memory (Markdown format).",
      inputSchema: z.object({ site: SiteParam }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const mem = await wpFetch<BoswellMemory>(site, "/boswell/v1/memory");

        const text = [
          `# Boswell Memory`,
          `Updated: ${mem.updated_at}`,
          `Sections: ${mem.sections.join(", ")}`,
          "",
          mem.memory,
        ].join("\n");

        return { content: [{ type: "text", text }] };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  // ─── Append Memory Entry ────────────────────────────────────────
  server.registerTool(
    "boswell_add_memory",
    {
      title: "Add Memory Entry",
      description:
        "Append an entry to a memory section. " +
        "Available sections: recent_activities, ongoing_topics, commentary_log, notes.",
      inputSchema: z.object({
        site: SiteParam,
        section: z
          .enum([
            "recent_activities",
            "ongoing_topics",
            "commentary_log",
            "notes",
          ])
          .describe("Memory section key"),
        entry: z.string().min(1).describe("Entry text (date prefix added automatically)"),
      }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const result = await wpFetch<BoswellMemoryEntry>(
          site,
          "/boswell/v1/memory/entry",
          {
            method: "POST",
            body: JSON.stringify({
              section: params.section,
              entry: params.entry,
            }),
          }
        );

        return {
          content: [
            {
              type: "text",
              text: `Entry added to "${result.section}" (updated: ${result.updated_at})`,
            },
          ],
        };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );
}
