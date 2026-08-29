<?php
/**
 * Tests for the ability policy table and the REST exposure filter.
 *
 * The drift tests here are the reason the policy table is worth having. Three
 * behaviors read from it — REST exposure, the capability floor, and which
 * invocations reach the audit log — and all three fail silently and invisibly if
 * an ability is added to ms-wp-abilities.php without a matching entry. So these
 * tests parse the registrations out of the plugin file and compare them against
 * the table, and fail the build on any mismatch in either direction.
 *
 * @package MS_WP_Abilities
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers mswpa_ability_policy() and mswpa_force_no_rest_exposure().
 */
class AbilityPolicyTest extends TestCase {

	/**
	 * Ability name => capability, parsed out of the plugin's registrations.
	 *
	 * Parsing lives in mswpa_test_parse_registrations() (tests/bootstrap.php) so
	 * this test and AbilityAuditLogTest read the registrations through one
	 * parser rather than two.
	 *
	 * @return array<string, string> Registered ability name => capability checked.
	 */
	private function registeredAbilities(): array {
		$parsed = mswpa_test_parse_registrations();
		$this->assertNotEmpty( $parsed, 'Parsed no abilities out of the plugin file — the parser has drifted.' );

		$caps = array();
		foreach ( $parsed as $name => $info ) {
			$this->assertNotSame(
				'',
				$info['cap'],
				sprintf( 'Could not parse a permission_callback for %s.', $name )
			);
			$caps[ $name ] = $info['cap'];
		}
		return $caps;
	}

	/**
	 * Every registered ability must be classified in the policy table.
	 *
	 * An unclassified ability is denied by the capability floor, so this failing
	 * means the ability is broken in production, not merely undocumented.
	 *
	 * @return void
	 */
	public function testEveryRegisteredAbilityIsInThePolicyTable(): void {
		$missing = array_diff_key( $this->registeredAbilities(), mswpa_ability_policy() );
		$this->assertSame(
			array(),
			$missing,
			'Abilities registered but absent from mswpa_ability_policy(): ' . implode( ', ', array_keys( $missing ) )
		);
	}

	/**
	 * The policy table must not name abilities that no longer exist.
	 *
	 * @return void
	 */
	public function testPolicyTableHasNoStaleEntries(): void {
		$stale = array_diff_key( mswpa_ability_policy(), $this->registeredAbilities() );
		$this->assertSame(
			array(),
			$stale,
			'Abilities in mswpa_ability_policy() that are not registered: ' . implode( ', ', array_keys( $stale ) )
		);
	}

	/**
	 * The capability in the table must match the one the ability actually checks.
	 *
	 * If these diverge, the floor either blocks a legitimate call or fails to
	 * catch a weakened permission_callback — the two failure modes it exists to
	 * prevent.
	 *
	 * @return void
	 */
	public function testPolicyCapabilitiesMatchTheRegistrations(): void {
		$policy = mswpa_ability_policy();
		foreach ( $this->registeredAbilities() as $name => $cap ) {
			$this->assertArrayHasKey( $name, $policy, sprintf( '%s is not in the policy table.', $name ) );
			$this->assertSame(
				$cap,
				$policy[ $name ]['cap'],
				sprintf( '%s checks "%s" but the policy table says "%s".', $name, $cap, $policy[ $name ]['cap'] )
			);
		}
	}

	/**
	 * Every policy entry must declare both keys, with the right types.
	 *
	 * @return void
	 */
	public function testPolicyEntriesAreWellFormed(): void {
		foreach ( mswpa_ability_policy() as $name => $entry ) {
			$this->assertArrayHasKey( 'cap', $entry, sprintf( '%s has no cap.', $name ) );
			$this->assertArrayHasKey( 'write', $entry, sprintf( '%s has no write flag.', $name ) );
			$this->assertIsString( $entry['cap'], sprintf( '%s cap must be a string.', $name ) );
			$this->assertIsBool( $entry['write'], sprintf( '%s write flag must be a bool.', $name ) );
			$this->assertNotSame( '', $entry['cap'], sprintf( '%s cap must not be empty.', $name ) );
		}
	}

	/**
	 * The abilities that change site state must be flagged as writes.
	 *
	 * Spelled out rather than derived, so that reclassifying an ability — which
	 * silently drops it from the audit log — has to be a deliberate edit here.
	 *
	 * @return void
	 */
	public function testKnownWriteAbilitiesAreFlagged(): void {
		$writes = array(
			'miriamschwab/create-post',
			'miriamschwab/patch-post-content',
			'miriamschwab/apply-post-update',
			'miriamschwab/trash-post',
			'miriamschwab/create-term',
			'miriamschwab/update-media-meta',
			'miriamschwab/install-plugin',
			'miriamschwab/activate-plugin',
			'miriamschwab/update-plugin',
			'miriamschwab/update-theme',
			'miriamschwab/rest-write',
		);
		foreach ( $writes as $name ) {
			$this->assertTrue( mswpa_is_write_ability( $name ), sprintf( '%s should be a write.', $name ) );
		}
	}

	/**
	 * Read abilities must not be flagged as writes.
	 *
	 * preview-post-update is included deliberately: it stages into user meta but
	 * changes nothing about the post, and apply-post-update is the mutation.
	 *
	 * @return void
	 */
	public function testReadAbilitiesAreNotFlaggedAsWrites(): void {
		$reads = array(
			'miriamschwab/get-posts',
			'miriamschwab/get-pages',
			'miriamschwab/preview-post-update',
			'miriamschwab/get-media',
			'miriamschwab/get-users',
			'miriamschwab/rest-get',
		);
		foreach ( $reads as $name ) {
			$this->assertFalse( mswpa_is_write_ability( $name ), sprintf( '%s should not be a write.', $name ) );
		}
	}

	/**
	 * An unknown ability is neither a write nor holder of a required capability.
	 *
	 * @return void
	 */
	public function testUnknownAbilityIsNotAWriteAndHasNoCapability(): void {
		$this->assertFalse( mswpa_is_write_ability( 'miriamschwab/not-a-real-ability' ) );
		$this->assertNull( mswpa_required_capability_for( 'miriamschwab/not-a-real-ability' ) );
	}

	// ---------------------------------------------------------------------
	// REST exposure.
	// ---------------------------------------------------------------------

	/**
	 * Every ability in this plugin's namespace is forced out of the REST API.
	 *
	 * @return void
	 */
	public function testOwnAbilitiesAreForcedOutOfRest(): void {
		foreach ( array_keys( mswpa_ability_policy() ) as $name ) {
			$args = mswpa_force_no_rest_exposure( array( 'meta' => array( 'mcp' => array( 'public' => true ) ) ), $name );
			$this->assertFalse( $args['meta']['show_in_rest'], sprintf( '%s must not be exposed to REST.', $name ) );
		}
	}

	/**
	 * A `meta.public` flag must not open REST exposure for this plugin's abilities.
	 *
	 * This is the regression the filter exists for: on WordPress 7.1 core resolves
	 * show_in_rest from meta.public, so adding that flag for MCP reasons would
	 * otherwise publish write abilities to the REST run endpoint as a side effect.
	 *
	 * @return void
	 */
	public function testPublicMetaDoesNotOpenRestExposure(): void {
		$args = mswpa_force_no_rest_exposure(
			array( 'meta' => array( 'public' => true ) ),
			'miriamschwab/rest-write'
		);
		$this->assertFalse( $args['meta']['show_in_rest'] );
		$this->assertTrue( $args['meta']['public'], 'The public flag itself must be left alone for MCP.' );
	}

	/**
	 * An explicit show_in_rest => true is overridden, not honored.
	 *
	 * @return void
	 */
	public function testExplicitShowInRestIsOverridden(): void {
		$args = mswpa_force_no_rest_exposure(
			array( 'meta' => array( 'show_in_rest' => true ) ),
			'miriamschwab/get-posts'
		);
		$this->assertFalse( $args['meta']['show_in_rest'] );
	}

	/**
	 * Registration args with no meta key at all are handled.
	 *
	 * @return void
	 */
	public function testMissingMetaIsCreated(): void {
		$args = mswpa_force_no_rest_exposure( array( 'label' => 'X' ), 'miriamschwab/get-posts' );
		$this->assertFalse( $args['meta']['show_in_rest'] );
		$this->assertSame( 'X', $args['label'], 'Other args must be preserved.' );
	}

	/**
	 * Abilities outside this plugin's namespace are left untouched.
	 *
	 * Whether the core abilities appear in REST is core's decision, not this
	 * plugin's, and another plugin's abilities are not ours to reach into.
	 *
	 * @dataProvider foreignAbilityNames
	 *
	 * @param string $name Ability name outside this plugin's namespace.
	 * @return void
	 */
	public function testForeignAbilitiesAreUntouched( string $name ): void {
		$args = array( 'meta' => array( 'public' => true ) );
		$this->assertSame( $args, mswpa_force_no_rest_exposure( $args, $name ) );
	}

	/**
	 * Ability names belonging to core or other plugins.
	 *
	 * @return array<string, string[]>
	 */
	public function foreignAbilityNames(): array {
		return array(
			'core site info'    => array( 'core/get-site-info' ),
			'core user info'    => array( 'core/get-user-info' ),
			'another plugin'    => array( 'woocommerce/get-orders' ),
			'similar prefix'    => array( 'miriamschwab-other/get-posts' ),
		);
	}

	/**
	 * Namespace membership is decided by prefix, not by substring.
	 *
	 * @return void
	 */
	public function testOwnAbilityDetection(): void {
		$this->assertTrue( mswpa_is_own_ability( 'miriamschwab/get-posts' ) );
		$this->assertFalse( mswpa_is_own_ability( 'other/miriamschwab/get-posts' ) );
		$this->assertFalse( mswpa_is_own_ability( 'miriamschwab-other/get-posts' ) );
	}
}
