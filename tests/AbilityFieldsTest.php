<?php
/**
 * Tests for the optional `fields` input helpers.
 *
 * @package MS_WP_Abilities
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers mswpa_requested_fields() and mswpa_apply_fields().
 */
class AbilityFieldsTest extends TestCase {

	/**
	 * A representative result row.
	 *
	 * @return array<string, mixed>
	 */
	private function row(): array {
		return array(
			'ID'          => 42,
			'post_title'  => 'Hello',
			'post_status' => 'publish',
			'permalink'   => 'https://example.com/hello',
		);
	}

	/**
	 * Anything that is not an array normalizes to "no selection".
	 *
	 * @dataProvider nonArrayInputs
	 *
	 * @param mixed $raw Raw fields input.
	 * @return void
	 */
	public function testNonArrayInputNormalizesToEmpty( $raw ): void {
		$this->assertSame( array(), mswpa_requested_fields( $raw ) );
	}

	/**
	 * Values that are not a usable fields list.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function nonArrayInputs(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'post_title' ),
			'int'    => array( 5 ),
			'bool'   => array( true ),
		);
	}

	/**
	 * Non-string and empty members are dropped, duplicates collapsed.
	 *
	 * @return void
	 */
	public function testFieldListIsCleaned(): void {
		$this->assertSame(
			array( 'ID', 'post_title' ),
			mswpa_requested_fields( array( 'ID', 'post_title', 'ID', '', null, 7, array( 'x' ) ) )
		);
	}

	/**
	 * The cleaned list is re-indexed, so it is a list and not a sparse array.
	 *
	 * @return void
	 */
	public function testFieldListIsReindexed(): void {
		$fields = mswpa_requested_fields( array( '', 'ID', '', 'post_title' ) );
		$this->assertSame( array( 0, 1 ), array_keys( $fields ) );
	}

	/**
	 * An empty selection returns the row untouched.
	 *
	 * @return void
	 */
	public function testEmptySelectionReturnsWholeRow(): void {
		$this->assertSame( $this->row(), mswpa_apply_fields( $this->row(), array() ) );
	}

	/**
	 * Only the requested fields survive.
	 *
	 * @return void
	 */
	public function testSelectionReducesTheRow(): void {
		$this->assertSame(
			array(
				'ID'         => 42,
				'post_title' => 'Hello',
			),
			mswpa_apply_fields( $this->row(), array( 'ID', 'post_title' ) )
		);
	}

	/**
	 * The `always` fields survive a selection that omits them.
	 *
	 * Without this an agent gets titles it cannot map back to a post, and has to
	 * issue a second query — which is what asking for fewer fields was avoiding.
	 *
	 * @return void
	 */
	public function testAlwaysFieldsSurviveOmission(): void {
		$this->assertSame(
			array(
				'ID'         => 42,
				'post_title' => 'Hello',
			),
			mswpa_apply_fields( $this->row(), array( 'post_title' ), array( 'ID' ) )
		);
	}

	/**
	 * Output key order follows the row, not the order fields were requested.
	 *
	 * @return void
	 */
	public function testKeyOrderFollowsTheRow(): void {
		$this->assertSame(
			array( 'ID', 'post_title', 'permalink' ),
			array_keys( mswpa_apply_fields( $this->row(), array( 'permalink', 'post_title', 'ID' ) ) )
		);
	}

	/**
	 * Requesting a field the row does not carry is not an error.
	 *
	 * The input schema's enum rejects unknown names before execute() runs, so
	 * this only guards a direct call.
	 *
	 * @return void
	 */
	public function testUnknownRequestedFieldIsIgnored(): void {
		$this->assertSame(
			array( 'ID' => 42 ),
			mswpa_apply_fields( $this->row(), array( 'ID', 'nope' ) )
		);
	}

	// ---------------------------------------------------------------------
	// Drift guards.
	//
	// Each ability that supports field selection writes the same list of field
	// names three times: the `fields` enum in its input schema, the keys of the
	// row its callback builds, and the names it passes to $wants(). Nothing in
	// PHP ties those together, and a mismatch fails silently — an agent asking
	// for a field the enum omits gets a validation error for a field that
	// exists, and a $wants() typo means a lookup is always or never performed.
	// ---------------------------------------------------------------------

	/**
	 * The advertised `fields` enum must match the row the callback actually builds.
	 *
	 * @return void
	 */
	public function testFieldsEnumMatchesTheRow(): void {
		$checked = 0;

		foreach ( mswpa_test_parse_registrations() as $name => $info ) {
			if ( empty( $info['enum'] ) && empty( $info['row'] ) ) {
				continue;
			}
			++$checked;

			$this->assertSame(
				array(),
				array_values( array_diff( $info['enum'], $info['row'] ) ),
				sprintf( '%s advertises fields its row does not contain.', $name )
			);
			$this->assertSame(
				array(),
				array_values( array_diff( $info['row'], $info['enum'] ) ),
				sprintf( '%s returns fields callers cannot ask for by name.', $name )
			);
		}

		$this->assertSame( 3, $checked, 'Expected get-posts, get-pages and get-media to support field selection.' );
	}

	/**
	 * Every name passed to $wants() must be a real key of that ability's row.
	 *
	 * A typo here does not raise anything — it just makes the guarded lookup
	 * unconditional or dead.
	 *
	 * @return void
	 */
	public function testEveryWantsReferenceIsARealField(): void {
		foreach ( mswpa_test_parse_registrations() as $name => $info ) {
			if ( empty( $info['wants'] ) ) {
				continue;
			}
			$this->assertSame(
				array(),
				array_values( array_diff( $info['wants'], $info['row'] ) ),
				sprintf( '%s guards a field name that its row does not define.', $name )
			);
		}
	}

	/**
	 * A field holding null is kept when requested — null is a value, not absence.
	 *
	 * @return void
	 */
	public function testNullValuedFieldIsKept(): void {
		$row = array(
			'ID'        => 42,
			'edit_link' => null,
		);
		$this->assertSame( $row, mswpa_apply_fields( $row, array( 'ID', 'edit_link' ) ) );
	}
}
