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
