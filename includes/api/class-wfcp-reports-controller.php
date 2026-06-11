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
	 * Sales totals bucketed by day/week/month/year. Fully SQL-aggregated so
	 * even the yearly report never hydrates order objects.
	 */
	public function sales( WP_REST_Request $request ): WP_REST_Response {
		$period = (string) $request->get_param( 'period' );

		$config = array(
			'day'   => array( 'buckets' => 30, 'modifier' => 'days', 'format' => 'Y-m-d', 'sql' => '%Y-%m-%d' ),
			'week'  => array( 'buckets' => 12, 'modifier' => 'weeks', 'format' => 'o-\WW', 'sql' => '%x-W%v' ),
			'month' => array( 'buckets' => 12, 'modifier' => 'months', 'format' => 'Y-m', 'sql' => '%Y-%m' ),
			'year'  => array( 'buckets' => 5, 'modifier' => 'years', 'format' => 'Y', 'sql' => '%Y' ),
		)[ $period ] ?? array( 'buckets' => 30, 'modifier' => 'days', 'format' => 'Y-m-d', 'sql' => '%Y-%m-%d' );

		$cache_key = 'wfcp_report_sales_' . $period;
		$data      = get_transient( $cache_key );
		if ( is_array( $data ) ) {
			return rest_ensure_response( $data );
		}

		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-' . ( $config['buckets'] - 1 ) . ' ' . $config['modifier'] );
		$rows  = $this->sales_series( $config['sql'], $start->getTimestamp() );

		$series = array();
		for ( $i = 0; $i < $config['buckets']; $i++ ) {
			$key            = $start->modify( "+{$i} {$config['modifier']}" )->format( $config['format'] );
			$series[ $key ] = array(
				'label'  => $key,
				'gross'  => (float) ( $rows[ $key ]['gross'] ?? 0 ),
				'net'    => (float) ( $rows[ $key ]['net'] ?? 0 ),
				'orders' => (int) ( $rows[ $key ]['orders'] ?? 0 ),
				'items'  => (int) ( $rows[ $key ]['items'] ?? 0 ),
			);
		}

		$gross  = array_sum( array_column( $series, 'gross' ) );
		$orders = array_sum( array_column( $series, 'orders' ) );

		$data = array(
			'period'  => $period,
			'series'  => array_values( $series ),
			'summary' => array(
				'gross'     => $gross,
				'net'       => array_sum( array_column( $series, 'net' ) ),
				'orders'    => $orders,
				'items'     => array_sum( array_column( $series, 'items' ) ),
				'avg_order' => $orders ? $gross / $orders : 0,
			),
		);

		set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

		return rest_ensure_response( $data );
	}

	/**
	 * Gross/net/order/item totals per bucket via three GROUP BY queries
	 * (totals, item quantities, refunds) on the active order storage.
	 *
	 * Net = total − tax − shipping − refunds; refunds are bucketed at refund
	 * date. PHP bucket keys and MySQL DATE_FORMAT patterns are kept in sync
	 * (e.g. PHP "o-\WW" ⇔ MySQL "%x-W%v" for ISO weeks).
	 *
	 * @param string $sql_format MySQL DATE_FORMAT pattern.
	 * @param int    $from_ts    Site-local timestamp lower bound.
	 *
	 * @return array<string, array{gross: float, net: float, orders: int, items: int}>
	 */
	private function sales_series( string $sql_format, int $from_ts ): array {
		global $wpdb;

		$paid = array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() );
		$ph   = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
		$fmt  = str_replace( '%', '%%', $sql_format );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $this->hpos_enabled() ) {
			// HPOS stores UTC; bucket by site-local time via the current offset.
			$offset   = wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) );
			$from_gmt = gmdate( 'Y-m-d H:i:s', $from_ts );
			$bucket   = "DATE_FORMAT(DATE_ADD(o.date_created_gmt, INTERVAL %d SECOND), '{$fmt}')";

			$totals = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$bucket} AS bucket,
					        SUM(o.total_amount) AS gross,
					        SUM(o.total_amount - o.tax_amount - COALESCE(od.shipping_total_amount, 0)) AS net,
					        COUNT(*) AS orders
					 FROM {$wpdb->prefix}wc_orders o
					 LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
					 WHERE o.type = 'shop_order' AND o.status IN ({$ph}) AND o.date_created_gmt >= %s
					 GROUP BY bucket",
					array_merge( array( $offset ), $paid, array( $from_gmt ) )
				),
				ARRAY_A
			);

			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$bucket} AS bucket, SUM(qm.meta_value) AS items
					 FROM {$wpdb->prefix}woocommerce_order_items oi
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
					 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id AND o.type = 'shop_order' AND o.status IN ({$ph})
					 WHERE oi.order_item_type = 'line_item' AND o.date_created_gmt >= %s
					 GROUP BY bucket",
					array_merge( array( $offset ), $paid, array( $from_gmt ) )
				),
				ARRAY_A
			);

			// HPOS refund rows carry negative totals: add them to net as-is.
			$refunds = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE_FORMAT(DATE_ADD(date_created_gmt, INTERVAL %d SECOND), '{$fmt}') AS bucket, SUM(total_amount) AS refunded
					 FROM {$wpdb->prefix}wc_orders
					 WHERE type = 'shop_order_refund' AND date_created_gmt >= %s
					 GROUP BY bucket",
					array( $offset, $from_gmt )
				),
				ARRAY_A
			);
			$refund_sign = 1;
		} else {
			$from_local = wp_date( 'Y-m-d H:i:s', $from_ts );
			$bucket     = "DATE_FORMAT(p.post_date, '{$fmt}')";

			$totals = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$bucket} AS bucket,
					        SUM(tm.meta_value + 0) AS gross,
					        SUM(tm.meta_value + 0 - COALESCE(xm.meta_value + 0, 0) - COALESCE(sxm.meta_value + 0, 0) - COALESCE(sm.meta_value + 0, 0)) AS net,
					        COUNT(*) AS orders
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_order_total'
					 LEFT JOIN {$wpdb->postmeta} xm ON xm.post_id = p.ID AND xm.meta_key = '_order_tax'
					 LEFT JOIN {$wpdb->postmeta} sxm ON sxm.post_id = p.ID AND sxm.meta_key = '_order_shipping_tax'
					 LEFT JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = '_order_shipping'
					 WHERE p.post_type = 'shop_order' AND p.post_status IN ({$ph}) AND p.post_date >= %s
					 GROUP BY bucket",
					array_merge( $paid, array( $from_local ) )
				),
				ARRAY_A
			);

			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$bucket} AS bucket, SUM(qm.meta_value) AS items
					 FROM {$wpdb->prefix}woocommerce_order_items oi
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
					 INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order' AND p.post_status IN ({$ph})
					 WHERE oi.order_item_type = 'line_item' AND p.post_date >= %s
					 GROUP BY bucket",
					array_merge( $paid, array( $from_local ) )
				),
				ARRAY_A
			);

			// Legacy refunds store positive _refund_amount values: subtract.
			$refunds = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$bucket} AS bucket, SUM(rm.meta_value + 0) AS refunded
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} rm ON rm.post_id = p.ID AND rm.meta_key = '_refund_amount'
					 WHERE p.post_type = 'shop_order_refund' AND p.post_date >= %s
					 GROUP BY bucket",
					$from_local
				),
				ARRAY_A
			);
			$refund_sign = -1;
		}
		// phpcs:enable

		$series = array();
		foreach ( (array) $totals as $row ) {
			$series[ $row['bucket'] ] = array(
				'gross'  => (float) $row['gross'],
				'net'    => (float) $row['net'],
				'orders' => (int) $row['orders'],
				'items'  => 0,
			);
		}
		foreach ( (array) $items as $row ) {
			if ( isset( $series[ $row['bucket'] ] ) ) {
				$series[ $row['bucket'] ]['items'] = (int) $row['items'];
			}
		}
		foreach ( (array) $refunds as $row ) {
			if ( isset( $series[ $row['bucket'] ] ) ) {
				$series[ $row['bucket'] ]['net'] += $refund_sign * (float) $row['refunded'];
			}
		}

		return $series;
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
