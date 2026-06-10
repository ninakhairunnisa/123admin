<?php
/**
 * Uninstall routine: removes all plugin data.
 *
 * @package WFCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Options.
delete_option( 'wfcp_settings' );
delete_option( 'wfcp_active_slug' );
delete_option( 'wfcp_flush_rewrite' );
delete_option( 'wfcp_db_version' );

// Transients (cached dashboards/reports/rate-limit buckets).
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wfcp\_%' OR option_name LIKE '\_transient\_timeout\_wfcp\_%'" );

// Audit log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wfcp_audit_log" );

// User meta added by the panel.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('wfcp_blocked', 'wfcp_last_login', 'wfcp_notes')" );
// phpcs:enable

flush_rewrite_rules();
