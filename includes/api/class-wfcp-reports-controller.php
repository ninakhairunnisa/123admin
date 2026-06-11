<?php
/**
 * Reports REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sales, product, customer, category and stock reports with CSV export.
 */
class WFCP_Reports_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'reports';

	public function register_routes(): void {
		$this->route(
			'/sales',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sales' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( 'day', 'week', 'month', 'year' ),
						'default' => 'day',
					),
				),
			)
		);

		$this->route(
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'products' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
			)
		);

		$this->route(
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'customers' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
			)
		);

		$this->route(
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'categories' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
			)
		);

		$this->route(
			'/stock',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stock' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
			)
		);

		$this->route(
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $this->permission( 'wfcp_reports_export' ),
			)
		);

		$this->route(
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit_entries' ),
				'permission_callback' => $this->permission( 'wfcp_reports_view' ),
			)
		);
	}

	/**
	 * Sales totals bucketed by day/week/month/year.
	 */
	public function sales( WP_REST_Request $request ): WP_REST_Response {
		$period = (string) $request->get_param( 'period' );

		$config = array(
			'day'   => array( 'buckets' => 30, 'modifier' => 'days', 'format' => 'Y-m-d' ),
			'week'  => array( 'buckets' => 12, 'modifier' => 'weeks', 'format' => 'o-\WW' ),
			'month' => array( 'buckets' => 12, 'modifier' => 'months', 'format' => 'Y-m' ),
			'year'  => array( 'buckets' => 5, 'modifier' => 'years', 'format' => 'Y' ),
		)[ $period ] ?? array( 'buckets' => 30, 'modifier' => 'days', 'format' => 'Y-m-d' );

		$cache_key = 'wfcp_report_sales_' . $period;
		$data      = get_transient( $cache_key );
		if ( is_array( $data ) ) {
			return rest_ensure_response( $data );
		}

		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-' . ( $config['buckets'] - 1 ) . ' ' . $config['modifier'] );

		$buckets = array();
		for ( $i = 0; $i < $config['buckets']; $i++ ) {
			$key             = $start->modify( "+{$i} {$config['modifier']}" )->format( $config['format'] );
			$buckets[ $key ] = array( 'label' => $key, 'gross' => 0.0, 'net' => 0.0, 'orders' => 0, 'items' => 0 );
		}

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() ),
				'date_created' => '>=' . $start->getTimestamp(),
				'return'       => 'objects',
				'type'         => 'shop_order',
			)
		);

		$gross = 0.0;
		$items = 0;
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			if ( ! $created ) {
				continue;
			}
			$key = wp_date( $config['format'], $created->getTimestamp(), $tz );
			if ( ! isset( $buckets[ $key ] ) ) {
				continue;
			}
			$total = (float) $order->get_total();
			$net   = $total - (float) $order->get_total_tax() - (float) $order->get_shipping_total() - (float) $order->get_total_refunded();

			$buckets[ $key ]['gross'] += $total;
			$buckets[ $key ]['net']   += $net;
			$buckets[ $key ]['items'] += $order->get_item_count();
			++$buckets[ $key ]['orders'];

			$gross += $total;
			$items += $order->get_item_count();
		}

		$orders_count = count( $orders );
		$data = array(
			'period'  => $period,
			'series'  => array_values( $buckets ),
			'summary' => array(
				'gross'     => $gross,
				'net'       => array_sum( array_column( $buckets, 'net' ) ),
				'orders'    => $orders_count,
				'items'     => $items,
				'avg_order' => $orders_count ? $gross / $orders_count : 0,
			),
		);

		set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

		return rest_ensure_response( $data );
	}

	/**
	 * SQL JOIN clause restricting order-item aggregates to paid orders,
	 * for the active order storage (HPOS or legacy posts).
	 *
	 * @return array{0:string, 1:string[]} JOIN SQL with %s placeholders + values.
	 */
	private function paid_orders_join(): array {
		global $wpdb;

		$paid         = array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() );
		$placeholders = implode( ',', array_fill( 0, count( $paid ), '%s' ) );

		$join = $this->hpos_enabled()
			? "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id AND o.type = 'shop_order' AND o.status IN ({$placeholders})"
			: "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id AND o.post_type = 'shop_order' AND o.post_status IN ({$placeholders})";

		return array( $join, $paid );
	}

	/**
	 * Top products by units sold across paid orders, via order item meta.
	 */
	public function products(): WP_REST_Response {
		global $wpdb;

		$cache_key = 'wfcp_report_products';
		$data      = get_transient( $cache_key );

		if ( ! is_array( $data ) ) {
			list( $orders_join, $paid ) = $this->paid_orders_join();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS product_id,
					        SUM(qm.meta_value) AS qty,
					        SUM(tm.meta_value) AS revenue
					 FROM {$wpdb->prefix}woocommerce_order_items oi
					 {$orders_join}
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta tm ON tm.order_item_id = oi.order_item_id AND tm.meta_key = '_line_total'
					 WHERE oi.order_item_type = 'line_item'
					 GROUP BY pm.meta_value
					 ORDER BY qty DESC
					 LIMIT %d",
					array_merge( $paid, array( 50 ) )
				),
				ARRAY_A
			);
			// phpcs:enable

			$data = array( 'items' => array() );
			foreach ( $rows as $row ) {
				$product = wc_get_product( (int) $row['product_id'] );
				if ( $product ) {
					$data['items'][] = array(
						'id'      => $product->get_id(),
						'name'    => $product->get_name(),
						'sku'     => $product->get_sku(),
						'qty'     => (int) $row['qty'],
						'revenue' => (float) $row['revenue'],
						'stock'   => $product->get_stock_quantity(),
					);
				}
			}
			set_transient( $cache_key, $data, 10 * MINUTE_IN_SECONDS );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Top customers by lifetime value, aggregated in SQL over all paid orders
	 * (not just the most recently registered users).
	 */
	public function customers(): WP_REST_Response {
		global $wpdb;

		$cache_key = 'wfcp_report_customers';
		$items     = get_transient( $cache_key );

		if ( ! is_array( $items ) ) {
			$paid         = array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() );
			$placeholders = implode( ',', array_fill( 0, count( $paid ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $this->hpos_enabled() ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT customer_id, COUNT(*) AS orders, SUM(total_amount) AS spent
						 FROM {$wpdb->prefix}wc_orders
						 WHERE type = 'shop_order' AND customer_id > 0 AND status IN ({$placeholders})
						 GROUP BY customer_id ORDER BY spent DESC LIMIT 50",
						$paid
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT CAST(cm.meta_value AS UNSIGNED) AS customer_id, COUNT(*) AS orders, SUM(tm.meta_value + 0) AS spent
						 FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} cm ON cm.post_id = p.ID AND cm.meta_key = '_customer_user' AND cm.meta_value > 0
						 INNER JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_order_total'
						 WHERE p.post_type = 'shop_order' AND p.post_status IN ({$placeholders})
						 GROUP BY cm.meta_value ORDER BY spent DESC LIMIT 50",
						$paid
					),
					ARRAY_A
				);
			}
			// phpcs:enable

			$items = array();
			foreach ( (array) $rows as $row ) {
				$user = get_userdata( (int) $row['customer_id'] );
				if ( ! $user ) {
					continue;
				}
				$items[] = array(
					'id'     => (int) $row['customer_id'],
					'name'   => $user->display_name,
					'email'  => $user->user_email,
					'orders' => (int) $row['orders'],
					'spent'  => (float) $row['spent'],
				);
			}

			set_transient( $cache_key, $items, 10 * MINUTE_IN_SECONDS );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Revenue by product category.
	 */
	public function categories(): WP_REST_Response {
		$top   = $this->products()->get_data();
		$terms = array();

		foreach ( $top['items'] as $item ) {
			foreach ( wp_get_post_terms( $item['id'], 'product_cat' ) as $term ) {
				if ( ! isset( $terms[ $term->term_id ] ) ) {
					$terms[ $term->term_id ] = array( 'name' => $term->name, 'qty' => 0, 'revenue' => 0.0 );
				}
				$terms[ $term->term_id ]['qty']     += $item['qty'];
				$terms[ $term->term_id ]['revenue'] += $item['revenue'];
			}
		}

		usort( $terms, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

		return rest_ensure_response( array( 'items' => array_values( $terms ) ) );
	}

	/**
	 * Stock valuation report.
	 */
	public function stock(): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_manage_stock', 'value' => 'yes' ),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );
			if ( $product ) {
				$stock   = (int) $product->get_stock_quantity();
				$items[] = array(
					'id'    => $product->get_id(),
					'name'  => $product->get_name(),
					'sku'   => $product->get_sku(),
					'stock' => $stock,
					'value' => $stock * (float) $product->get_price(),
				);
			}
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Audit log entries (panel activity).
	 */
	public function audit_entries( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		return rest_ensure_response(
			array(
				'items' => wfcp()->audit_log->recent( $pagination['per_page'], ( $pagination['page'] - 1 ) * $pagination['per_page'] ),
			)
		);
	}

	/**
	 * CSV export for any report type.
	 */
	public function export( WP_REST_Request $request ) {
		$type    = (string) $request->get_param( 'type' );
		$limited = wfcp()->security->rate_limit( 'export', 10, 60 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		switch ( $type ) {
			case 'sales':
				$data    = $this->sales( $request )->get_data();
				$headers = array( 'Period', 'Gross', 'Net', 'Orders', 'Items' );
				$rows    = array_map( static fn( $r ) => array( $r['label'], $r['gross'], $r['net'], $r['orders'], $r['items'] ), $data['series'] );
				break;
			case 'products':
				$data    = $this->products()->get_data();
				$headers = array( 'ID', 'Name', 'SKU', 'Qty sold', 'Revenue', 'Stock' );
				$rows    = array_map( static fn( $r ) => array( $r['id'], $r['name'], $r['sku'], $r['qty'], $r['revenue'], $r['stock'] ), $data['items'] );
				break;
			case 'customers':
				$data    = $this->customers()->get_data();
				$headers = array( 'ID', 'Name', 'Email', 'Orders', 'Total spent' );
				$rows    = array_map( static fn( $r ) => array( $r['id'], $r['name'], $r['email'], $r['orders'], $r['spent'] ), $data['items'] );
				break;
			case 'stock':
				$data    = $this->stock()->get_data();
				$headers = array( 'ID', 'Name', 'SKU', 'Stock', 'Stock value' );
				$rows    = array_map( static fn( $r ) => array( $r['id'], $r['name'], $r['sku'], $r['stock'], $r['value'] ), $data['items'] );
				break;
			default:
				return new WP_Error( 'wfcp_invalid', __( 'Unknown report type.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$this->audit( 'report.export', 'report', 0, array( 'type' => $type ) );

		return rest_ensure_response(
			array(
				'filename' => "report-{$type}-" . gmdate( 'Ymd-His' ) . '.csv',
				'csv'      => $this->to_csv( $headers, $rows ),
			)
		);
	}
}
