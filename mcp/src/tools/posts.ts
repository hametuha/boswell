/**
 * WordPress post management tools.
 *
 * Wraps wp/v2/posts endpoints for create, update, and list operations.
 */

import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { findSite, getSiteNames, type SiteConfig } from "../config.js";
import { wpFetch } from "../client.js";

interface WPPost {
  id: number;
  status: string;
  title: { rendered: string };
  link: string;
}

const SiteParam = z
  .string()
  .min(1)
  .describe("Target site name or URL fragment (partial match supported)");

function resolveSite(query: string): SiteConfig | string {
  const site = findSite(query);
  if (!site) {
    const available = getSiteNames();
    return `Site "${query}" not found. Available: ${available.join(", ") || "(none)"}`;
  }
  return site;
}

function errorResponse(message: string) {
  return {
    content: [{ type: "text" as const, text: `Error: ${message}` }],
    isError: true,
  };
}

export function registerPostTools(server: McpServer): void {
  // ─── Create Draft ───────────────────────────────────────────────
  server.registerTool(
    "wp_create_draft",
    {
      title: "Create WordPress Draft",
      description:
        "Create a new post on a WordPress site. " +
        "Call boswell_get_context first to get writing guidelines.",
      inputSchema: z.object({
        site: SiteParam,
        title: z.string().min(1).describe("Post title"),
        content: z.string().min(1).describe("Post content (HTML)"),
        excerpt: z.string().optional().describe("Post excerpt"),
        categories: z
          .array(z.number().int().positive())
          .optional()
          .describe("Category IDs"),
        tags: z
          .array(z.number().int().positive())
          .optional()
          .describe("Tag IDs"),
        status: z
          .enum(["draft", "publish", "pending", "private"])
          .optional()
          .describe("Post status (defaults to site's default_status)"),
      }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const post = await wpFetch<WPPost>(site, "/wp/v2/posts", {
          method: "POST",
          body: JSON.stringify({
            title: params.title,
            content: params.content,
            excerpt: params.excerpt || "",
            status: params.status || site.default_status,
            categories: params.categories || [],
            tags: params.tags || [],
          }),
        });

        const editUrl = `${site.url}/wp-admin/post.php?post=${post.id}&action=edit`;
        const text = [
          `Draft created on ${site.name}`,
          `  Post ID: ${post.id}`,
          `  Title: ${post.title.rendered}`,
          `  Status: ${post.status}`,
          `  Edit: ${editUrl}`,
          `  View: ${post.link}`,
        ].join("\n");

        return { content: [{ type: "text", text }] };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  // ─── Update Post ────────────────────────────────────────────────
  server.registerTool(
    "wp_update_post",
    {
      title: "Update WordPress Post",
      description: "Update an existing post. Specify site, post ID, and fields to update.",
      inputSchema: z.object({
        site: SiteParam,
        post_id: z.number().int().positive().describe("Post ID to update"),
        title: z.string().optional().describe("New title"),
        content: z.string().optional().describe("New content (HTML)"),
        excerpt: z.string().optional().describe("New excerpt"),
        status: z
          .enum(["draft", "publish", "pending", "private"])
          .optional()
          .describe("New status"),
        categories: z.array(z.number().int().positive()).optional(),
        tags: z.array(z.number().int().positive()).optional(),
      }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const { site: _, post_id, ...fields } = params;
        const post = await wpFetch<WPPost>(site, `/wp/v2/posts/${post_id}`, {
          method: "POST",
          body: JSON.stringify(fields),
        });

        const editUrl = `${site.url}/wp-admin/post.php?post=${post.id}&action=edit`;
        const text = [
          `Post updated on ${site.name}`,
          `  Post ID: ${post.id}`,
          `  Title: ${post.title.rendered}`,
          `  Status: ${post.status}`,
          `  Edit: ${editUrl}`,
        ].join("\n");

        return { content: [{ type: "text", text }] };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );

  // ─── List Posts ─────────────────────────────────────────────────
  server.registerTool(
    "wp_list_posts",
    {
      title: "List WordPress Posts",
      description: "List posts filtered by status. Defaults to drafts.",
      inputSchema: z.object({
        site: SiteParam,
        status: z
          .enum(["draft", "publish", "pending", "private", "any"])
          .optional()
          .default("draft")
          .describe("Filter by status (default: draft)"),
        per_page: z
          .number()
          .int()
          .min(1)
          .max(100)
          .optional()
          .default(10),
        search: z.string().optional().describe("Keyword search"),
      }),
    },
    async (params) => {
      const site = resolveSite(params.site);
      if (typeof site === "string") return errorResponse(site);

      try {
        const query = new URLSearchParams();
        if (params.status && params.status !== "any")
          query.set("status", params.status);
        if (params.per_page) query.set("per_page", String(params.per_page));
        if (params.search) query.set("search", params.search);

        const qs = query.toString();
        const posts = await wpFetch<WPPost[]>(
          site,
          `/wp/v2/posts${qs ? `?${qs}` : ""}`
        );

        if (posts.length === 0) {
          return {
            content: [
              {
                type: "text",
                text: `No posts found on ${site.name} with status="${params.status}"`,
              },
            ],
          };
        }

        const lines = posts.map((p) => {
          const editUrl = `${site.url}/wp-admin/post.php?post=${p.id}&action=edit`;
          return `- [${p.status}] #${p.id}: ${p.title.rendered}\n  ${editUrl}`;
        });

        return {
          content: [
            {
              type: "text",
              text: `Posts on ${site.name} (${posts.length}):\n\n${lines.join("\n")}`,
            },
          ],
        };
      } catch (err) {
        return errorResponse(String(err));
      }
    }
  );
}
