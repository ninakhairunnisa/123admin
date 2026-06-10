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

			$orders = wc_get_orders(
				array(
					'limit'        => -1,
					'status'       => array_merge( $paid_statuses, array( 'wc-pending', 'wc-on-hold' ) ),
					'date_created' => '>=' . $chart_start->getTimestamp(),
					'return'       => 'objects',
				)
			);

			$paid_keys = wc_get_is_paid_statuses();
			$series    = array();
			for ( $i = 0; $i < 30; $i++ ) {
				$key            = $chart_start->modify( "+{$i} days" )->format( 'Y-m-d' );
				$series[ $key ] = array( 'date' => $key, 'sales' => 0.0, 'orders' => 0 );
			}

			$sales_today  = 0.0;
			$sales_week   = 0.0;
			$sales_month  = 0.0;
			$orders_today = 0;

			foreach ( $orders as $order ) {
				$created = $order->get_date_created();
				if ( ! $created ) {
					continue;
				}
				$local = $created->date_i18n( 'Y-m-d' );
				$total = (float) $order->get_total();
				$paid  = in_array( $order->get_status(), $paid_keys, true );

				if ( isset( $series[ $local ] ) ) {
					++$series[ $local ]['orders'];
					if ( $paid ) {
						$series[ $local ]['sales'] += $total;
					}
				}
				if ( $local >= $today_start->format( 'Y-m-d' ) ) {
					++$orders_today;
					if ( $paid ) {
						$sales_today += $total;
					}
				}
				if ( $paid && $local >= $week_start->format( 'Y-m-d' ) ) {
					$sales_week += $total;
				}
				if ( $paid && $local >= $month_start->format( 'Y-m-d' ) ) {
					$sales_month += $total;
				}
			}

			$data = array(
				'sales'      => array(
					'today'         => $sales_today,
					'week'          => $sales_week,
					'month'         => $sales_month,
					'total_revenue' => $this->total_revenue(),
				),
				'orders'     => array(
					'today'      => $orders_today,
					'pending'    => $this->count_orders( array( 'wc-pending', 'wc-on-hold' ) ),
					'processing' => $this->count_orders( array( 'wc-processing' ) ),
					'completed'  => $this->count_orders( array( 'wc-completed' ) ),
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

	private function count_orders( array $statuses ): int {
		$result = wc_get_orders(
			array(
				'limit'    => 1,
				'status'   => $statuses,
				'paginate' => true,
				'return'   => 'ids',
			)
		);
		return (int) $result->total;
	}

	private function total_revenue(): float {
		$key   = 'wfcp_total_revenue';
		$total = get_transient( $key );
		if ( false === $total ) {
			$total  = 0.0;
			$orders = wc_get_orders(
				array(
					'limit'  => -1,
					'status' => array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() ),
					'return' => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				$total += (float) $order->get_total();
			}
			set_transient( $key, $total, 10 * MINUTE_IN_SECONDS );
		}
		return (float) $total;
	}

	private function customers_count(): int {
		$counts = count_users();
		return (int) ( $counts['avail_roles']['customer'] ?? 0 );
	}

	private function stock_counts(): array {
		global $wpdb;

		$threshold = (int) wfcp()->settings->get( 'low_stock', 2 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$out_of_stock = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value = 'outofstock'"
		);

		$low_stock = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock'
				 INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
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
