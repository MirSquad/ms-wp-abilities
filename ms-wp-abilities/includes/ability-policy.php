<?php
/**
 * Central policy table for this plugin's abilities.
 *
 * One table, three consumers:
 *
 *   1. mswpa_force_no_rest_exposure()   — keeps every ability out of the core
 *                                         abilities REST API.
 *   2. mswpa_enforce_capability_floor() — re-checks the declared capability after
 *                                         the ability's own permission_callback ran.
 *   3. the wp_ability_invoked audit log — decides which invocations are recorded.
 *
 * Keeping the classification in one place is the point: an ability added to
 * ms-wp-abilities.php without a matching entry here fails AbilityPolicyTest, so
 * the three behaviors above can never silently miss a new ability.
 *
 * This file has no WordPress dependencies, so it can be unit-tested without
 * loading the plugin's hook registrations. See tests/AbilityPolicyTest.php.
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Namespace prefix for every ability this plugin registers.
 *
 * Used to scope the registration filters so they never touch `core/*` abilities
 * or another plugin's — their exposure and permissions are not ours to override.
 */
const MSWPA_ABILITY_PREFIX = 'miriamschwab/';

/**
 * The canonical policy table for this plugin's abilities.
 *
 * `cap` mirrors the capability the ability's own `permission_callback` checks.
 * The duplication is deliberate: the floor in mswpa_enforce_capability_floor()
 * exists precisely so a registration whose callback is wrong, missing, or
 * weakened cannot execute anyway. AbilityPolicyTest asserts the two stay in sync.
 *
 * `write` marks abilities that change site content or configuration. Read
 * abilities are excluded from the audit log — logging them would write to the
 * database on every agent query for no investigative value.
 *
 * miriamschwab/preview-post-update is classified as a read on purpose. It stages
 * a proposal into user meta and explicitly changes nothing about the post; the
 * paired apply-post-update is the mutation, and is what the log should record.
 *
 * @return array<string, array{cap: string, write: bool}> Ability name => policy.
 */
function mswpa_ability_policy(): array {
	return array(
		// Posts and pages.
		'miriamschwab/get-posts'             => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/get-pages'             => array(
			'cap'   => 'edit_pages',
			'write' => false,
		),
		'miriamschwab/create-post'           => array(
			'cap'   => 'edit_posts',
			'write' => true,
		),
		'miriamschwab/patch-post-content'    => array(
			'cap'   => 'edit_posts',
			'write' => true,
		),
		'miriamschwab/preview-post-update'   => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/apply-post-update'     => array(
			'cap'   => 'edit_posts',
			'write' => true,
		),
		'miriamschwab/trash-post'            => array(
			'cap'   => 'delete_posts',
			'write' => true,
		),
		'miriamschwab/get-post-meta'         => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/get-post-types'        => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),

		// Taxonomies.
		'miriamschwab/get-categories'        => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/get-tags'              => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/create-term'           => array(
			'cap'   => 'manage_categories',
			'write' => true,
		),

		// Media.
		'miriamschwab/get-media'             => array(
			'cap'   => 'upload_files',
			'write' => false,
		),
		'miriamschwab/update-media-meta'     => array(
			'cap'   => 'upload_files',
			'write' => true,
		),

		// Site.
		'miriamschwab/get-users'             => array(
			'cap'   => 'list_users',
			'write' => false,
		),
		'miriamschwab/get-site-settings'     => array(
			'cap'   => 'manage_options',
			'write' => false,
		),
		'miriamschwab/get-menus'             => array(
			'cap'   => 'edit_theme_options',
			'write' => false,
		),

		// Plugins and themes.
		'miriamschwab/get-plugins'           => array(
			'cap'   => 'activate_plugins',
			'write' => false,
		),
		'miriamschwab/install-plugin'        => array(
			'cap'   => 'install_plugins',
			'write' => true,
		),
		'miriamschwab/activate-plugin'       => array(
			'cap'   => 'activate_plugins',
			'write' => true,
		),
		'miriamschwab/update-plugin'         => array(
			'cap'   => 'update_plugins',
			'write' => true,
		),
		'miriamschwab/get-themes'            => array(
			'cap'   => 'switch_themes',
			'write' => false,
		),
		'miriamschwab/update-theme'          => array(
			'cap'   => 'update_themes',
			'write' => true,
		),
		'miriamschwab/get-available-updates' => array(
			'cap'   => 'update_plugins',
			'write' => false,
		),

		// REST bridge.
		'miriamschwab/rest-get'              => array(
			'cap'   => 'edit_posts',
			'write' => false,
		),
		'miriamschwab/rest-write'            => array(
			'cap'   => 'edit_posts',
			'write' => true,
		),
	);
}

/**
 * Whether an ability name belongs to this plugin.
 *
 * @param string $ability_name Ability name, with its namespace.
 * @return bool True when the ability is registered by this plugin.
 */
function mswpa_is_own_ability( string $ability_name ): bool {
	return 0 === strpos( $ability_name, MSWPA_ABILITY_PREFIX );
}

/**
 * Whether an ability changes site content or configuration.
 *
 * Unknown ability names return false — this answers "is this a known write",
 * and callers that need to reject unknown names check membership separately.
 *
 * @param string $ability_name Ability name, with its namespace.
 * @return bool True for a write ability declared in the policy table.
 */
function mswpa_is_write_ability( string $ability_name ): bool {
	$policy = mswpa_ability_policy();
	return isset( $policy[ $ability_name ] ) && true === $policy[ $ability_name ]['write'];
}

/**
 * The capability an ability requires, per the policy table.
 *
 * @param string $ability_name Ability name, with its namespace.
 * @return string|null Capability slug, or null when the ability is not in the table.
 */
function mswpa_required_capability_for( string $ability_name ) {
	$policy = mswpa_ability_policy();
	return $policy[ $ability_name ]['cap'] ?? null;
}

/**
 * Keep this plugin's abilities out of the core abilities REST API.
 *
 * WordPress 7.1 resolves REST exposure as
 * `meta.show_in_rest ?? meta.public ?? false` (WP_Ability::prepare_properties()),
 * so the high-level `meta.public` flag turns on REST exposure unless an explicit
 * `show_in_rest` overrides it. Today every ability here sets only
 * `meta.mcp.public`, so exposure already resolves to false — but only by the
 * absence of `meta.public`, not by any stated decision.
 *
 * This states it. Every ability in this plugin is designed for the MCP
 * conversational channel, where the agent confirms writes with the user before
 * calling and the rest-write hard-block guard backstops that. The core abilities
 * run endpoint has neither; there, install-plugin, trash-post and rest-write
 * would sit behind nothing but their permission_callback. So `show_in_rest` is
 * forced off here rather than left to a default, and adding `meta.public` to an
 * ability for MCP reasons can no longer open REST as a side effect.
 *
 * Setting the flag in this filter beats setting it on each registration: it
 * covers abilities added later without anyone remembering to. The filter fires
 * in WP_Abilities_Registry::register() before `new WP_Ability()`, so the explicit
 * value set here wins the resolution above.
 *
 * MCP exposure is unaffected — McpAbilityExposure::is_meta_public() reads only
 * `meta.mcp.public` then `meta.public`, and never `show_in_rest` (verified
 * against MCP Adapter 0.6.1). Deliberately scoped to this plugin's namespace:
 * whether the three `core/*` abilities appear in REST is core's call, not ours.
 *
 * @param array  $args         Ability registration args.
 * @param string $ability_name Ability being registered.
 * @return array Filtered registration args.
 */
function mswpa_force_no_rest_exposure( array $args, string $ability_name ): array {
	if ( ! mswpa_is_own_ability( $ability_name ) ) {
		return $args;
	}
	if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
		$args['meta'] = array();
	}
	$args['meta']['show_in_rest'] = false;
	return $args;
}
