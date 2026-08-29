<?php
/**
 * Optional `fields` input support for the list-returning abilities.
 *
 * WordPress 7.1 added an optional `fields` parameter to core/get-user-info and
 * core/get-environment-info so a caller can ask for a subset of the output
 * properties. This file applies the same convention to the abilities here that
 * return the largest payloads — get-posts, get-pages and get-media — where a
 * full result set is the plugin's biggest consumer of an agent's context window.
 *
 * Unknown field names are rejected by the input schema rather than here: each
 * ability declares `fields.items.enum`, so WP_Ability::validate_input() returns a
 * validation error naming the allowed values before the execute callback runs.
 * That keeps the failure legible instead of silently returning empty rows.
 *
 * No WordPress dependencies, so it can be unit-tested on its own.
 * See tests/AbilityFieldsTest.php.
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize an ability's `fields` input into a clean list of field names.
 *
 * Accepts the raw input value, which may be absent, null, or a non-array if a
 * caller reached execute() by a path that skipped schema validation. Anything
 * unusable normalizes to an empty array, which callers treat as "all fields".
 *
 * @param mixed $raw Raw `fields` input value.
 * @return string[] Requested field names, de-duplicated and re-indexed.
 */
function mswpa_requested_fields( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$fields = array();
	foreach ( $raw as $field ) {
		if ( is_string( $field ) && '' !== $field ) {
			$fields[] = $field;
		}
	}
	return array_values( array_unique( $fields ) );
}

/**
 * Reduce one result row to the requested fields.
 *
 * An empty `$fields` list means no selection was made, so the row is returned
 * whole. `$always` names keys that survive selection regardless — the abilities
 * pass their identifier there, because a row an agent cannot map back to a post
 * or attachment ID forces a second query and defeats the point of asking for
 * fewer fields in the first place. Each ability documents this in its schema.
 *
 * Key order follows the row, not the request, so output shape stays stable
 * across calls no matter what order the fields were asked for.
 *
 * @param array    $row    A single result row.
 * @param string[] $fields Requested field names. Empty means all.
 * @param string[] $always Field names to keep regardless of the request.
 * @return array The row, reduced to the selected fields.
 */
function mswpa_apply_fields( array $row, array $fields, array $always = array() ): array {
	if ( empty( $fields ) ) {
		return $row;
	}
	$keep = array_flip( array_merge( $fields, $always ) );
	return array_intersect_key( $row, $keep );
}
