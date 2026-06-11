<?php
/**
 * Dashboard REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aggregated store KPIs for the dashboard, cached briefly for speed.
 */
class WFCP_Dashboard_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'dashboard';

	public function register_routes(): void {
		$this->route(
			'',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => $this->permission( WFCP_Capabilities::ACCESS ),
			)
		);

		$this->route(
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => $this->permission( WFCP_Capabilities::ACCESS ),
				'args'                => array(
					'last_order' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	/**
	 * Full dashboard payload.
	 */
	public function get_dashboard(): WP_REST_Response {
		$cache_key = 'wfcp_dashboard_v1';
		$data      = get_transient( $cache_key );

		if ( ! is_array( $data ) ) {
			$paid_statuses = array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() );
			$tz            = wp_timezone();
			$today_start   = ( new DateTimeImmutable( 'today', $tz ) );
			$week_start    = $today_start->modify( '-6 days' );
			$month_start   = new DateTimeImmutable( 'first day of this month 00:00', $tz );
			$chart_start   = $today_start->modify( '-29 days' );

			// All KPIs are aggregated in SQL; no order objects are hydrated.
			$rows   = $this->chart_rows( $chart_start->getTimestamp(), $paid_statuses );
			$series = array();
			for ( $i = 0; $i < 30; $i++ ) {
				$key            = $chart_start->modify( "+{$i} days" )->format( 'Y-m-d' );
				$series[ $key ] = array(
					'date'   => $key,
					'sales'  => (float) ( $rows[ $key ]['sales'] ?? 0 ),
					'orders' => (int) ( $rows[ $key ]['orders'] ?? 0 ),
				);
			}

			$today_key  = $today_start->format( 'Y-m-d' );
			$week_key   = $week_start->format( 'Y-m-d' );
			$sales_week = 0.0;
			foreach ( $series as $key => $bucket ) {
				if ( $key >= $week_key ) {
					$sales_week += $bucket['sales'];
				}
			}

			$counts = $this->order_status_counts();

			$data = array(
				'sales'      => array(
					'today'         => $series[ $today_key ]['sales'],
					'week'          => $sales_week,
					// Month-to-date as its own SUM: on the 31st the month start
					// falls outside the 30-day chart window.
					'month'         => $this->sum_orders_total( $month_start->getTimestamp(), $paid_statuses ),
					'total_revenue' => $this->total_revenue(),
				),
				'orders'     => array(
					'today'      => $series[ $today_key ]['orders'],
					'pending'    => ( $counts['pending'] ?? 0 ) + ( $counts['on-hold'] ?? 0 ),
					'processing' => $counts['processing'] ?? 0,
					'completed'  => $counts['completed'] ?? 0,
				),
				'customers'  => $this->customers_count(),
				'stock'      => $this->stock_counts(),
				'chart'      => array_values( $series ),
				'recent'     => $this->recent_orders(),
				'last_order' => $this->last_order_id(),
			);

			/**
			 * Filters the dashboard payload (e.g. to add custom KPI widgets).
			 *
			 * @param array $data Dashboard data.
			 */
			$data = apply_filters( 'wfcp_dashboard_data', $data );

			set_transient( $cache_key, $data, 2 * MINUTE_IN_SECONDS );
		}

		$data['activity'] = current_user_can( 'wfcp_reports_view' ) ? wfcp()->audit_log->recent( 10 ) : array();

		return rest_ensure_response( $data );
	}

	/**
	 * Lightweight polling endpoint for live "new order" notifications.
	 */
	public function ping( WP_REST_Request $request ): WP_REST_Response {
		$last  = (int) $request->get_param( 'last_order' );
		$newest = $this->last_order_id();

		return rest_ensure_response(
			array(
				'last_order' => $newest,
				'has_new'    => $last > 0 && $newest > $last,
			)
		);
	}

	private function last_order_id(): int {
		$ids = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Per-day sales (paid statuses) and order counts (paid plus pending and
	 * on-hold) for the chart window, aggregated in one SQL query per storage.
	 *
	 * @param int      $from_ts       Site-local timestamp of the first chart day.
	 * @param string[] $paid_statuses Prefixed paid statuses.
	 *
	 * @return array<string, array{sales: float, orders: int}> Keyed by Y-m-d.
	 */
	private function chart_rows( int $from_ts, array $paid_statuses ): array {
		global $wpdb;

		$all_statuses = array_values( array_unique( array_merge( $paid_statuses, array( 'wc-pending', 'wc-on-hold' ) ) ) );
		$paid_ph      = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$all_ph       = implode( ',', array_fill( 0, count( $all_statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $this->hpos_enabled() ) {
			// HPOS stores UTC; bucket by site-local day via the current offset.
			$offset = wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE_FORMAT(DATE_ADD(date_created_gmt, INTERVAL %d SECOND), '%%Y-%%m-%%d') AS bucket,
					        SUM(CASE WHEN status IN ({$paid_ph}) THEN total_amount ELSE 0 END) AS sales,
					        COUNT(*) AS orders
					 FROM {$wpdb->prefix}wc_orders
					 WHERE type = 'shop_order' AND status IN ({$all_ph}) AND date_created_gmt >= %s
					 GROUP BY bucket",
					array_merge( array( $offset ), $paid_statuses, $all_statuses, array( gmdate( 'Y-m-d H:i:s', $from_ts ) ) )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE_FORMAT(p.post_date, '%%Y-%%m-%%d') AS bucket,
					        SUM(CASE WHEN p.post_status IN ({$paid_ph}) THEN pm.meta_value + 0 ELSE 0 END) AS sales,
					        COUNT(*) AS orders
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
					 WHERE p.post_type = 'shop_order' AND p.post_status IN ({$all_ph}) AND p.post_date >= %s
					 GROUP BY bucket",
					array_merge( $paid_statuses, $all_statuses, array( wp_date( 'Y-m-d H:i:s', $from_ts ) ) )
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		$buckets = array();
		foreach ( (array) $rows as $row ) {
			$buckets[ $row['bucket'] ] = array(
				'sales'  => (float) $row['sales'],
				'orders' => (int) $row['orders'],
			);
		}

		return $buckets;
	}

	/**
	 * Lifetime revenue via a single SQL SUM, briefly cached.
	 */
	private function total_revenue(): float {
		$key   = 'wfcp_total_revenue';
		$total = get_transient( $key );

		if ( false === $total ) {
			$total = $this->sum_orders_total( 0, array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() ) );
			set_transient( $key, $total, 10 * MINUTE_IN_SECONDS );
		}

		return (float) $total;
	}

	private function customers_count(): int {
		$counts = count_users();
		return (int) ( $counts['avail_roles']['customer'] ?? 0 ) + (int) ( $counts['avail_roles']['subscriber'] ?? 0 );
	}

	private function stock_counts(): array {
		global $wpdb;

		$threshold = (int) wfcp()->settings->get( 'low_stock', 2 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// Out of stock: parents only — WooCommerce syncs a variable product's
		// stock status from its variations, so counting variations too would
		// double-count.
		$out_of_stock = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value = 'outofstock'"
		);

		// Low stock: variations included — stock is usually managed on the
		// variation, and the parent carries no _stock of its own.
		$low_stock = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock'
				 INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				 WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'
				 AND CAST(s.meta_value AS SIGNED) > 0 AND CAST(s.meta_value AS SIGNED) <= %d",
				$threshold
			)
		);
		// phpcs:enable

		return array(
			'low'  => $low_stock,
			'out'  => $out_of_stock,
		);
	}

	private function recent_orders(): array {
		$orders = wc_get_orders(
			array(
				'limit'   => 8,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);

		return array_map(
			static fn( WC_Order $order ) => array(
				'id'       => $order->get_id(),
				'number'   => $order->get_order_number(),
				'customer' => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'wfcp' ),
				'total'    => (float) $order->get_total(),
				'status'   => $order->get_status(),
				'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			),
			$orders
		);
	}
}
