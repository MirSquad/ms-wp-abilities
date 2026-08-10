# MS WordPress Abilities

Registers a comprehensive set of WordPress abilities — using the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) introduced in WP 6.9 — and exposes them via the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, so an MCP-speaking AI agent (Claude Desktop, Claude Code, or anything else that can connect to an MCP server) can read and manage a WordPress site's content and configuration through a structured, permission-checked interface.

## Why

WordPress's Abilities API gives plugins a standard way to register discrete, permission-checked capabilities. MCP Adapter turns those into MCP tools any compatible AI client can call. But core and the official [AI plugin](https://github.com/WordPress/ai) only ship a handful of abilities out of the box — this plugin fills in the rest: posts and pages, taxonomy, media, users, site settings, plugin/theme management, and a generic REST API bridge for anything not covered by a dedicated ability.

## Abilities included

29 abilities total: 26 custom, plus 3 WordPress core abilities (`get-site-info`, `get-user-info`, `get-environment-info`) explicitly opted into MCP visibility.

**Posts and pages**
- `get-posts` / `get-pages` — retrieve with filtering by status, post type, category, tag, or search
- `get-post-meta` — read post meta, all fields or specific keys
- `get-post-types` — list registered public post types
- `create-post` — create from markdown (converted to Gutenberg blocks server-side) or raw HTML, with optional meta (e.g. Yoast fields)
- `preview-post-update` / `apply-post-update` — two-step staged update (title, content, excerpt, status, categories, tags, meta)
- `patch-post-content` — surgical find-and-replace on post content, no staging required
- `trash-post` — move a post to trash

**Taxonomy**
- `get-categories` / `get-tags` — list terms
- `create-term` — create a category or tag

**Media**
- `get-media` — list media items
- `update-media-meta` — update alt text, title, caption, or description

**Users**
- `get-users` — list users with role filtering

**Site**
- `get-site-settings` — site configuration
- `get-menus` — navigation menus and their items

**Plugins**
- `get-plugins` — list installed plugins with active/update status
- `install-plugin` / `activate-plugin` / `update-plugin`

**Themes**
- `get-themes` / `update-theme`

**Updates**
- `get-available-updates` — pending plugin, theme, and core updates

**REST bridge**
- `rest-get` — call any registered REST route (core or a third-party plugin's own namespace) with GET only
- `rest-write` — call any registered REST route with POST/PUT/PATCH/DELETE. Hard-blocked in code regardless of confirmation: writes to `/wp/v2/users`, `DELETE` on `/wp/v2/plugins`, writes to `/wp/v2/settings`, and any `force=true` (permanent delete bypassing trash)

## Admin UI

**Tools > WP Abilities** lists every ability registered on the site — from this plugin or any other — with filters for category, namespace, and free-text search, a per-ability MCP-public flag, and an expandable input schema. It also flags what's new or no longer registered since your last visit to the page, so you can spot abilities a plugin update added without hunting through changelogs.

## Security

This plugin grants real, broad control over the WordPress site to whatever MCP client connects to it — it can create and publish content, install and activate plugins, update themes, and issue arbitrary REST API writes. Before installing:

- **Only connect a trusted MCP client.** Whatever has access to the abilities this plugin registers can act on the site as the authenticated user.
- **Use an application password scoped to a real Administrator account**, not a shared or over-privileged one, and treat it like any other credential.
- **Review the `rest-write` hard-block list** in the source (`mswpa_rest_write_blocked_reason()`) before relying on it as a safety net — it blocks a short list of especially destructive routes (user writes, force-deleting plugins, site settings writes, permanent deletes), not everything that could go wrong.
- Post content updates use a two-step preview/confirm model (`preview-post-update` → `apply-post-update`); the REST bridge follows the same pattern for writes. Both rely on the calling agent stating the proposed change and waiting for the human's explicit approval — this is a conversational policy the agent is instructed to follow, not a mechanism enforced by the code itself.

## Installation

1. Install and activate [MCP Adapter](https://github.com/WordPress/mcp-adapter).
2. Download or clone this repository.
3. Copy the `ms-wp-abilities` folder into `wp-content/plugins/`.
4. Activate the plugin in WordPress.
5. Visit **Tools > WP Abilities** to see everything registered on your site.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Requirements

- WordPress 6.9+ (Abilities API)
- PHP 7.4+
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin

## License

GPL-2.0-or-later
