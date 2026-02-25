# Boswell

Tags: ai, comments, persona, mcp, content  
Contributors: hametuha, Takahashi_Fumiki  
Tested Up to: 6.9  
Stable Tag: 0.1.0  
Requires at least: 6.9  
Requires PHP: 8.1  
License: GPLv3 or later  
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI-powered commenting and content management for WordPress.

## Description

Boswell enriches your WordPress blog with AI-powered personas that can comment on posts, manage shared memory, and create content — all through the WordPress Abilities API and MCP (Model Context Protocol).

### Features

- **AI Personas** — Define multiple AI personas with unique writing styles and personalities. Each persona generates comments that match their character.
- **Shared Memory** — A Markdown-based memory system that personas share, maintaining context across interactions.
- **Automated Commenting** — Schedule personas to comment on posts via WordPress cron, or trigger comments manually.
- **MCP Integration** — Expose all capabilities as MCP tools via the WordPress MCP Adapter. Connect Claude Desktop or any MCP client directly to your site.
- **Post Management** — Create, update, list, and delete posts through MCP tools.
- **WP-CLI Support** — Manage personas, memory, and comments from the command line.

### How It Works

Boswell registers its capabilities through the [WordPress Abilities API](https://make.wordpress.org/core/tag/abilities-api/) (available in WordPress 6.9+). The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin exposes these abilities as MCP tools, resources, and prompts.

```
Claude Desktop ←MCP→ WordPress (MCP Adapter)
                         ├── boswell/comment       (tool)
                         ├── boswell/get-context    (tool)
                         ├── boswell/get-memory     (tool)
                         ├── boswell/add-memory     (tool)
                         ├── boswell/create-post    (tool)
                         ├── boswell/update-post    (tool)
                         ├── boswell/list-posts     (tool)
                         ├── boswell/delete-post    (tool)
                         ├── boswell/context        (resource)
                         └── boswell/write-for-site (prompt)
```

### AI Providers

Boswell uses [wp-ai-client](https://github.com/WordPress/wp-ai-client) for AI text generation. You need at least one AI provider plugin installed:

- [AI Provider for Anthropic](https://github.com/WordPress/ai-provider-for-anthropic) (bundled)
- [AI Provider for OpenAI](https://github.com/WordPress/openai-ai-provider) (optional)
- [AI Provider for Google](https://github.com/WordPress/google-ai-provider) (optional)

Since WordPress 7.0, these extensions above will not be bundled.

## Installation

1. Download the latest release zip from [GitHub Releases](https://github.com/hametuha/boswell/releases).
2. Upload the zip file via **Plugins > Add New > Upload Plugin** in WordPress admin.
3. Activate the plugin.
4. Go to **Settings > Boswell** to configure personas.
5. Set up your AI provider API key (e.g., `ANTHROPIC_API_KEY` constant or environment variable).

### MCP Connection

To connect Claude Desktop to your WordPress site, add the MCP server to your Claude Desktop configuration:

```json
{
  "mcpServers": {
    "boswell": {
      "command": "npx",
      "args": [
        "-y",
        "@anthropic-ai/mcp-remote",
        "https://your-site.com/wp-json/mcp/mcp-adapter-default-server"
      ]
    }
  }
}
```

## Frequently Asked Questions

### What WordPress version is required?

WordPress 6.9 or later is required for the Abilities API.

### Can I use multiple AI providers?

Yes. Install additional AI provider plugins (OpenAI, Google) alongside the bundled Anthropic provider. WordPress will use whichever is configured.

### How do I create a persona?

Go to **Settings > Boswell** in the WordPress admin. Each persona needs an ID, display name, and a persona description that defines their writing style.

### Can personas comment automatically?

Yes. Each persona can have a cron schedule (e.g., daily). Boswell will automatically find eligible posts and generate comments.

### How do I customize comment strategies?

See the [Wiki](https://github.com/hametuha/boswell/wiki) for details on adding custom strategies, adjusting query parameters, enriching post context, and blocking comments on specific posts or categories.

## Changelog

### 0.1.0

- Initial release.
- AI persona management with admin UI.
- Shared memory system with section-based Markdown storage.
- Automated commenting via WordPress cron.
- MCP integration via WordPress Abilities API.
- Post CRUD tools (create, update, list, delete).
- WP-CLI commands for personas, memory, and comments.
