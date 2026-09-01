<?php
/**
 * Tests for the MCP server ability catalog.
 *
 * @package MS_WP_Abilities
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers mswpa_ability_is_mcp_public(), mswpa_catalog_one_line(),
 * mswpa_catalog_line() and mswpa_build_ability_catalog().
 */
class AbilityCatalogTest extends TestCase {

	/**
	 * Build a list of catalog rows with controllable field lengths.
	 *
	 * @param int $count       How many rows.
	 * @param int $name_len    Length of each generated name.
	 * @param int $label_len   Length of each generated label.
	 * @param int $desc_len    Length of each generated description.
	 * @return array[] Catalog rows.
	 */
	private function rows( int $count, int $name_len, int $label_len, int $desc_len ): array {
		$rows = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = array(
				'name'        => str_pad( 'ns/a' . $i, $name_len, 'x' ),
				'label'       => str_pad( 'L' . $i, $label_len, 'y' ),
				'description' => str_pad( 'D' . $i, $desc_len, 'z' ),
			);
		}
		return $rows;
	}

	// ---------------------------------------------------------------------
	// Exposure resolution.
	// ---------------------------------------------------------------------

	/**
	 * An explicit meta.mcp.public wins over everything else.
	 *
	 * @return void
	 */
	public function testExplicitMcpPublicWins(): void {
		$this->assertTrue( mswpa_ability_is_mcp_public( array( 'mcp' => array( 'public' => true ) ) ) );
		$this->assertFalse( mswpa_ability_is_mcp_public( array( 'mcp' => array( 'public' => false ) ) ) );
	}

	/**
	 * An explicit false mcp.public is not overridden by a true meta.public.
	 *
	 * This is the pairing that decides whether an ability deliberately kept off
	 * MCP can be dragged back on by the high-level flag. It must not be.
	 *
	 * @return void
	 */
	public function testExplicitMcpPublicFalseBeatsMetaPublicTrue(): void {
		$meta = array(
			'mcp'    => array( 'public' => false ),
			'public' => true,
		);
		$this->assertFalse( mswpa_ability_is_mcp_public( $meta ) );
	}

	/**
	 * With no mcp.public, the WordPress 7.1 meta.public flag is the fallback.
	 *
	 * @return void
	 */
	public function testFallsBackToMetaPublic(): void {
		$this->assertTrue( mswpa_ability_is_mcp_public( array( 'public' => true ) ) );
		$this->assertFalse( mswpa_ability_is_mcp_public( array( 'public' => false ) ) );
		$this->assertFalse( mswpa_ability_is_mcp_public( array() ) );
	}

	/**
	 * The meta.public fallback is strict: only boolean true counts.
	 *
	 * A loose check would expose abilities on any truthy value — `1`, `"yes"` —
	 * which is the over-exposure direction and the one that matters.
	 *
	 * @dataProvider truthyNonTrueValues
	 *
	 * @param mixed $value Truthy value that is not boolean true.
	 * @return void
	 */
	public function testMetaPublicFallbackIsStrict( $value ): void {
		$this->assertFalse( mswpa_ability_is_mcp_public( array( 'public' => $value ) ) );
	}

	/**
	 * Truthy values that must still resolve to not-exposed.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function truthyNonTrueValues(): array {
		return array(
			'integer one'  => array( 1 ),
			'string one'   => array( '1' ),
			'string true'  => array( 'true' ),
			'string yes'   => array( 'yes' ),
			'non-empty arr' => array( array( 'x' ) ),
		);
	}

	/**
	 * A malformed meta.mcp fails closed rather than falling through.
	 *
	 * Falling through to meta.public here would expose an ability whose MCP
	 * metadata is corrupt — exactly the case where least privilege applies.
	 *
	 * @return void
	 */
	public function testMalformedMcpMetaFailsClosed(): void {
		$meta = array(
			'mcp'    => 'not-an-array',
			'public' => true,
		);
		$this->assertFalse( mswpa_ability_is_mcp_public( $meta ) );
	}

	/**
	 * When a delegate is supplied it decides, and the local rule is not consulted.
	 *
	 * @return void
	 */
	public function testDelegateOverridesLocalRule(): void {
		$always_true  = static fn( array $meta ): bool => true;
		$always_false = static fn( array $meta ): bool => false;

		// Local rule would say false; delegate says true.
		$this->assertTrue( mswpa_ability_is_mcp_public( array(), $always_true ) );
		// Local rule would say true; delegate says false.
		$this->assertFalse(
			mswpa_ability_is_mcp_public( array( 'mcp' => array( 'public' => true ) ), $always_false )
		);
	}

	/**
	 * An ability with no declared MCP type is a tool, matching the adapter's default.
	 *
	 * @return void
	 */
	public function testMissingTypeDefaultsToTool(): void {
		$this->assertTrue( mswpa_ability_is_mcp_tool( array() ) );
		$this->assertTrue( mswpa_ability_is_mcp_tool( array( 'mcp' => array( 'public' => true ) ) ) );
	}

	/**
	 * Resources and prompts are not tools.
	 *
	 * They are reached through different MCP methods, so listing them under an
	 * instruction to call execute-ability would send an agent down a dead end.
	 *
	 * @dataProvider nonToolTypes
	 *
	 * @param string $type Declared mcp.type.
	 * @return void
	 */
	public function testResourcesAndPromptsAreNotTools( string $type ): void {
		$meta = array( 'mcp' => array( 'type' => $type ) );
		$this->assertFalse( mswpa_ability_is_mcp_tool( $meta ) );
	}

	/**
	 * MCP types that are not tools.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function nonToolTypes(): array {
		return array(
			'resource' => array( 'resource' ),
			'prompt'   => array( 'prompt' ),
		);
	}

	/**
	 * An explicit tool type is a tool.
	 *
	 * @return void
	 */
	public function testExplicitToolTypeIsTool(): void {
		$this->assertTrue( mswpa_ability_is_mcp_tool( array( 'mcp' => array( 'type' => 'tool' ) ) ) );
	}

	/**
	 * Malformed meta.mcp fails closed here too.
	 *
	 * @return void
	 */
	public function testMalformedMcpMetaIsNotATool(): void {
		$this->assertFalse( mswpa_ability_is_mcp_tool( array( 'mcp' => 'not-an-array' ) ) );
	}

	// ---------------------------------------------------------------------
	// Line rendering.
	// ---------------------------------------------------------------------

	/**
	 * Multi-line text collapses to a single line.
	 *
	 * The catalog is one ability per line. A newline inside a description would
	 * split one entry into two, and the tail would read as another ability name.
	 *
	 * @return void
	 */
	public function testMultilineTextCollapsesToOneLine(): void {
		$this->assertSame(
			'First sentence. Second sentence.',
			mswpa_catalog_one_line( "First sentence.\n\n  Second   sentence.\t" )
		);
	}

	/**
	 * Non-strings normalize to an empty string.
	 *
	 * @return void
	 */
	public function testNonStringCollapsesToEmpty(): void {
		$this->assertSame( '', mswpa_catalog_one_line( null ) );
		$this->assertSame( '', mswpa_catalog_one_line( array( 'x' ) ) );
	}

	/**
	 * A line with no detail is just the name, with no dangling separator.
	 *
	 * @return void
	 */
	public function testLineWithoutDetailHasNoSeparator(): void {
		$this->assertSame( 'ns/thing', mswpa_catalog_line( 'ns/thing', '' ) );
		$this->assertSame( 'ns/thing', mswpa_catalog_line( 'ns/thing', "  \n " ) );
	}

	/**
	 * A row with no usable name renders as nothing.
	 *
	 * @return void
	 */
	public function testLineWithoutNameIsEmpty(): void {
		$this->assertSame( '', mswpa_catalog_line( '', 'some description' ) );
	}

	// ---------------------------------------------------------------------
	// Catalog assembly and budget degradation.
	// ---------------------------------------------------------------------

	/**
	 * An empty ability list produces no catalog at all.
	 *
	 * @return void
	 */
	public function testEmptyRowsProduceEmptyCatalog(): void {
		$this->assertSame( '', mswpa_build_ability_catalog( array() ) );
	}

	/**
	 * A small set renders at full fidelity, one ability per line.
	 *
	 * @return void
	 */
	public function testSmallSetRendersNameAndDescription(): void {
		$rows = array(
			array(
				'name'        => 'ns/alpha',
				'label'       => 'Alpha',
				'description' => 'Does the alpha thing.',
			),
			array(
				'name'        => 'ns/beta',
				'label'       => 'Beta',
				'description' => 'Does the beta thing.',
			),
		);

		$catalog = mswpa_build_ability_catalog( $rows );

		$this->assertStringContainsString( 'execute-ability', $catalog );
		$this->assertStringContainsString( "ns/alpha — Does the alpha thing.", $catalog );
		$this->assertStringContainsString( "ns/beta — Does the beta thing.", $catalog );
		$this->assertStringEndsWith( "ns/beta — Does the beta thing.", $catalog );
	}

	/**
	 * Every ability appears exactly once, at every tier.
	 *
	 * @return void
	 */
	public function testEveryAbilityIsListedOnce(): void {
		$rows    = $this->rows( 12, 10, 10, 10 );
		$catalog = mswpa_build_ability_catalog( $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( 1, substr_count( $catalog, $row['name'] ), $row['name'] . ' listed once' );
		}
	}

	/**
	 * Oversized descriptions degrade the whole catalog to name and label.
	 *
	 * @return void
	 */
	public function testOversizedDescriptionsDegradeToLabels(): void {
		$rows    = $this->rows( 40, 20, 20, 600 );
		$catalog = mswpa_build_ability_catalog( $rows );

		$this->assertNotSame( '', $catalog );
		$this->assertLessThanOrEqual( MSWPA_CATALOG_MAX_CHARS, strlen( $catalog ) );
		$this->assertStringContainsString( $rows[0]['label'], $catalog );
		$this->assertStringNotContainsString( $rows[0]['description'], $catalog );
	}

	/**
	 * Oversized labels degrade further, to bare names.
	 *
	 * @return void
	 */
	public function testOversizedLabelsDegradeToNames(): void {
		$rows    = $this->rows( 40, 20, 600, 600 );
		$catalog = mswpa_build_ability_catalog( $rows );

		$this->assertNotSame( '', $catalog );
		$this->assertLessThanOrEqual( MSWPA_CATALOG_MAX_CHARS, strlen( $catalog ) );
		$this->assertStringContainsString( $rows[0]['name'], $catalog );
		$this->assertStringNotContainsString( $rows[0]['label'], $catalog );

		// The preamble legitimately contains an em dash, so inspect the list only:
		// at this tier every listed line must be a bare ability name.
		foreach ( $this->listedLines( $catalog ) as $line ) {
			$this->assertStringNotContainsString( ' — ', $line );
		}
	}

	/**
	 * The ability lines of a catalog, with the preamble removed.
	 *
	 * @param string $catalog Rendered catalog.
	 * @return string[] One entry per listed ability.
	 */
	private function listedLines( string $catalog ): array {
		$parts = explode( "\n\n", $catalog, 2 );
		return isset( $parts[1] ) ? explode( "\n", $parts[1] ) : array();
	}

	/**
	 * When even bare names exceed the budget, no catalog is produced.
	 *
	 * A truncated list would be indistinguishable from a site that simply has
	 * fewer abilities, so the caller is left to keep the stock description.
	 *
	 * @return void
	 */
	public function testNamesAloneOverBudgetProduceNoCatalog(): void {
		$rows = $this->rows( 300, 80, 10, 10 );
		$this->assertSame( '', mswpa_build_ability_catalog( $rows ) );
	}

	/**
	 * Every tier that returns a catalog respects the budget.
	 *
	 * @dataProvider budgetShapes
	 *
	 * @param int $count    Row count.
	 * @param int $name_len Name length.
	 * @param int $label    Label length.
	 * @param int $desc     Description length.
	 * @return void
	 */
	public function testCatalogNeverExceedsBudget( int $count, int $name_len, int $label, int $desc ): void {
		$catalog = mswpa_build_ability_catalog( $this->rows( $count, $name_len, $label, $desc ) );
		$this->assertLessThanOrEqual( MSWPA_CATALOG_MAX_CHARS, strlen( $catalog ) );
	}

	/**
	 * Row shapes spanning all four outcomes.
	 *
	 * @return array<string, array{0:int,1:int,2:int,3:int}>
	 */
	public static function budgetShapes(): array {
		return array(
			'tiny'              => array( 3, 10, 10, 20 ),
			'realistic'         => array( 67, 30, 25, 130 ),
			'degrades to label' => array( 40, 20, 20, 600 ),
			'degrades to name'  => array( 40, 20, 600, 600 ),
			'over budget'       => array( 300, 80, 10, 10 ),
		);
	}

	/**
	 * Rows carrying no usable name are skipped rather than rendered as blanks.
	 *
	 * @return void
	 */
	public function testMalformedRowsAreSkipped(): void {
		$rows = array(
			array(
				'name'        => 'ns/good',
				'label'       => 'Good',
				'description' => 'Fine.',
			),
			array(),
			array( 'label' => 'nameless' ),
			array(
				'name'        => 'ns/also-good',
				'label'       => 'Also',
				'description' => 'Also fine.',
			),
		);

		$catalog = mswpa_build_ability_catalog( $rows );

		$this->assertStringContainsString( 'ns/good', $catalog );
		$this->assertStringContainsString( 'ns/also-good', $catalog );
		$this->assertStringNotContainsString( 'nameless', $catalog );
	}

	/**
	 * A list in which no row has a usable name produces no catalog.
	 *
	 * @return void
	 */
	public function testOnlyMalformedRowsProduceEmptyCatalog(): void {
		$rows = array( array(), array( 'label' => 'no name' ), array( 'description' => 'also none' ) );
		$this->assertSame( '', mswpa_build_ability_catalog( $rows ) );
	}

	/**
	 * Missing description falls back to the name alone rather than a stray dash.
	 *
	 * @return void
	 */
	public function testRowMissingDescriptionRendersNameOnly(): void {
		$catalog = mswpa_build_ability_catalog( array( array( 'name' => 'ns/bare' ) ) );
		$this->assertStringEndsWith( 'ns/bare', $catalog );
		$this->assertStringNotContainsString( 'ns/bare — ', $catalog );
	}
}
