# Changelog

## 1.11.1 — 2026-08-30

- Fixed: the write audit trail added in 1.11.0 recorded *that* a plugin was activated or updated, and that a media item's metadata changed, without recording *which* one. The list of input fields whose values are safe to record was written from assumption rather than from the abilities' actual input schemas, and the write abilities do not share a naming convention for their identifier — `update-media-meta` takes `ID` and `activate-plugin`/`update-plugin` take `plugin_file`, neither of which was on the list, while an `attachment_id` field no ability accepts was on it. Those three are corrected. Entries written by 1.11.0 are unaffected and still readable; they simply lack the identifier for those abilities.
- Changed: added the test that should have caught the above. Every required parameter of every write ability must now be classified as either an identifier worth recording or content that must never be stored, every write ability must record at least one identifier, and the list may not name fields no ability accepts. Suite grows to 94 tests. Registration parsing moved into the test bootstrap so the audit-log and policy-table drift guards read the registrations through one parser.
- Changed: CI workflows use `actions/checkout@v5`; v4 runs on Node.js 20, which GitHub Actions has deprecated. No effect on the plugin.

## 1.11.0 — 2026-08-30

- Added: an audit trail of write-ability invocations, shown under Tools > WP Abilities. Built on the `wp_ability_invoked` action added in WordPress 7.1, which fires before validation and before the permission check — so calls that the rest-write hard-block guard refused, or that failed their permission check, are recorded too. Those previously left no trace anywhere. Read abilities are not recorded. Stored in the `mswpa_write_log` site option, capped at 100 entries; values are kept only for identifying fields, never for post content, REST bodies or meta values. Requires WordPress 7.1; on 6.9 and 7.0 the section reports no activity.
- Added: an optional `fields` parameter on get-posts, get-pages and get-media, following the convention WordPress 7.1 introduced on `core/get-user-info` and `core/get-environment-info`. Requesting a subset keeps large result sets from consuming an agent's context window, and skips the per-row lookups (categories, tags, featured image, permalinks, attachment metadata) whose results would only be discarded. The ID is always returned so results stay actionable.
- Changed (hardening): every ability this plugin registers is now explicitly marked `show_in_rest => false`. WordPress 7.1 resolves REST exposure as `meta.show_in_rest ?? meta.public ?? false`, so the high-level `meta.public` flag turns on the core abilities REST API unless an explicit value overrides it. These abilities already resolved to false, but only because `meta.public` happened to be absent. They are designed for the MCP conversational channel, where the agent confirms writes with the user before calling; the REST run endpoint has no such step. Forcing the flag in a registration filter also covers abilities added later. MCP discovery is unaffected — the adapter reads `meta.mcp.public` and `meta.public`, never `show_in_rest`.
- Changed (hardening): the capability each ability declares is now re-checked centrally after its own `permission_callback` runs, via the `wp_ability_permission_result` filter added in WordPress 7.1. On a correct registration this changes nothing; it exists so an ability whose permission callback is wrong, weakened, or missing cannot execute anyway. A unit test asserts the central table matches the registrations, so the two cannot drift apart.
- Changed (hardening): the rest-write hard-block guard now also runs at input-validation time via the `wp_ability_validate_input` filter added in WordPress 7.1, so a blocked route is refused before the permission check and before any execute-callback work. The copy inside the execute callback remains the enforcing one on WordPress 6.9 and 7.0, where that filter does not exist.
- Fixed: five boolean inputs (`replace_all`, `hide_empty` on get-categories and get-tags, `include_items`, `activate`) were read with truthiness checks, so the string `"false"` — which PHP treats as true — would have been read as true. Real JSON booleans arrive correctly from the MCP adapter, so this was not reachable in normal use, but `activate: "false"` would have activated a plugin. All five now go through `rest_sanitize_boolean()`, the same coercion WordPress 7.1 applies to REST input.

## 1.10.2 — 2026-08-28

- Changed: documented why the `wp_register_ability_args` filter that exposes the three WordPress core abilities to MCP is kept. On WordPress 7.1 with MCP Adapter 0.6.0+ it is redundant — core sets a high-level `meta.public` flag on those abilities and the adapter inherits it when `meta.mcp.public` is absent — but WordPress 6.9/7.0 do not set that flag and MCP Adapter before 0.6.0 reads only `meta.mcp.public`, so the filter is still required across this plugin's declared requirements. No functional change.
- Changed: Tested up to WordPress 7.1.

## 1.10.1 — 2026-08-12

- Fixed (security): the rest-write hard-block guard could be bypassed by varying the case of the `force` parameter. Body keys are normalized with `sanitize_key()` before dispatch, which lowercases them — but the guard checked the raw keys, so `{"Force": true}` passed the check and still reached the endpoint as `force`, performing a permanent delete that bypasses trash. Body keys are now normalized once, before the check, and that same normalized array is what gets dispatched.
- Fixed (security): the `/wp/v2/users`, `/wp/v2/plugins` and `/wp/v2/settings` route blocks were matched case-sensitively, while WordPress matches registered REST routes case-insensitively. A route such as `/wp/v2/Users/5` therefore skipped the block list and still reached the users endpoint. The block patterns are now case-insensitive, matching core's own route-matching behavior.
- Changed: the hard-block guard moved to `includes/rest-write-guard.php` so it can be unit-tested on its own. No behavior change beyond the two fixes above.
- Added: a PHPUnit suite covering the rest-write hard-block guard, including the casing-bypass regressions above, wired into the CI audit workflow so it gates releases. Dev-only — not shipped in the plugin zip.

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
