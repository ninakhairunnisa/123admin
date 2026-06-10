<?php
/**
 * Activation / deactivation routines.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates database tables and rewrite rules on activation.
 */
class WFCP_Activator {

	/**
	 * Plugin activation: create tables, seed options, flush rewrites.
	 */
	public static function activate(): void {
		self::create_tables();

		if ( false === get_option( WFCP_Settings::OPTION_KEY, false ) ) {
			add_option( WFCP_Settings::OPTION_KEY, ( new WFCP_Settings() )->defaults(), '', false );
		}
		update_option( 'wfcp_active_slug', ( new WFCP_Settings() )->slug(), false );
		update_option( 'wfcp_flush_rewrite', 1, false );
		update_option( 'wfcp_db_version', WFCP_VERSION, false );
	}

	/**
	 * Plugin deactivation: clean rewrite rules.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Creates the audit-log table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'wfcp_audit_log';

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(64) NOT NULL,
				object_type VARCHAR(32) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				details LONGTEXT NULL,
				ip VARCHAR(45) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY action (action),
				KEY object_lookup (object_type, object_id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}
}
