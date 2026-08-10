# Changelog

## 1.10.0 — 2026-08-10

- Added: the WP Abilities admin page now flags what's new or no longer registered since your last visit. A snapshot of the ability list is saved to a site option (`mswpa_abilities_snapshot`) on each visit and diffed against the previous one; new/removed abilities are listed in a notice at the top of the page. First-ever visit just records the baseline silently, no false "everything is new" notice.

## 1.9.2 — 2026-08-10

- Changed: the WP Abilities admin page's Category column now shows each ability's registered category *label* (e.g. "MS WordPress Abilities") instead of its raw slug (e.g. "miriamschwab"), falling back to the slug for any category without a registered label. The Category filter dropdown already did this; the table column now matches.
- Fixed: the "MCP Public" table header no longer wraps onto two lines.

## 1.9.1 — 2026-08-10

- Changed: the `miriamschwab` ability category's label and description were tied to this author's own site ("Miriam Schwab Site" / "Abilities for miriamschwab.me content management"), which would be inaccurate if this plugin is installed elsewhere. Renamed to "MS WordPress Abilities" / "Abilities registered by the MS WordPress Abilities plugin for AI agent site management" — matching the naming convention this author's other plugins use (category named after the plugin, not the site). The category *slug* (`miriamschwab`) is unchanged. No functional or behavior change.

## 1.9.0 — 2026-08-10

- Added: "WP Abilities" admin page under Tools — browse all registered abilities with filters for category, namespace, and free-text search, plus a per-ability MCP-public flag and expandable input schema. Uses the `wp_get_abilities()` filtering added in WordPress 7.1 when available, with a manual-filter fallback on 6.9/7.0.

## 1.8.2

- Fixed: the get-site-settings ability now reports "comments_open" correctly (it previously always returned false). Hardened the install-plugin flow against an unexpected API response. WordPress coding-standards cleanup.

## 1.8.1

- Changed: clarified the `rest-get` and `rest-write` ability descriptions to tell agents to try a plugin's own REST namespace (guessed from its slug, e.g. `angie/angie.php` → `/angie/v1`) before concluding a feature isn't supported or asking the user to clarify, and to query that namespace directly rather than the global REST index (which can be hundreds of KB and exceed output limits). No behavior change — description text only.

## 1.8.0

- Added: `miriamschwab/rest-get` — call any registered REST route (core or third-party plugin namespace) with GET only. Read-only bridge for anything not covered by a dedicated ability: comments, custom post types, other plugins' data (WooCommerce, Gravity Forms, etc.).
- Added: `miriamschwab/rest-write` — call any registered REST route with POST/PUT/PATCH/DELETE. Agent states the exact request and waits for explicit user confirmation before every call. Hard-blocked in code regardless of confirmation: writes to `/wp/v2/users`, `DELETE` on `/wp/v2/plugins`, writes to `/wp/v2/settings`, and any `force=true` (permanent delete bypassing trash).

## 1.7.0

- Removed the hard-coded 30-second minimum delay and confirmation-gate error between `preview-post-update` and `apply-post-update`. Superseded by a conversational confirmation policy: the agent always states the exact proposed change and waits for explicit user approval before calling apply, regardless of ability. The preview step still stages a before/after diff and expires after 10 minutes if unused.

## 1.6.1

- Removed: `miriamschwab/get-post-content`, added in 1.6.0 — found to duplicate the pre-existing `ai/get-post-details` ability (also exposed on this MCP server), which already returns raw post content by ID for any post status.

## 1.6.0

- Added: `miriamschwab/get-post-content` — read the raw, unmodified post_content for a given post, for any post status.

## 1.5.2

- Fixed: plugin header now includes all required fields.

## 1.5.1

- Added: `miriamschwab/get-available-updates` — check pending plugin, theme, and core updates.
- Added: `miriamschwab/update-plugin` and `miriamschwab/update-theme` — apply updates from the agent.

## 1.5.0

- Added: `miriamschwab/install-plugin` — install from WordPress.org plugin directory.
- Added: `miriamschwab/activate-plugin` — activate an installed plugin by file path.
- Added: `miriamschwab/get-plugins` — list installed plugins with active status and update info.
- Added: `miriamschwab/get-themes` — list installed themes with active status and update info.

## 1.4.2

- Fixed: `miriamschwab/preview-post-update` now supports `meta` key-value pairs alongside standard post fields.
- Fixed: `miriamschwab/apply-post-update` correctly applies staged meta changes.

## 1.0.0

- Initial release.
