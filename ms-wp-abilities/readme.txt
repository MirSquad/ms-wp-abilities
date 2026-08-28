=== MS WordPress Abilities ===
Contributors: miriamschwab
Tags: ai, mcp, abilities, rest-api, agents
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registers WordPress abilities for MCP Adapter access, enabling AI agents to manage posts, pages, media, plugins, themes, and site settings.

== Description ==

MS WordPress Abilities registers a comprehensive set of WordPress abilities using the WordPress 6.9 Abilities API. These abilities are exposed via the MCP Adapter plugin, allowing AI agents (such as Claude in Angie) to interact with this site's content and configuration through a structured, permission-checked interface.

**Abilities included**

26 custom abilities, plus 3 WordPress core abilities exposed for MCP access (`get-site-info`, `get-user-info`, `get-environment-info`).

Posts and pages:

* `get-posts` / `get-pages` — retrieve with filtering by status, post type, category, tag, or search
* `get-post-meta` — read post meta, all fields or specific keys
* `get-post-types` — list registered public post types
* `create-post` — create from markdown (converted to Gutenberg blocks server-side) or raw HTML, with optional meta (e.g. Yoast fields)
* `preview-post-update` / `apply-post-update` — two-step staged update (title, content, excerpt, status, categories, tags, meta) gated by a minimum confirmation delay
* `patch-post-content` — surgical find-and-replace on post content, no staging required
* `trash-post` — move a post to trash

Taxonomy:

* `get-categories` / `get-tags` — list terms
* `create-term` — create a category or tag

Media:

* `get-media` — list media items
* `update-media-meta` — update alt text, title, caption, or description

Users:

* `get-users` — list users with role filtering

Site:

* `get-site-settings` — site configuration
* `get-menus` — navigation menus and their items

Plugins:

* `get-plugins` — list installed plugins with active/update status
* `install-plugin` / `activate-plugin` / `update-plugin`

Themes:

* `get-themes` / `update-theme`

Updates:

* `get-available-updates` — pending plugin, theme, and core updates

REST bridge:

* `rest-get` — call any registered REST route (core or a third-party plugin's namespace) with GET only. Fills gaps not covered by a dedicated ability: comments, custom post types, other plugins' data.
* `rest-write` — call any registered REST route with POST/PUT/PATCH/DELETE. Hard-blocked in code regardless of confirmation: writes to `/wp/v2/users`, `DELETE` on `/wp/v2/plugins`, writes to `/wp/v2/settings`, and any `force=true` (permanent delete bypassing trash).

**Admin UI**

**Tools > WP Abilities** lists every ability registered on the site — from this plugin or any other — with filters for category, namespace, and free-text search, a per-ability MCP-public flag, and an expandable input schema. It also flags what's new or no longer registered since your last visit to the page, so you can spot abilities added by a plugin update without hunting through changelogs.

**Safety**

Post content updates use a two-step preview/confirm model: changes are staged by `preview-post-update` and committed by `apply-post-update`. The agent is expected to state the exact proposed change in plain language and wait for explicit user approval before calling apply — this is a conversational policy the agent follows, not a timed delay enforced by the code.

The REST bridge follows the same policy for any write: state the request, wait for approval, then call `rest-write`. A short list of especially destructive routes is blocked in code regardless of approval — see `rest-write` above.

**Requirements**

* WordPress 6.9 or higher (Abilities API required)
* MCP Adapter plugin

== Installation ==

1. Upload the `ms-wp-abilities` folder to `/wp-content/plugins/`.
2. Activate from **Plugins > Installed Plugins**.
3. Make sure the MCP Adapter plugin is also active.

== Frequently Asked Questions ==

= What WordPress version is required? =

WordPress 6.9 or higher. This plugin uses the `wp_register_ability()` API introduced in 6.9.

= What capabilities do the abilities require? =

Each ability checks the appropriate WordPress capability: `edit_posts` for post reads and writes, `delete_posts` for trashing, `upload_files` for media, `manage_categories` for taxonomy, `list_users` for user listing, `manage_options` for site settings, `activate_plugins` / `install_plugins` / `update_plugins` for plugin management, and `switch_themes` / `update_themes` for theme management.

= Does the plugin store any data? =

Yes, two things. When a post update is staged via preview-post-update, the pending update is stored temporarily in user meta under `_mswpa_pending_update_{post_id}`; it expires after 10 minutes and is consumed on apply. The WP Abilities admin page (Tools > WP Abilities) also stores a snapshot of the currently-registered ability list in a site option, `mswpa_abilities_snapshot`, so it can flag what's new or no longer registered since your last visit to that page. Both are removed on uninstall.

= What is the site-specific category? =

Abilities are registered under the `miriamschwab` category, which is specific to the miriamschwab.me site. These abilities are designed for single-site use and include site-specific post type awareness.

== Changelog ==

= 1.10.2 - 2026-08-28 =
* Changed: documented why the `wp_register_ability_args` filter that exposes the three WordPress core abilities to MCP is kept. On WordPress 7.1 with MCP Adapter 0.6.0+ it is redundant — core sets a high-level `meta.public` flag on those abilities and the adapter inherits it when `meta.mcp.public` is absent — but WordPress 6.9/7.0 do not set that flag and MCP Adapter before 0.6.0 reads only `meta.mcp.public`, so the filter is still required across this plugin's declared requirements. No functional change.
* Changed: Tested up to WordPress 7.1.

= 1.10.1 - 2026-08-12 =
* Fixed (security): the rest-write hard-block guard could be bypassed by varying the case of the `force` parameter. Body keys are normalized with `sanitize_key()` before dispatch, which lowercases them — but the guard checked the raw keys, so `{"Force": true}` passed the check and still reached the endpoint as `force`, performing a permanent delete that bypasses trash. Body keys are now normalized once, before the check, and that same normalized array is what gets dispatched.
* Fixed (security): the `/wp/v2/users`, `/wp/v2/plugins` and `/wp/v2/settings` route blocks were matched case-sensitively, while WordPress matches registered REST routes case-insensitively. A route such as `/wp/v2/Users/5` therefore skipped the block list and still reached the users endpoint. The block patterns are now case-insensitive, matching core's own route-matching behavior.
* Changed: the hard-block guard moved to `includes/rest-write-guard.php` so it can be unit-tested on its own. No behavior change beyond the two fixes above.

= 1.10.0 - 2026-08-10 =
* Added: the WP Abilities admin page now flags what's new or no longer registered since your last visit. A snapshot of the ability list is saved to a site option (`mswpa_abilities_snapshot`) on each visit and diffed against the previous one; new/removed abilities are listed in a notice at the top of the page. First-ever visit just records the baseline silently, no false "everything is new" notice.

= 1.9.2 - 2026-08-10 =
* Changed: the WP Abilities admin page's Category column now shows each ability's registered category *label* (e.g. "MS WordPress Abilities") instead of its raw slug (e.g. "miriamschwab"), falling back to the slug for any category without a registered label. The Category filter dropdown already did this; the table column now matches.
* Fixed: the "MCP Public" table header no longer wraps onto two lines.

= 1.9.1 - 2026-08-10 =
* Changed: the `miriamschwab` ability category's label and description were tied to this author's own site ("Miriam Schwab Site" / "Abilities for miriamschwab.me content management"), which would be inaccurate if this plugin is installed elsewhere. Renamed to "MS WordPress Abilities" / "Abilities registered by the MS WordPress Abilities plugin for AI agent site management" — matching the naming convention this author's other plugins use (category named after the plugin, not the site). The category *slug* (`miriamschwab`) is unchanged. No functional or behavior change.

= 1.9.0 - 2026-08-10 =
* Added: "WP Abilities" admin page under Tools — browse all registered abilities with filters for category, namespace, and free-text search, plus a per-ability MCP-public flag and expandable input schema. Uses the wp_get_abilities() filtering added in WordPress 7.1 when available, with a manual-filter fallback on 6.9/7.0.

= 1.8.2 =
* Fixed: the get-site-settings ability now reports "comments_open" correctly (it previously always returned false). Hardened the install-plugin flow against an unexpected API response. WordPress coding-standards cleanup.

= 1.8.1 =
* Changed: clarified the `rest-get` and `rest-write` ability descriptions to tell agents to try a plugin's own REST namespace (guessed from its slug, e.g. `angie/angie.php` → `/angie/v1`) before concluding a feature isn't supported or asking the user to clarify, and to query that namespace directly rather than the global REST index (which can be hundreds of KB and exceed output limits). No behavior change — description text only.

= 1.8.0 =
* Added: `miriamschwab/rest-get` — call any registered REST route (core or third-party plugin namespace) with GET only. Read-only bridge for anything not covered by a dedicated ability: comments, custom post types, other plugins' data (WooCommerce, Gravity Forms, etc.).
* Added: `miriamschwab/rest-write` — call any registered REST route with POST/PUT/PATCH/DELETE. Agent states the exact request and waits for explicit user confirmation before every call. Hard-blocked in code regardless of confirmation: writes to `/wp/v2/users`, `DELETE` on `/wp/v2/plugins`, writes to `/wp/v2/settings`, and any `force=true` (permanent delete bypassing trash).

= 1.7.0 =
* Removed the hard-coded 30-second minimum delay and confirmation-gate error between `preview-post-update` and `apply-post-update`. Superseded by a conversational confirmation policy: the agent always states the exact proposed change and waits for explicit user approval before calling apply, regardless of ability. The preview step still stages a before/after diff and expires after 10 minutes if unused.

= 1.6.1 =
* Removed: `miriamschwab/get-post-content`, added in 1.6.0 — found to duplicate the pre-existing `ai/get-post-details` ability (also exposed on this MCP server), which already returns raw post content by ID for any post status.

= 1.6.0 =
* Added: `miriamschwab/get-post-content` — read the raw, unmodified post_content for a given post, for any post status.

= 1.5.2 =
* Fixed: plugin header now includes all required fields.

= 1.5.1 =
* Added: `miriamschwab/get-available-updates` — check pending plugin, theme, and core updates.
* Added: `miriamschwab/update-plugin` and `miriamschwab/update-theme` — apply updates from the agent.

= 1.5.0 =
* Added: `miriamschwab/install-plugin` — install from WordPress.org plugin directory.
* Added: `miriamschwab/activate-plugin` — activate an installed plugin by file path.
* Added: `miriamschwab/get-plugins` — list installed plugins with active status and update info.
* Added: `miriamschwab/get-themes` — list installed themes with active status and update info.

= 1.4.2 =
* Fixed: `miriamschwab/preview-post-update` now supports `meta` key-value pairs alongside standard post fields.
* Fixed: `miriamschwab/apply-post-update` correctly applies staged meta changes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.6.1 =
Removes the redundant `get-post-content` ability from 1.6.0. No breaking changes — use `ai/get-post-details` (fields: content) instead, if that ability is available on your site.

= 1.6.0 =
Adds `get-post-content` ability. No breaking changes.

= 1.5.2 =
Header-only fix — no functional changes.

= 1.5.0 =
Adds plugin and theme management abilities. No breaking changes.
