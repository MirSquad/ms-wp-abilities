<?php
/**
 * PHPUnit bootstrap for the MS WordPress Abilities test suite.
 *
 * Loads the files under includes/, not the whole plugin. Between them they have
 * exactly two WordPress dependencies — sanitize_key() and __() — so they can be
 * exercised against a pair of stubs instead of a full WordPress test install.
 * Everything that needs more than that stays in ms-wp-abilities.php, where the
 * hook registrations live; that split is what keeps this suite stub-sized.
 *
 * sanitize_key() is the function the guard's correctness actually rests on, so
 * it is reproduced exactly as WordPress implements it (minus the filter hook,
 * which this plugin does not subscribe to). If that stub drifts from core, the
 * tests stop proving anything about production behavior.
 *
 * @package MS_WP_Abilities
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Copy of wp-includes/formatting.php::sanitize_key() (WordPress 6.9), minus
	 * the `sanitize_key` filter. Lowercases, then strips every character outside
	 * [a-z0-9_-]. The (string) cast is the only deviation — preg_replace() only
	 * returns null on a PCRE error, so it is behaviorally identical.
	 *
	 * @param string $key Key to sanitize.
	 * @return string Sanitized key.
	 */
	function sanitize_key( $key ) {
		$sanitized_key = '';

		if ( is_scalar( $key ) ) {
			$sanitized_key = strtolower( $key );
			$sanitized_key = (string) preg_replace( '/[^a-z0-9_\-]/', '', $sanitized_key );
		}

		return $sanitized_key;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass-through translation stub.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain (ignored).
	 * @return string Untranslated text.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		unset( $domain );
		return (string) $text;
	}
}

require_once __DIR__ . '/../ms-wp-abilities/includes/rest-write-guard.php';
require_once __DIR__ . '/../ms-wp-abilities/includes/ability-policy.php';
require_once __DIR__ . '/../ms-wp-abilities/includes/ability-fields.php';
require_once __DIR__ . '/../ms-wp-abilities/includes/ability-audit-log.php';
require_once __DIR__ . '/../ms-wp-abilities/includes/ability-catalog.php';

/**
 * Compact JSON encoder for assertion messages (WordPress's wp_json_encode is not stubbed).
 *
 * @param mixed $value Value to encode.
 * @return string JSON representation.
 */
function wp_json_encode_compat( $value ): string {
	$encoded = wp_json_encode_fallback( $value );
	return false === $encoded ? '<unencodable>' : $encoded;
}

/**
 * json_encode wrapper isolated so the test files have no direct json_encode call.
 *
 * @param mixed $value Value to encode.
 * @return string|false JSON string, or false on failure.
 */
function wp_json_encode_fallback( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only helper; wp_json_encode() is not loaded in this harness.
}

/**
 * Parse this plugin's ability registrations out of the plugin source.
 *
 * The tests compare hand-maintained tables (the policy table, the audit log's
 * allowlist) against the real registrations. Reading the source rather than
 * loading it is what keeps this suite free of a WordPress install:
 * wp_register_ability() and current_user_can() do not need to exist for the
 * registrations to be readable.
 *
 * Shared by AbilityPolicyTest and AbilityAuditLogTest so there is one parser to
 * keep correct rather than two.
 *
 * @return array<string, array{cap: string, required: string[], props: string[], category: string,
 *         mcp_public: bool, enum: string[], row: string[], wants: string[]}>
 *         Ability name => capability, required input keys, all top-level input keys, category,
 *         whether meta.mcp.public is set, and — for the abilities that support field selection —
 *         the `fields` enum, the keys of the row the callback builds, and the names passed to
 *         $wants().
 */
function mswpa_test_parse_registrations(): array {
	$source = (string) file_get_contents( __DIR__ . '/../ms-wp-abilities/ms-wp-abilities.php' );
	$chunks = explode( 'wp_register_ability(', $source );
	array_shift( $chunks );

	$found = array();
	foreach ( $chunks as $chunk ) {
		if ( ! preg_match( "/^\s*'(miriamschwab\/[a-z0-9-]+)'/", $chunk, $name_match ) ) {
			continue;
		}

		$cap = '';
		if ( preg_match( "/'permission_callback'\s*=>\s*fn\(\)\s*=>\s*current_user_can\(\s*'([a-z_]+)'\s*\)/", $chunk, $cap_match ) ) {
			$cap = $cap_match[1];
		}

		// Isolate the input schema so output_schema keys are not mistaken for inputs.
		$schema_start = strpos( $chunk, "'input_schema'" );
		$schema_end   = strpos( $chunk, "'output_schema'" );
		$schema       = ( false !== $schema_start && false !== $schema_end )
			? substr( $chunk, $schema_start, $schema_end - $schema_start )
			: '';

		$required = array();
		if ( preg_match( "/'required'\s*=>\s*array\(([^)]*)\)/", $schema, $req_match ) ) {
			preg_match_all( "/'([a-zA-Z_]+)'/", $req_match[1], $req_names );
			$required = $req_names[1];
		}

		// Top-level input properties sit at exactly five tabs of indentation.
		preg_match_all( "/^\t{5}'([a-zA-Z_]+)'\s*=>\s*array\(/m", $schema, $prop_names );

		// Everything below mirrors something else in the same registration, and
		// each mirror has a test asserting it still lines up.
		$category = '';
		if ( preg_match( "/'category'\s*=>\s*'([a-z0-9-]+)'/", $chunk, $cat_match ) ) {
			$category = $cat_match[1];
		}

		$mcp_public = (bool) preg_match(
			"/'meta'\s*=>\s*array\(\s*'mcp'\s*=>\s*array\(\s*'public'\s*=>\s*true/",
			$chunk
		);

		// The `fields` enum, the row the callback builds, and the field names
		// passed to $wants() are three hand-written lists of the same thing.
		$enum = array();
		if ( preg_match( "/'fields'\s*=>.*?'enum'\s*=>\s*array\(([^)]*)\)/s", $chunk, $enum_match ) ) {
			preg_match_all( "/'([A-Za-z_]+)'/", $enum_match[1], $enum_names );
			$enum = $enum_names[1];
		}

		$row = array();
		if ( preg_match( '/\$row\s*=\s*array\((.*?)^\t{5}\);/ms', $chunk, $row_match ) ) {
			preg_match_all( "/^\t{6}'([A-Za-z_]+)'\s*=>/m", $row_match[1], $row_names );
			$row = $row_names[1];
		}

		preg_match_all( '/\$wants\(\s*\'([A-Za-z_]+)\'\s*\)/', $chunk, $wants_names );

		$found[ $name_match[1] ] = array(
			'cap'        => $cap,
			'required'   => $required,
			'props'      => $prop_names[1],
			'category'   => $category,
			'mcp_public' => $mcp_public,
			'enum'       => $enum,
			'row'        => $row,
			'wants'      => array_values( array_unique( $wants_names[1] ) ),
		);
	}

	return $found;
}
