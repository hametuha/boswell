/**
 * Configuration loader for Boswell MCP Bridge.
 *
 * Loads site configurations from a JSON file.
 * Path resolution:
 *   1. BOSWELL_SITES_PATH environment variable
 *   2. Default: ~/.config/boswell-mcp/sites.json
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { homedir } from "node:os";

/** WordPress site configuration (auth only — personas live in Boswell). */
export interface SiteConfig {
  name: string;
  url: string;
  username: string;
  application_password: string;
  default_status: string;
}

const DEFAULT_CONFIG_PATH = resolve(
  homedir(),
  ".config",
  "boswell-mcp",
  "sites.json"
);

let cachedSites: SiteConfig[] | null = null;

/**
 * Load and validate site configurations from JSON file.
 */
export function loadSites(): SiteConfig[] {
  if (cachedSites) return cachedSites;

  const configPath = process.env.BOSWELL_SITES_PATH || DEFAULT_CONFIG_PATH;

  try {
    const raw = readFileSync(configPath, "utf-8");
    const parsed: unknown = JSON.parse(raw);

    if (!Array.isArray(parsed)) {
      throw new Error("sites.json must be an array of site configurations");
    }

    const sites = parsed as SiteConfig[];

    for (const site of sites) {
      if (
        !site.name ||
        !site.url ||
        !site.username ||
        !site.application_password
      ) {
        throw new Error(
          `Invalid site config for "${site.name || "unknown"}": ` +
            "name, url, username, and application_password are required"
        );
      }
      site.url = site.url.replace(/\/+$/, "");
      site.default_status = site.default_status || "draft";
    }

    cachedSites = sites;
    return sites;
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === "ENOENT") {
      console.error(
        `[boswell-mcp] Config file not found: ${configPath}\n` +
          `Create it from sites.example.json or set BOSWELL_SITES_PATH env var.`
      );
      return [];
    }
    throw err;
  }
}

/**
 * Find a site by name (partial match supported).
 */
export function findSite(query: string): SiteConfig | undefined {
  const sites = loadSites();
  const lower = query.toLowerCase();

  const exact = sites.find((s) => s.name.toLowerCase() === lower);
  if (exact) return exact;

  const partial = sites.filter((s) => s.name.toLowerCase().includes(lower));
  if (partial.length === 1) return partial[0];

  return sites.find((s) => s.url.toLowerCase().includes(lower));
}

/**
 * Get list of all configured site names.
 */
export function getSiteNames(): string[] {
  return loadSites().map((s) => s.name);
}
