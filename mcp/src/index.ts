#!/usr/bin/env node

/**
 * Boswell MCP Bridge
 *
 * Connects Claude Desktop to WordPress via Boswell REST API.
 * Replaces blog-mcp-server with a unified approach:
 *  - Personas & memory managed by Boswell plugin (WordPress)
 *  - Post CRUD via WordPress core REST API (wp/v2)
 *  - AI commenting triggered via Boswell REST API (boswell/v1)
 *
 * Transport: stdio (for Claude Desktop)
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { loadSites, findSite, getSiteNames } from "./config.js";
import { wpFetch } from "./client.js";
import { registerPostTools } from "./tools/posts.js";
import { registerTaxonomyTools } from "./tools/taxonomy.js";
import { registerBoswellTools } from "./tools/boswell.js";

// ─── Server ───────────────────────────────────────────────────────

const server = new McpServer({
  name: "boswell-mcp",
  version: "0.1.0",
});

// ─── Tools ────────────────────────────────────────────────────────

registerPostTools(server);
registerTaxonomyTools(server);
registerBoswellTools(server);

// ─── Prompts ──────────────────────────────────────────────────────

interface BoswellContext {
  site_name: string;
  site_url: string;
  personas: Array<{ id: string; name: string; persona: string }>;
  memory: string;
}

server.registerPrompt(
  "write_for_site",
  {
    title: "Write for a WordPress site",
    description:
      "Loads the site's personas and memory as writing context. " +
      "Use before drafting content to match the site's tone and style.",
    argsSchema: {
      site: z.string().describe("Site name or URL fragment"),
      persona: z
        .string()
        .optional()
        .describe("Persona ID (uses first persona if omitted)"),
      topic: z
        .string()
        .optional()
        .describe("Topic or theme for the post"),
    },
  },
  async ({ site, persona, topic }) => {
    const siteConfig = findSite(site);

    if (!siteConfig) {
      const available = getSiteNames();
      return {
        messages: [
          {
            role: "user",
            content: {
              type: "text",
              text: `Site "${site}" not found. Available: ${available.join(", ")}`,
            },
          },
        ],
      };
    }

    try {
      const ctx = await wpFetch<BoswellContext>(
        siteConfig,
        "/boswell/v1/context"
      );

      // Find specific persona or use first one.
      const target = persona
        ? ctx.personas.find((p) => p.id === persona)
        : ctx.personas[0];

      const parts: string[] = [];

      if (target) {
        parts.push(target.persona);
      } else if (persona) {
        parts.push(
          `Persona "${persona}" not found. Available: ${ctx.personas.map((p) => p.id).join(", ")}`
        );
      }

      if (ctx.memory) {
        parts.push("", "---", "", "## Your Memory", "", ctx.memory);
      }

      if (topic) {
        parts.push("", "---", "", `## Topic`, "", topic);
      }

      parts.push(
        "",
        "---",
        "",
        "Write content following these guidelines. " +
          "The content will be posted as HTML to WordPress, " +
          "so use appropriate HTML tags (h2, h3, p, pre, code, ul, ol, etc.)."
      );

      return {
        messages: [
          {
            role: "user",
            content: { type: "text", text: parts.join("\n") },
          },
        ],
      };
    } catch (err) {
      return {
        messages: [
          {
            role: "user",
            content: {
              type: "text",
              text: `Failed to load context: ${String(err)}`,
            },
          },
        ],
      };
    }
  }
);

// ─── Resources ────────────────────────────────────────────────────

server.registerResource(
  "wordpress_sites",
  "wp://sites",
  {
    description: "List of configured WordPress sites (credentials excluded)",
    mimeType: "application/json",
  },
  async () => {
    const sites = loadSites();
    const safeList = sites.map((s) => ({
      name: s.name,
      url: s.url,
      default_status: s.default_status,
    }));

    return {
      contents: [
        {
          uri: "wp://sites",
          mimeType: "application/json",
          text: JSON.stringify(safeList, null, 2),
        },
      ],
    };
  }
);

// ─── Start ────────────────────────────────────────────────────────

async function main(): Promise<void> {
  const sites = loadSites();
  if (sites.length === 0) {
    console.error(
      "[boswell-mcp] Warning: No sites configured. " +
        "Create sites.json to get started."
    );
  } else {
    console.error(
      `[boswell-mcp] Loaded ${sites.length} site(s): ${getSiteNames().join(", ")}`
    );
  }

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("[boswell-mcp] Server started (stdio transport)");

  const shutdown = async () => {
    console.error("[boswell-mcp] Shutting down...");
    await server.close();
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

main().catch((err) => {
  console.error("[boswell-mcp] Fatal error:", err);
  process.exit(1);
});
