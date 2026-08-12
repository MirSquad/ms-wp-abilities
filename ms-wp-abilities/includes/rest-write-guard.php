<?php
/**
 * REST write hard-block list — enforced in code regardless of conversational
 * confirmation. See ms-wp-abilities-decisions-log.md for the rationale.
 *
 * This lives in its own file so the guard can be unit-tested in isolation,
 * without loading the plugin's hook registrations. See tests/RestWriteGuardTest.php.
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize REST write body keys exactly the way they will be dispatched.
 *
 * The rest-write ability passes every body key through sanitize_key() before
 * setting it on the WP_REST_Request. sanitize_key() lowercases and strips any
 * character outside [a-z0-9_-], so "Force", "FORCE" and "f o r c e" all arrive
 * at the endpoint as "force". The hard-block guard has to inspect the same
 * normalized keys, or a casing/padding variant slips past the check and still
 * reaches the endpoint under its normalized name.
 *
 * This is the single normalization point: both mswpa_rest_write_blocked_reason()
 * and the dispatch loop use it, so the two cannot diverge. sanitize_key() is
 * idempotent, so applying it on an already-normalized array is a no-op.
 *
 * @param array $body Raw request body parameters.
 * @return array Body with every key passed through sanitize_key().
 */
function mswpa_normalize_rest_body( array $body ): array {
	$normalized = array();
	foreach ( $body as $key => $value ) {
		$normalized[ sanitize_key( $key ) ] = $value;
	}
	return $normalized;
}

/**
 * Return a human-readable reason if a REST write should be hard-blocked, or null to allow.
 *
 * @param string $route  REST route being requested.
 * @param string $method HTTP method.
 * @param array  $body   Request body parameters. Keys are normalized here, so raw
 *                       (un-normalized) input is safe to pass.
 * @return string|null Block reason, or null when the write is allowed.
 */
function mswpa_rest_write_blocked_reason( string $route, string $method, array $body ) {
	// The route patterns below are case-insensitive on purpose. WordPress matches
	// registered routes with a case-insensitive regex
	// (WP_REST_Server::match_request_to_handler() uses '@^' . $route . '$@i'), so
	// /wp/v2/Users and /WP/V2/USERS both reach the users endpoint. Case-sensitive
	// patterns here would let a casing variant walk straight past the block list.
	if ( preg_match( '#^/wp/v2/users(/|$|\?)#i', $route ) ) {
		return __( 'Writes to /wp/v2/users are blocked — creation, role changes, deletion, and password resets all live behind this one endpoint.', 'ms-wp-abilities' );
	}
	if ( 'DELETE' === $method && preg_match( '#^/wp/v2/plugins(/|$|\?)#i', $route ) ) {
		return __( 'Deleting plugins via REST is blocked.', 'ms-wp-abilities' );
	}
	if ( preg_match( '#^/wp/v2/settings(/|$|\?)#i', $route ) ) {
		return __( 'Writes to /wp/v2/settings are blocked.', 'ms-wp-abilities' );
	}
	// Normalize defensively so this guard is correct for any caller, not only the
	// rest-write callback that already normalizes before calling.
	$normalized_body = mswpa_normalize_rest_body( $body );
	if ( array_key_exists( 'force', $normalized_body ) || false !== stripos( $route, 'force=' ) ) {
		return __( 'force (permanent delete bypassing trash) is blocked.', 'ms-wp-abilities' );
	}
	return null;
}
