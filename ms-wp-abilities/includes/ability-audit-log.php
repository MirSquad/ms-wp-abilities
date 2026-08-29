<?php
/**
 * Audit trail for write-ability invocations.
 *
 * Built on the `wp_ability_invoked` action added in WordPress 7.1, which fires at
 * the top of WP_Ability::execute() — before input normalization, before input
 * validation, and before the permission check. That timing is the whole reason
 * this exists. It records the calls that were then refused: a write the
 * rest-write hard-block guard rejected, or one that failed its permission check,
 * leaves no other trace anywhere in this plugin. Those are exactly the
 * invocations worth being able to look back at.
 *
 * Only write abilities are recorded, per the `write` flag in the policy table.
 * Read abilities would put a database write behind every agent query and tell an
 * investigation nothing.
 *
 * Recorded input is redacted by construction: key names always, values only for
 * the short identifier allowlist below. Post content, block markup, meta values
 * and REST bodies are never stored — they are the bulk of the payload and the
 * part most likely to be sensitive, and the ability name plus identifiers is
 * enough to reconstruct what happened from the post's own revision history.
 *
 * No WordPress dependencies, so it can be unit-tested on its own.
 * See tests/AbilityAuditLogTest.php.
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum number of entries kept in the log.
 *
 * The log lives in a single non-autoloaded option, so this bounds both its size
 * and the cost of reading it. Writes are rare by construction — only write
 * abilities are recorded.
 */
const MSWPA_AUDIT_LOG_MAX = 100;

/**
 * Maximum stored length of any single recorded value.
 */
const MSWPA_AUDIT_VALUE_MAX = 200;

/**
 * Input keys whose values are safe to record.
 *
 * Everything else contributes its key name only. These are identifiers — they
 * say which thing was acted on, not what was written to it.
 *
 * @return string[] Allowlisted input key names.
 */
function mswpa_audit_loggable_keys(): array {
	return array(
		'post_id',
		'attachment_id',
		'route',
		'method',
		'slug',
		'status',
		'post_type',
		'taxonomy',
		'name',
		'activate',
	);
}

/**
 * Build a redacted summary of an ability's input.
 *
 * @param mixed $input Raw ability input, before normalization.
 * @return array{keys: string[], values: array<string, string>} Redacted summary.
 */
function mswpa_summarize_ability_input( $input ): array {
	if ( ! is_array( $input ) ) {
		return array(
			'keys'   => array(),
			'values' => array(),
		);
	}

	$loggable = mswpa_audit_loggable_keys();
	$keys     = array();
	$values   = array();

	foreach ( $input as $key => $value ) {
		if ( ! is_string( $key ) ) {
			continue;
		}
		$keys[] = $key;

		if ( ! in_array( $key, $loggable, true ) ) {
			continue;
		}
		if ( is_bool( $value ) ) {
			$values[ $key ] = $value ? 'true' : 'false';
			continue;
		}
		if ( is_scalar( $value ) ) {
			$values[ $key ] = mb_substr( (string) $value, 0, MSWPA_AUDIT_VALUE_MAX );
		}
	}

	return array(
		'keys'   => $keys,
		'values' => $values,
	);
}

/**
 * Build one audit log entry.
 *
 * @param string $ability_name Ability that was invoked.
 * @param mixed  $input        Raw ability input, before normalization.
 * @param int    $user_id      Acting user ID. 0 when there is no current user.
 * @param int    $timestamp    Unix timestamp of the invocation.
 * @return array The entry to store.
 */
function mswpa_audit_entry( string $ability_name, $input, int $user_id, int $timestamp ): array {
	$summary = mswpa_summarize_ability_input( $input );

	return array(
		'ability' => $ability_name,
		'user'    => $user_id,
		'time'    => $timestamp,
		'keys'    => $summary['keys'],
		'values'  => $summary['values'],
	);
}

/**
 * Append an entry to the log, newest first, capped at `$max`.
 *
 * Newest first so the admin page can render the head of the array without
 * reversing it, and so the cap discards the oldest entries.
 *
 * @param array $log   Existing log entries.
 * @param array $entry Entry to prepend.
 * @param int   $max   Maximum entries to keep.
 * @return array The capped log.
 */
function mswpa_audit_append( array $log, array $entry, int $max = MSWPA_AUDIT_LOG_MAX ): array {
	array_unshift( $log, $entry );
	if ( $max > 0 && count( $log ) > $max ) {
		$log = array_slice( $log, 0, $max );
	}
	return $log;
}
