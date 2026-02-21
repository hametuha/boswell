/**
 * WordPress taxonomy tools (categories & tags).
 */

import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { findSite, getSiteNames, type SiteConfig } from "../config.js";
import { wpFetch } from "../client.js";

interface WPTerm {
  id: number;
  name: string;
  slug: string;
  count: number;
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

const TermsInput = z.object({
  site: SiteParam,
  search: z.string().optional().describe("Keyword filter"),
  per_page: z
    .number()
    .int()
    .min(1)
    .max(100)
    .optional()
    .default(30),
});

export function registerTaxonomyTools(server: McpServer): void {
  server.registerTool(
    "wp_list_categories",
    {
      title: "List WordPress Categories",
      description: "List categories to find IDs before creating a post.",
      inputSchema: TermsInput,
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const query = new URLSearchParams();
        if (params.per_page) query.set("per_page", String(params.per_page));
        if (params.search) query.set("search", params.search);

        const qs = query.toString();
        const terms = await wpFetch<WPTerm[]>(
          site,
          `/wp/v2/categories${qs ? `?${qs}` : ""}`
        );

        const lines = terms.map(
          (t) => `- #${t.id}: ${t.name} (${t.slug}) [${t.count} posts]`
        );
        return {
          content: [
            {
              type: "text",
              text: `Categories on ${site.name}:\n\n${lines.join("\n") || "(none)"}`,
            },
          ],
        };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  server.registerTool(
    "wp_list_tags",
    {
      title: "List WordPress Tags",
      description: "List tags to find IDs before creating a post.",
      inputSchema: TermsInput,
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const query = new URLSearchParams();
        if (params.per_page) query.set("per_page", String(params.per_page));
        if (params.search) query.set("search", params.search);

        const qs = query.toString();
        const terms = await wpFetch<WPTerm[]>(
          site,
          `/wp/v2/tags${qs ? `?${qs}` : ""}`
        );

        const lines = terms.map(
          (t) => `- #${t.id}: ${t.name} (${t.slug}) [${t.count} posts]`
        );
        return {
          content: [
            {
              type: "text",
              text: `Tags on ${site.name}:\n\n${lines.join("\n") || "(none)"}`,
            },
          ],
        };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );
}
