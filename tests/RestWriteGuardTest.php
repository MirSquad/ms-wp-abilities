<?php
/**
 * Tests for the miriamschwab/rest-write hard-block guard.
 *
 * The guard is the plugin's one non-negotiable boundary: a short list of
 * destructive REST writes that are refused in code regardless of what the agent
 * and the user agreed conversationally. These tests exist so that promise stays
 * true for casing and padding variants, not just for the canonical spelling.
 *
 * @package MS_WP_Abilities
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers mswpa_rest_write_blocked_reason() and mswpa_normalize_rest_body().
 */
class RestWriteGuardTest extends TestCase {

	/**
	 * Assert a call is blocked, with a readable failure message.
	 *
	 * @param string $route  REST route.
	 * @param string $method HTTP method.
	 * @param array  $body   Request body.
	 * @return void
	 */
	private function assertBlocked( string $route, string $method, array $body = array() ): void {
		$reason = mswpa_rest_write_blocked_reason( $route, $method, $body );
		$this->assertIsString(
			$reason,
			sprintf( 'Expected %s %s with body %s to be BLOCKED.', $method, $route, wp_json_encode_compat( $body ) )
		);
		$this->assertNotSame( '', $reason, 'A block must carry a non-empty reason.' );
	}

	/**
	 * Assert a call is allowed.
	 *
	 * @param string $route  REST route.
	 * @param string $method HTTP method.
	 * @param array  $body   Request body.
	 * @return void
	 */
	private function assertAllowed( string $route, string $method, array $body = array() ): void {
		$this->assertNull(
			mswpa_rest_write_blocked_reason( $route, $method, $body ),
			sprintf( 'Expected %s %s with body %s to be ALLOWED.', $method, $route, wp_json_encode_compat( $body ) )
		);
	}

	// ---------------------------------------------------------------------
	// force parameter — the case-sensitivity bypass this suite was written for.
	// ---------------------------------------------------------------------

	/**
	 * Every spelling of `force` that sanitize_key() folds to "force" must be blocked.
	 *
	 * @dataProvider forceKeyVariants
	 *
	 * @param string $key Body key as the caller would send it.
	 * @return void
	 */
	public function test_force_key_variants_are_blocked( string $key ): void {
		$this->assertBlocked( '/wp/v2/posts/42', 'DELETE', array( $key => true ) );
	}

	/**
	 * Body-key spellings that all normalize to "force".
	 *
	 * @return array<string, array{0: string}>
	 */
	public function forceKeyVariants(): array {
		return array(
			'lowercase (the case that always worked)' => array( 'force' ),
			'capitalized'                             => array( 'Force' ),
			'all caps'                                => array( 'FORCE' ),
			'mixed case'                              => array( 'FoRcE' ),
			'trailing space'                          => array( 'force ' ),
			'leading space'                           => array( ' force' ),
			'internal spaces'                          => array( 'f o r c e' ),
			'trailing punctuation'                    => array( 'force!' ),
			'internal punctuation'                    => array( 'fo.rce' ),
			'mixed case with padding'                 => array( "  FoRcE\t" ),
		);
	}

	/**
	 * The force block applies whatever the route is — it is a parameter block, not a route block.
	 *
	 * @return void
	 */
	public function test_force_is_blocked_on_any_route(): void {
		$this->assertBlocked( '/wp/v2/media/9', 'DELETE', array( 'Force' => true ) );
		$this->assertBlocked( '/wp/v2/comments/3', 'DELETE', array( 'FORCE' => 'true' ) );
		$this->assertBlocked( '/some-plugin/v1/thing', 'POST', array( 'Force' => 1 ) );
	}

	/**
	 * force=false is still blocked — the guard blocks the parameter, not a truthy value.
	 *
	 * Passing force at all signals intent to bypass trash, and the endpoint's own
	 * coercion of the value is not something this guard should have to model.
	 *
	 * @return void
	 */
	public function test_force_is_blocked_regardless_of_value(): void {
		$this->assertBlocked( '/wp/v2/posts/42', 'DELETE', array( 'force' => false ) );
		$this->assertBlocked( '/wp/v2/posts/42', 'DELETE', array( 'Force' => '' ) );
	}

	/**
	 * Keys that merely contain "force" must NOT be blocked — no over-blocking.
	 *
	 * @dataProvider nonForceKeys
	 *
	 * @param string $key Body key that does not normalize to "force".
	 * @return void
	 */
	public function test_keys_that_are_not_force_are_allowed( string $key ): void {
		$this->assertAllowed( '/wp/v2/posts/42', 'POST', array( $key => true ) );
	}

	/**
	 * Body keys that look force-adjacent but normalize to something else.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function nonForceKeys(): array {
		return array(
			'forced'    => array( 'forced' ),
			'enforce'   => array( 'enforce' ),
			'force_all' => array( 'force_all' ),
			'reinforce' => array( 'reinforce' ),
			'status'    => array( 'status' ),
		);
	}

	/**
	 * A force= query string on the route is blocked, in any casing.
	 *
	 * Note: WP_REST_Request does not split query strings out of a route, so such a
	 * route would not dispatch anyway. This clause is defense in depth.
	 *
	 * @return void
	 */
	public function test_force_in_route_query_string_is_blocked(): void {
		$this->assertBlocked( '/wp/v2/posts/42?force=true', 'DELETE' );
		$this->assertBlocked( '/wp/v2/posts/42?FORCE=TRUE', 'DELETE' );
		$this->assertBlocked( '/wp/v2/posts/42?status=draft&Force=1', 'DELETE' );
	}

	// ---------------------------------------------------------------------
	// Route blocks — WordPress matches REST routes case-insensitively.
	// ---------------------------------------------------------------------

	/**
	 * Writes to the users endpoint are blocked in every casing.
	 *
	 * WP_REST_Server::match_request_to_handler() matches with '@^' . $route . '$@i',
	 * so /wp/v2/Users/5 reaches the same handler as /wp/v2/users/5.
	 *
	 * @dataProvider usersRoutes
	 *
	 * @param string $route Route spelling.
	 * @return void
	 */
	public function test_users_writes_are_blocked( string $route ): void {
		$this->assertBlocked( $route, 'POST' );
		$this->assertBlocked( $route, 'DELETE' );
		$this->assertBlocked( $route, 'PATCH' );
	}

	/**
	 * Users-route spellings that all resolve to the users endpoint.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function usersRoutes(): array {
		return array(
			'lowercase'          => array( '/wp/v2/users' ),
			'capitalized noun'   => array( '/wp/v2/Users' ),
			'all caps'           => array( '/WP/V2/USERS' ),
			'mixed case'         => array( '/Wp/V2/UsErS' ),
			'with id'            => array( '/wp/v2/users/5' ),
			'capitalized w/ id'  => array( '/wp/v2/Users/5' ),
			'with query string'  => array( '/wp/v2/users?context=edit' ),
			'me endpoint'        => array( '/wp/v2/users/me' ),
		);
	}

	/**
	 * Deleting plugins is blocked in every casing; other plugin writes still pass.
	 *
	 * @return void
	 */
	public function test_plugin_deletes_are_blocked_but_other_methods_pass(): void {
		$this->assertBlocked( '/wp/v2/plugins/akismet/akismet', 'DELETE' );
		$this->assertBlocked( '/wp/v2/Plugins/akismet/akismet', 'DELETE' );
		$this->assertBlocked( '/WP/V2/PLUGINS', 'DELETE' );

		// Activating / installing a plugin is deliberately still allowed.
		$this->assertAllowed( '/wp/v2/plugins', 'POST' );
		$this->assertAllowed( '/wp/v2/plugins/akismet/akismet', 'PUT' );
	}

	/**
	 * Writes to the settings endpoint are blocked in every casing and method.
	 *
	 * @return void
	 */
	public function test_settings_writes_are_blocked(): void {
		$this->assertBlocked( '/wp/v2/settings', 'POST' );
		$this->assertBlocked( '/wp/v2/Settings', 'POST' );
		$this->assertBlocked( '/WP/V2/SETTINGS', 'PUT' );
		$this->assertBlocked( '/wp/v2/settings?context=edit', 'PATCH' );
	}

	/**
	 * Ordinary writes are unaffected — the guard is a short list, not a wall.
	 *
	 * @return void
	 */
	public function test_ordinary_writes_are_allowed(): void {
		$this->assertAllowed( '/wp/v2/posts', 'POST', array( 'title' => 'Hello' ) );
		$this->assertAllowed( '/wp/v2/posts/42', 'PATCH', array( 'status' => 'draft' ) );
		$this->assertAllowed( '/wp/v2/posts/42', 'DELETE', array( 'status' => 'trash' ) );
		$this->assertAllowed( '/wp/v2/comments/3', 'POST' );
		$this->assertAllowed( '/angie/v1/thing', 'POST' );
	}

	/**
	 * Routes that merely share a prefix with a blocked route are not blocked.
	 *
	 * @return void
	 */
	public function test_prefix_lookalike_routes_are_allowed(): void {
		$this->assertAllowed( '/wp/v2/user', 'POST' );
		$this->assertAllowed( '/wp/v2/usersomething', 'POST' );
		$this->assertAllowed( '/wp/v2/settings-extra', 'POST' );
		$this->assertAllowed( '/my-plugin/v1/users', 'POST' );
	}

	// ---------------------------------------------------------------------
	// The structural property: the guard sees what the dispatcher will see.
	// ---------------------------------------------------------------------

	/**
	 * mswpa_normalize_rest_body() folds keys exactly as sanitize_key() does.
	 *
	 * @return void
	 */
	public function test_normalize_rest_body_folds_keys(): void {
		$this->assertSame(
			array( 'force' => true ),
			mswpa_normalize_rest_body( array( 'Force' => true ) )
		);
		$this->assertSame(
			array(
				'force'  => true,
				'status' => 'trash',
			),
			mswpa_normalize_rest_body(
				array(
					'FORCE'  => true,
					'StAtUs' => 'trash',
				)
			)
		);
		$this->assertSame( array(), mswpa_normalize_rest_body( array() ) );
	}

	/**
	 * Normalization is idempotent, so the guard normalizing a second time is a no-op.
	 *
	 * @return void
	 */
	public function test_normalize_rest_body_is_idempotent(): void {
		$raw  = array(
			'Force'  => true,
			'St Atus' => 'trash',
			'ok_key' => 1,
		);
		$once = mswpa_normalize_rest_body( $raw );
		$this->assertSame( $once, mswpa_normalize_rest_body( $once ) );
	}

	/**
	 * The regression guard: a body is blocked if and only if the keys that will
	 * actually be dispatched contain "force".
	 *
	 * This is the property that broke. Checking raw keys while dispatching
	 * normalized keys let {"Force": true} pass the guard and still land as
	 * "force" on the endpoint. Asserting the equivalence directly means any
	 * future divergence between the check and the dispatch fails here.
	 *
	 * @dataProvider bodiesForDispatchEquivalence
	 *
	 * @param array $body Raw body as supplied by the caller.
	 * @return void
	 */
	public function test_guard_agrees_with_what_will_be_dispatched( array $body ): void {
		$dispatched_keys = array_keys( mswpa_normalize_rest_body( $body ) );
		$will_send_force = in_array( 'force', $dispatched_keys, true );

		$blocked = null !== mswpa_rest_write_blocked_reason( '/wp/v2/posts/42', 'DELETE', $body );

		$this->assertSame(
			$will_send_force,
			$blocked,
			sprintf(
				'Guard and dispatcher disagree for body %s: dispatch keys are [%s].',
				wp_json_encode_compat( $body ),
				implode( ', ', $dispatched_keys )
			)
		);
	}

	/**
	 * A spread of bodies, force-bearing and not, for the equivalence check.
	 *
	 * @return array<string, array{0: array}>
	 */
	public function bodiesForDispatchEquivalence(): array {
		return array(
			'empty'                => array( array() ),
			'lowercase force'      => array( array( 'force' => true ) ),
			'capitalized force'    => array( array( 'Force' => true ) ),
			'all caps force'       => array( array( 'FORCE' => true ) ),
			'padded force'         => array( array( ' Force ' => true ) ),
			'punctuated force'     => array( array( 'f.o.r.c.e' => true ) ),
			'force among others'   => array(
				array(
					'status' => 'trash',
					'Force'  => true,
				),
			),
			'no force'             => array( array( 'status' => 'trash' ) ),
			'force-like but not'   => array( array( 'forced' => true ) ),
			'enforce'              => array( array( 'enforce' => true ) ),
		);
	}

	/**
	 * The guard is safe for callers that have not normalized yet — passing raw and
	 * pre-normalized bodies yields the same verdict.
	 *
	 * @return void
	 */
	public function test_guard_verdict_is_same_for_raw_and_normalized_bodies(): void {
		foreach ( $this->bodiesForDispatchEquivalence() as $label => $case ) {
			$raw = $case[0];
			$this->assertSame(
				mswpa_rest_write_blocked_reason( '/wp/v2/posts/42', 'DELETE', $raw ),
				mswpa_rest_write_blocked_reason( '/wp/v2/posts/42', 'DELETE', mswpa_normalize_rest_body( $raw ) ),
				sprintf( 'Raw and normalized bodies disagree for case "%s".', $label )
			);
		}
	}
}

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
 * json_encode wrapper isolated so the test file has no direct json_encode call.
 *
 * @param mixed $value Value to encode.
 * @return string|false JSON string, or false on failure.
 */
function wp_json_encode_fallback( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only helper; wp_json_encode() is not loaded in this harness.
}
