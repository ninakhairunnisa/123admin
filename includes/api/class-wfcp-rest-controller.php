<?php
/**
 * Base REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for all wfcp/v1 REST controllers.
 *
 * Security model per request:
 *  1. Cookie authentication + wp_rest nonce (CSRF) — handled by core,
 *     rejected requests never reach the callback.
 *  2. Granular wfcp_* capability check in permission_callback.
 *  3. Rate limiting on write/export endpoints.
 *  4. Audit logging of every mutation.
 */
abstract class WFCP_REST_Controller {

	protected string $namespace = 'wfcp/v1';
	protected string $rest_base = '';

	/**
	 * Registers the controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Builds a permission callback that checks a wfcp_* capability and,
	 * for unsafe methods, applies rate limiting.
	 *
	 * @param string $capability Required capability.
	 */
	protected function permission( string $capability ): callable {
		return function ( WP_REST_Request $request ) use ( $capability ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'wfcp_unauthorized', __( 'Authentication required.', 'wfcp' ), array( 'status' => 401 ) );
			}
			if ( ! current_user_can( $capability ) || ! current_user_can( WFCP_Capabilities::ACCESS ) ) {
				return new WP_Error( 'wfcp_forbidden', __( 'You do not have permission to do this.', 'wfcp' ), array( 'status' => 403 ) );
			}
			if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
				$limited = wfcp()->security->rate_limit( 'write', 120, 60 );
				if ( is_wp_error( $limited ) ) {
					return $limited;
				}
			}
			return true;
		};
	}

	/**
	 * Registers a route relative to the controller base.
	 *
	 * @param string $route Route regex, may be ''.
	 * @param array  $args  Endpoint args (single or list of endpoints).
	 */
	protected function route( string $route, array $args ): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . $route, $args );
	}

	/**
	 * Records a mutation in the audit log.
	 */
	protected function audit( string $action, string $object_type, int $object_id = 0, array $details = array() ): void {
		wfcp()->audit_log->record( $action, $object_type, $object_id, $details );
	}

	/**
	 * Standard pagination params from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array{page:int, per_page:int}
	 */
	protected function pagination( WP_REST_Request $request ): array {
		return array(
			'page'     => max( 1, (int) $request->get_param( 'page' ) ),
			'per_page' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: wfcp()->settings->get( 'per_page', 25 ) ) ) ),
		);
	}

	/**
	 * Wraps a list payload with pagination meta.
	 */
	protected function list_response( array $items, int $total, int $page, int $per_page ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Whether WooCommerce HPOS (custom order tables) is the active order storage.
	 */
	protected function hpos_enabled(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Order counts per (unprefixed) status in a single GROUP BY query,
	 * briefly cached. Works on both HPOS and legacy storage.
	 *
	 * @return array<string, int>
	 */
	protected function order_status_counts(): array {
		$cached = get_transient( 'wfcp_status_counts' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $this->hpos_enabled()
			? $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' GROUP BY status", ARRAY_A )
			: $wpdb->get_results( "SELECT post_status AS status, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = 'shop_order' GROUP BY post_status", ARRAY_A );
		// phpcs:enable

		$counts = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
			$counts[ substr( $key, 3 ) ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			$key = str_starts_with( (string) $row['status'], 'wc-' ) ? substr( $row['status'], 3 ) : (string) $row['status'];
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = (int) $row['total'];
			}
		}
		unset( $counts['checkout-draft'] );

		set_transient( 'wfcp_status_counts', $counts, MINUTE_IN_SECONDS );

		return $counts;
	}

	/**
	 * SUM of order totals for the given prefixed statuses, optionally from a
	 * timestamp, computed in SQL on either storage.
	 *
	 * @param int      $from_ts  Site-local timestamp lower bound (0 = all time).
	 * @param string[] $statuses Prefixed statuses, e.g. ['wc-completed'].
	 */
	protected function sum_orders_total( int $from_ts, array $statuses ): float {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = $statuses;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $this->hpos_enabled() ) {
			$sql = "SELECT SUM(total_amount) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status IN ({$placeholders})";
			if ( $from_ts ) {
				$sql   .= ' AND date_created_gmt >= %s';
				$args[] = gmdate( 'Y-m-d H:i:s', $from_ts );
			}
		} else {
			$sql = "SELECT SUM(pm.meta_value + 0) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ({$placeholders})";
			if ( $from_ts ) {
				$sql   .= ' AND p.post_date >= %s';
				$args[] = wp_date( 'Y-m-d H:i:s', $from_ts );
			}
		}

		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable
	}

	/**
	 * Builds a CSV string from rows (with UTF-8 BOM so Excel renders Persian correctly).
	 * String cells starting with a formula trigger are neutralised so the file
	 * is safe to open in Excel (CSV/formula-injection protection).
	 *
	 * @param string[] $headers Column headers.
	 * @param array[]  $rows    Data rows.
	 */
	protected function to_csv( array $headers, array $rows ): string {
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $headers, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( $this, 'csv_cell' ), $row ), ',', '"', '\\' );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $csv;
	}

	/**
	 * Neutralises spreadsheet formula triggers (=, +, -, @, tab, CR) at the
	 * start of user-influenced string cells.
	 *
	 * @param mixed $value Cell value.
	 */
	protected function csv_cell( $value ) {
		if ( is_string( $value ) && '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
