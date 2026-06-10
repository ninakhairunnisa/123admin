<?php
/**
 * Audit log.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records every state-changing panel action for accountability.
 */
class WFCP_Audit_Log {

	/**
	 * Writes an audit entry.
	 *
	 * @param string $action      Machine action key, e.g. product.update.
	 * @param string $object_type Object type (product|order|user|settings).
	 * @param int    $object_id   Related object ID.
	 * @param array  $details     Extra context, stored as JSON.
	 */
	public function record( string $action, string $object_type = '', int $object_id = 0, array $details = array() ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wfcp_audit_log',
			array(
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 64 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => $object_id,
				'details'     => wp_json_encode( $details ),
				'ip'          => self::client_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		/**
		 * Fires after an audit-log entry has been recorded.
		 *
		 * @param string $action      Action key.
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 * @param array  $details     Context details.
		 */
		do_action( 'wfcp_audit_recorded', $action, $object_type, $object_id, $details );
	}

	/**
	 * Returns recent audit entries.
	 *
	 * @param int $limit  Number of rows.
	 * @param int $offset Offset.
	 */
	public function recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, user_id, action, object_type, object_id, details, ip, created_at
				 FROM {$wpdb->prefix}wfcp_audit_log
				 ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$user                = get_userdata( (int) $row['user_id'] );
				$row['user_name']    = $user ? $user->display_name : __( 'System', 'wfcp' );
				$row['details']      = json_decode( (string) $row['details'], true );
				$row['created_at']   = mysql2date( 'c', $row['created_at'], false );
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * Best-effort client IP (proxy-aware headers are intentionally not trusted).
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}
}
