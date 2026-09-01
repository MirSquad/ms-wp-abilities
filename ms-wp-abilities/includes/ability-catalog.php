<?php
/**
 * Ability catalog for the MCP server's instructions.
 *
 * The MCP Adapter's default server exposes three meta-tools; every ability is
 * reached by passing its name to execute-ability. An agent connecting fresh
 * therefore knows three tool names and nothing else, and has to spend a
 * discover-abilities call — often a get-ability-info call too — before it can do
 * anything. This file builds a catalog of the site's MCP-exposed abilities that
 * ms-wp-abilities.php writes into the server's own instructions, so the list is
 * already in front of the agent when the connection opens.
 *
 * Why instructions rather than exposing each ability as its own MCP tool: the
 * flat-tool shape loads a full input schema per ability whether or not it is
 * used. Measured against miriamschwab.me at 67 abilities, that is ~14,000 tokens
 * a session against ~3,450 for name-and-description text. The cheaper shape also
 * keeps the descriptions themselves in front of the agent, which matters here
 * because several of this plugin's descriptions carry usage protocol rather than
 * only identification — rest-write's confirm-before-calling rule, and
 * preview-post-update's preview-then-apply sequence, are both instructions an
 * agent needs before it acts, not after. This is Decision 15's reasoning applied
 * one level up: put the guidance where it travels with the plugin.
 *
 * No WordPress dependencies beyond __(), so it can be unit-tested on its own.
 * Enumerating the registry needs wp_get_abilities() and WP_Ability objects, so
 * that half stays in ms-wp-abilities.php and hands plain arrays to the builder
 * here. See tests/AbilityCatalogTest.php.
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Byte ceiling for the generated catalog.
 *
 * The catalog degrades to a terser form rather than growing without limit,
 * because this plugin ships to other sites. Measured on miriamschwab.me,
 * 65 tool abilities render to 11,249 bytes at full fidelity. The ceiling is set
 * well above that rather than just above it: at a snug budget, adding a handful
 * of abilities anywhere on the site — including from another plugin — would flip
 * the whole catalog down a tier and silently drop the usage protocol carried in
 * the rest-write and preview-post-update descriptions, which is the main reason
 * for listing descriptions at all. 16,000 leaves room for roughly 25-30 more
 * before that happens, while still capping what a much larger site loads into
 * every agent session.
 */
const MSWPA_CATALOG_MAX_CHARS = 16000;

/**
 * Whether an ability's metadata resolves to MCP exposure.
 *
 * Mirrors MCP Adapter 0.6.1's McpAbilityExposure::is_meta_public(): an explicit
 * `meta.mcp.public` wins when present, otherwise the high-level `meta.public`
 * flag WordPress 7.1 sets is consulted, and malformed `meta.mcp` fails closed.
 *
 * The adapter's own implementation is preferred when it is available — it is the
 * authority on what that server will actually expose, and delegating means the
 * catalog cannot drift from it. It is passed in rather than looked up here so
 * this file stays free of adapter dependencies and both branches stay testable.
 * The local copy is not redundant: McpAbilityExposure is `@since 0.6.0` and this
 * plugin declares no adapter floor, so on older adapters it is the only rule
 * available. Same reasoning as decisions-log Decision 20.
 *
 * @param array         $meta     Ability metadata.
 * @param callable|null $delegate Optional resolver taking $meta and returning bool.
 * @return bool True when the ability is exposed over MCP.
 */
function mswpa_ability_is_mcp_public( array $meta, $delegate = null ): bool {
	if ( is_callable( $delegate ) ) {
		return (bool) call_user_func( $delegate, $meta );
	}

	$mcp_meta = $meta['mcp'] ?? array();

	// Fail closed when meta.mcp is malformed rather than falling through to meta.public.
	if ( ! is_array( $mcp_meta ) ) {
		return false;
	}

	if ( isset( $mcp_meta['public'] ) ) {
		return (bool) $mcp_meta['public'];
	}

	return true === ( $meta['public'] ?? false );
}

/**
 * Whether an ability is exposed as an MCP *tool*, as opposed to a resource or prompt.
 *
 * The adapter reads `meta.mcp.type` and defaults it to 'tool' when absent
 * (DefaultServerFactory::discover_abilities_by_type(), MCP Adapter 0.6.1).
 * Resources and prompts are exposed through different MCP methods and are not
 * callable with execute-ability, so listing them in a catalog that says "call
 * any ability below with execute-ability" would be actively wrong. Angie
 * registers several — angie/basic-instructions and angie/ask-for-snippet-details
 * among them — so this is not a hypothetical case.
 *
 * Fails closed on malformed `meta.mcp` for the same reason
 * mswpa_ability_is_mcp_public() does.
 *
 * @param array $meta Ability metadata.
 * @return bool True when the ability is an MCP tool.
 */
function mswpa_ability_is_mcp_tool( array $meta ): bool {
	$mcp_meta = $meta['mcp'] ?? array();

	if ( ! is_array( $mcp_meta ) ) {
		return false;
	}

	return 'tool' === ( $mcp_meta['type'] ?? 'tool' );
}

/**
 * Collapse a value to a single trimmed line.
 *
 * Ability descriptions are written as prose and several run to multiple
 * sentences with newlines in them. The catalog is one ability per line, so a
 * stray newline would silently split one entry into two and make the rest of the
 * list read as if those were ability names.
 *
 * @param mixed $text Raw text.
 * @return string Single-line text, or '' when unusable.
 */
function mswpa_catalog_one_line( $text ): string {
	if ( ! is_string( $text ) ) {
		return '';
	}
	return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * Render one catalog line.
 *
 * @param string $name   Ability name.
 * @param string $detail Description or label. May be empty.
 * @return string The rendered line, or '' when the ability has no usable name.
 */
function mswpa_catalog_line( string $name, string $detail ): string {
	$name = mswpa_catalog_one_line( $name );
	if ( '' === $name ) {
		return '';
	}
	$detail = mswpa_catalog_one_line( $detail );
	return '' === $detail ? $name : $name . ' — ' . $detail;
}

/**
 * Build the catalog block for the MCP server's instructions.
 *
 * Rows are `array( 'name' => string, 'label' => string, 'description' => string )`.
 * Three renderings are tried in order of richness and the first one inside the
 * byte budget wins: name and description, then name and label, then bare names.
 * When even bare names do not fit, '' is returned and the caller leaves the
 * server's stock description alone — a truncated catalog would be worse than
 * none, because an agent cannot tell a list that was cut short from a site that
 * simply has fewer abilities.
 *
 * Degrading by whole tiers rather than truncating mid-list is deliberate for the
 * same reason: every ability is either fully described or uniformly terser, and
 * no ability silently disappears.
 *
 * @param array[] $rows Ability rows, in the order they should be listed.
 * @return string The catalog block, or '' when it cannot fit the budget.
 */
function mswpa_build_ability_catalog( array $rows ): string {
	if ( empty( $rows ) ) {
		return '';
	}

	$preamble = __(
		'Call any ability below with execute-ability, passing its name as ability_name. Call get-ability-info first when you need the exact input schema. You do not need to call discover-abilities — the full list follows.',
		'ms-wp-abilities'
	);

	$renderings = array(
		static function ( array $row ): string {
			return mswpa_catalog_line( (string) ( $row['name'] ?? '' ), (string) ( $row['description'] ?? '' ) );
		},
		static function ( array $row ): string {
			return mswpa_catalog_line( (string) ( $row['name'] ?? '' ), (string) ( $row['label'] ?? '' ) );
		},
		static function ( array $row ): string {
			return mswpa_catalog_line( (string) ( $row['name'] ?? '' ), '' );
		},
	);

	foreach ( $renderings as $render ) {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line = $render( $row );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$catalog = $preamble . "\n\n" . implode( "\n", $lines );
		if ( strlen( $catalog ) <= MSWPA_CATALOG_MAX_CHARS ) {
			return $catalog;
		}
	}

	return '';
}
