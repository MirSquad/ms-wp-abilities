<?php
/**
 * Uninstall routine for MS WordPress Abilities.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * via Plugins > Delete in wp-admin. Does NOT run on deactivation.
 *
 * Data removed:
 *   _mswpa_pending_update_{post_id} — staged post update stored in user meta
 *                                     (key suffix is a post ID; LIKE query removes all)
 *   mswpa_abilities_snapshot        — last-seen ability list, for the "what's new since
 *                                     your last visit" notice on the WP Abilities page
 *   mswpa_write_log                 — audit trail of write-ability invocations
 *
 * @package MS_WP_Abilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all staged pending-update user meta entries (key suffix varies by post ID).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall context, no alternative.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_mswpa_pending_update_' ) . '%'
	)
);

delete_option( 'mswpa_abilities_snapshot' );
delete_option( 'mswpa_write_log' );
