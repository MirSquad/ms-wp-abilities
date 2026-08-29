<?php
/**
 * Tests for the write-ability audit log.
 *
 * The redaction tests are the load-bearing ones: the log's whole premise is that
 * it can be kept in a site option and read on an admin page because it never
 * holds post content, REST bodies, or meta values. If that stops being true the
 * feature stops being safe, so it is asserted rather than assumed.
 *
 * @package MS_WP_Abilities
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers mswpa_summarize_ability_input(), mswpa_audit_entry() and mswpa_audit_append().
 */
class AbilityAuditLogTest extends TestCase {

	// ---------------------------------------------------------------------
	// Redaction.
	// ---------------------------------------------------------------------

	/**
	 * Identifier fields are recorded with their values.
	 *
	 * @return void
	 */
	public function testIdentifierValuesAreRecorded(): void {
		$summary = mswpa_summarize_ability_input(
			array(
				'route'  => '/wp/v2/posts/42',
				'method' => 'DELETE',
			)
		);
		$this->assertSame(
			array(
				'route'  => '/wp/v2/posts/42',
				'method' => 'DELETE',
			),
			$summary['values']
		);
	}

	/**
	 * Content-bearing fields contribute their key name and nothing else.
	 *
	 * @dataProvider sensitiveFields
	 *
	 * @param string $key   Input key that must never have its value stored.
	 * @param mixed  $value A value for that key.
	 * @return void
	 */
	public function testContentValuesAreNeverRecorded( string $key, $value ): void {
		$summary = mswpa_summarize_ability_input( array( $key => $value ) );

		$this->assertSame( array( $key ), $summary['keys'], 'The key itself should still be recorded.' );
		$this->assertArrayNotHasKey( $key, $summary['values'], sprintf( '%s must not have its value stored.', $key ) );
	}

	/**
	 * Input keys whose values must never reach the log.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function sensitiveFields(): array {
		return array(
			'post content' => array( 'content', '<!-- wp:paragraph --><p>Secret</p><!-- /wp:paragraph -->' ),
			'markdown'     => array( 'markdown', '# Draft of unannounced thing' ),
			'rest body'    => array( 'body', array( 'password' => 'hunter2' ) ),
			'meta'         => array( 'meta', array( 'private_key' => 'abc' ) ),
			'excerpt'      => array( 'excerpt', 'A summary' ),
			'title'        => array( 'title', 'An unpublished headline' ),
			'find string'  => array( 'find', 'internal codename' ),
			'replacement'  => array( 'replace', 'other internal codename' ),
		);
	}

	/**
	 * A long identifier value is truncated to the storage cap.
	 *
	 * @return void
	 */
	public function testLongValuesAreTruncated(): void {
		$summary = mswpa_summarize_ability_input( array( 'route' => str_repeat( 'a', 500 ) ) );
		$this->assertSame( MSWPA_AUDIT_VALUE_MAX, strlen( $summary['values']['route'] ) );
	}

	/**
	 * Booleans are stored readably rather than as an empty string.
	 *
	 * @return void
	 */
	public function testBooleansAreStoredAsWords(): void {
		$summary = mswpa_summarize_ability_input( array( 'activate' => false ) );
		$this->assertSame( 'false', $summary['values']['activate'] );

		$summary = mswpa_summarize_ability_input( array( 'activate' => true ) );
		$this->assertSame( 'true', $summary['values']['activate'] );
	}

	/**
	 * A non-scalar value on an allowlisted key is dropped, not serialized.
	 *
	 * @return void
	 */
	public function testNonScalarAllowlistedValueIsDropped(): void {
		$summary = mswpa_summarize_ability_input( array( 'route' => array( 'nested' => 'value' ) ) );
		$this->assertSame( array( 'route' ), $summary['keys'] );
		$this->assertSame( array(), $summary['values'] );
	}

	/**
	 * Non-array input produces an empty summary rather than an error.
	 *
	 * @return void
	 */
	public function testNonArrayInputSummarizesEmpty(): void {
		foreach ( array( null, 'string', 5, true ) as $input ) {
			$summary = mswpa_summarize_ability_input( $input );
			$this->assertSame( array(), $summary['keys'] );
			$this->assertSame( array(), $summary['values'] );
		}
	}

	/**
	 * Numeric keys are skipped — they carry no meaning in a log line.
	 *
	 * @return void
	 */
	public function testNumericKeysAreSkipped(): void {
		$summary = mswpa_summarize_ability_input( array( 'a', 'b' ) );
		$this->assertSame( array(), $summary['keys'] );
	}

	// ---------------------------------------------------------------------
	// Entries.
	// ---------------------------------------------------------------------

	/**
	 * An entry carries the ability, user, time, and the redacted summary.
	 *
	 * @return void
	 */
	public function testEntryShape(): void {
		$entry = mswpa_audit_entry(
			'miriamschwab/rest-write',
			array(
				'route'  => '/wp/v2/settings',
				'method' => 'POST',
				'body'   => array( 'admin_email' => 'x@example.com' ),
			),
			7,
			1700000000
		);

		$this->assertSame( 'miriamschwab/rest-write', $entry['ability'] );
		$this->assertSame( 7, $entry['user'] );
		$this->assertSame( 1700000000, $entry['time'] );
		$this->assertSame( array( 'route', 'method', 'body' ), $entry['keys'] );
		$this->assertSame(
			array(
				'route'  => '/wp/v2/settings',
				'method' => 'POST',
			),
			$entry['values']
		);
		$this->assertStringNotContainsString( 'x@example.com', wp_json_encode_compat( $entry ) );
	}

	// ---------------------------------------------------------------------
	// Allowlist coverage — the drift guard.
	//
	// v1.11.0 shipped an allowlist written from assumption rather than from the
	// input schemas: `ID` and `plugin_file` were missing, so the log recorded
	// that a plugin was activated or media meta changed without recording which,
	// and an `attachment_id` key no ability uses was present. These tests exist
	// so that class of mistake fails the build instead of shipping.
	// ---------------------------------------------------------------------

	/**
	 * Every required parameter of every write ability must be classified.
	 *
	 * Either it identifies what was acted on (loggable) or it carries content
	 * (deliberately excluded). A new required parameter that is neither means
	 * somebody added a write ability without deciding what the audit trail
	 * should say about it.
	 *
	 * @return void
	 */
	public function testEveryWriteAbilityRequiredParamIsClassified(): void {
		$loggable = mswpa_audit_loggable_keys();
		$content  = mswpa_audit_content_keys();
		$checked  = 0;

		foreach ( mswpa_test_parse_registrations() as $name => $info ) {
			if ( ! mswpa_is_write_ability( $name ) ) {
				continue;
			}
			foreach ( $info['required'] as $param ) {
				++$checked;
				$this->assertTrue(
					in_array( $param, $loggable, true ) || in_array( $param, $content, true ),
					sprintf(
						'%s requires "%s", which is in neither mswpa_audit_loggable_keys() nor mswpa_audit_content_keys(). '
							. 'Decide which it is: an identifier worth recording, or content that must never be stored.',
						$name,
						$param
					)
				);
			}
		}

		$this->assertGreaterThan( 0, $checked, 'Checked no parameters — the registration parser has drifted.' );
	}

	/**
	 * Every write ability must have at least one loggable identifier.
	 *
	 * An entry naming only the ability and the user, with no indication of what
	 * it acted on, is the failure this whole section is about. create-post is the
	 * documented exception: it creates rather than targets, so it has no
	 * identifier at call time.
	 *
	 * @return void
	 */
	public function testEveryWriteAbilityRecordsSomeIdentifier(): void {
		$loggable = mswpa_audit_loggable_keys();

		foreach ( mswpa_test_parse_registrations() as $name => $info ) {
			if ( ! mswpa_is_write_ability( $name ) || 'miriamschwab/create-post' === $name ) {
				continue;
			}
			$this->assertNotEmpty(
				array_intersect( $info['props'], $loggable ),
				sprintf( '%s would be logged with no identifier at all.', $name )
			);
		}
	}

	/**
	 * The allowlist must not name keys no write ability actually accepts.
	 *
	 * A dead entry advertises coverage that does not exist — which is how the
	 * missing `ID` went unnoticed next to a useless `attachment_id`.
	 *
	 * @return void
	 */
	public function testAllowlistHasNoUnusedKeys(): void {
		$all_props = array();
		foreach ( mswpa_test_parse_registrations() as $name => $info ) {
			if ( mswpa_is_write_ability( $name ) ) {
				$all_props = array_merge( $all_props, $info['props'] );
			}
		}

		$unused = array_values( array_diff( mswpa_audit_loggable_keys(), $all_props ) );
		$this->assertSame(
			array(),
			$unused,
			'Allowlisted keys that no write ability accepts: ' . implode( ', ', $unused )
		);
	}

	/**
	 * A key cannot be both an identifier and content.
	 *
	 * @return void
	 */
	public function testLoggableAndContentKeysDoNotOverlap(): void {
		$this->assertSame(
			array(),
			array_values( array_intersect( mswpa_audit_loggable_keys(), mswpa_audit_content_keys() ) )
		);
	}

	/**
	 * The three identifiers v1.11.0 missed are recorded.
	 *
	 * Named explicitly so the specific regression stays covered even if the
	 * generic tests above are ever loosened.
	 *
	 * @return void
	 */
	public function testPreviouslyMissedIdentifiersAreRecorded(): void {
		$summary = mswpa_summarize_ability_input(
			array(
				'ID'          => 9362,
				'plugin_file' => 'woocommerce/woocommerce.php',
			)
		);
		$this->assertSame( '9362', $summary['values']['ID'] );
		$this->assertSame( 'woocommerce/woocommerce.php', $summary['values']['plugin_file'] );
	}

	// ---------------------------------------------------------------------
	// Ring buffer.
	// ---------------------------------------------------------------------

	/**
	 * New entries go to the front.
	 *
	 * @return void
	 */
	public function testNewestEntryIsFirst(): void {
		$log = mswpa_audit_append( array(), array( 'ability' => 'first' ) );
		$log = mswpa_audit_append( $log, array( 'ability' => 'second' ) );

		$this->assertSame( 'second', $log[0]['ability'] );
		$this->assertSame( 'first', $log[1]['ability'] );
	}

	/**
	 * The log is capped, and it is the oldest entries that are discarded.
	 *
	 * @return void
	 */
	public function testLogIsCappedAndDropsOldest(): void {
		$log = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$log = mswpa_audit_append( $log, array( 'ability' => 'entry-' . $i ), 3 );
		}

		$this->assertCount( 3, $log );
		$this->assertSame( 'entry-9', $log[0]['ability'] );
		$this->assertSame( 'entry-7', $log[2]['ability'] );
	}

	/**
	 * The default cap is the declared maximum.
	 *
	 * @return void
	 */
	public function testDefaultCapIsTheDeclaredMaximum(): void {
		$log = array();
		for ( $i = 0; $i < MSWPA_AUDIT_LOG_MAX + 25; $i++ ) {
			$log = mswpa_audit_append( $log, array( 'ability' => 'entry-' . $i ) );
		}
		$this->assertCount( MSWPA_AUDIT_LOG_MAX, $log );
	}
}
