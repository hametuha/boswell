/**
 * WordPress HTTP client with Basic Auth.
 *
 * Handles authenticated requests to both WP core (wp/v2)
 * and Boswell (boswell/v1) REST endpoints.
 */

import type { SiteConfig } from "./config.js";

/** WordPress REST API error shape. */
interface WPError {
  code: string;
  message: string;
  data?: { status: number };
}

function authHeader(site: SiteConfig): string {
  const credentials = `${site.username}:${site.application_password}`;
  return `Basic ${Buffer.from(credentials).toString("base64")}`;
}

/**
 * Make an authenticated request to a WordPress REST endpoint.
 *
 * @param site - Site configuration.
 * @param path - REST path including namespace (e.g., "/wp/v2/posts").
 * @param options - Fetch options.
 */
export async function wpFetch<T>(
  site: SiteConfig,
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const url = `${site.url}/wp-json${path}`;

  const response = await fetch(url, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      Authorization: authHeader(site),
      ...options.headers,
    },
  });

  const body = (await response.json()) as T | WPError;

  if (!response.ok) {
    const wpError = body as WPError;
    throw new Error(
      `WordPress API error (${response.status}): ${wpError.message || response.statusText}\n` +
        `Endpoint: ${options.method || "GET"} ${path}\n` +
        (wpError.code ? `Code: ${wpError.code}` : "")
    );
  }

  return body as T;
}
