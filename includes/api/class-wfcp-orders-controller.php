<?php
/**
 * Orders REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order management (HPOS-compatible, WooCommerce CRUD only).
 */
class WFCP_Orders_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'orders';

	public function register_routes(): void {
		$this->route(
			'',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_orders' ),
					'permission_callback' => $this->permission( 'wfcp_orders_view' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_order' ),
					'permission_callback' => $this->permission( 'wfcp_orders_create' ),
				),
			)
		);

		$this->route(
			'/counts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'counts' ),
				'permission_callback' => $this->permission( 'wfcp_orders_view' ),
			)
		);

		$this->route(
			'/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $this->permission( 'wfcp_orders_status' ),
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
			'/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => $this->permission( 'wfcp_orders_view' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_order' ),
					'permission_callback' => $this->permission( 'wfcp_orders_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_order' ),
					'permission_callback' => $this->permission( 'wfcp_orders_delete' ),
				),
			)
		);

		$this->route(
			'/(?P<id>\d+)/status',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => $this->permission( 'wfcp_orders_status' ),
			)
		);

		$this->route(
			'/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_notes' ),
					'permission_callback' => $this->permission( 'wfcp_orders_view' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => $this->permission( 'wfcp_orders_edit' ),
				),
			)
		);

		$this->route(
			'/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_item' ),
				'permission_callback' => $this->permission( 'wfcp_orders_edit' ),
			)
		);

		$this->route(
			'/(?P<id>\d+)/items/(?P<item_id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $this->permission( 'wfcp_orders_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_item' ),
					'permission_callback' => $this->permission( 'wfcp_orders_edit' ),
				),
			)
		);
	}

	/**
	 * Live order list with smart search (order #, phone, email, name),
	 * status filters and date presets.
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		// Unprefixed status filter (e.g. ['processing', 'on-hold']) or null for "any".
		$statuses     = null;
		$status_param = (string) $request->get_param( 'status' );
		if ( $status_param && 'any' !== $status_param ) {
			$statuses = array_map( 'sanitize_key', explode( ',', $status_param ) );
		}

		$tz   = wp_timezone();
		$from = 0;
		$to   = 0;
		switch ( (string) $request->get_param( 'range' ) ) {
			case 'today':
				$from = ( new DateTimeImmutable( 'today', $tz ) )->getTimestamp();
				break;
			case 'yesterday':
				$from = ( new DateTimeImmutable( 'yesterday', $tz ) )->getTimestamp();
				$to   = ( new DateTimeImmutable( 'today', $tz ) )->getTimestamp() - 1;
				break;
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			return $this->search_results( $search, $statuses, $from, $to, $pagination );
		}

		$args = array(
			'limit'    => $pagination['per_page'],
			'page'     => $pagination['page'],
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
			// Internal checkout drafts are never shown in the panel.
			'status'   => $statuses
				? array_map( static fn( $s ) => "wc-{$s}", $statuses )
				: array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ),
		);

		if ( $from && $to ) {
			$args['date_created'] = $from . '...' . $to;
		} elseif ( $from ) {
			$args['date_created'] = '>=' . $from;
		}

		$results = wc_get_orders( $args );
		$items   = array_map( array( $this, 'format_order' ), $results->orders );

		return $this->list_response( $items, (int) $results->total, $pagination['page'], $pagination['per_page'] );
	}

	/**
	 * Search result list. IDs come from direct SQL (storage-aware); status and
	 * date filters are applied in PHP because neither legacy post__in nor HPOS
	 * id-array params behave consistently across order storages.
	 *
	 * @param string     $search     Search term.
	 * @param array|null $statuses   Unprefixed statuses or null for any.
	 * @param int        $from       Min created timestamp (0 = unbounded).
	 * @param int        $to         Max created timestamp (0 = unbounded).
	 * @param array      $pagination page / per_page.
	 */
	private function search_results( string $search, ?array $statuses, int $from, int $to, array $pagination ): WP_REST_Response {
		$matched = array();

		foreach ( array_slice( $this->search_order_ids( $search ), 0, 300 ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || 'checkout-draft' === $order->get_status() ) {
				continue;
			}
			if ( null !== $statuses && ! in_array( $order->get_status(), $statuses, true ) ) {
				continue;
			}
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			if ( ( $from && $created < $from ) || ( $to && $created > $to ) ) {
				continue;
			}
			$matched[] = $order;
		}

		usort(
			$matched,
			static fn( WC_Order $a, WC_Order $b ) =>
				( $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0 )
				<=> ( $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0 )
		);

		$total = count( $matched );
		$page  = array_slice( $matched, ( $pagination['page'] - 1 ) * $pagination['per_page'], $pagination['per_page'] );

		return $this->list_response( array_map( array( $this, 'format_order' ), $page ), $total, $pagination['page'], $pagination['per_page'] );
	}

	/**
	 * Resolves a free-text search to order IDs with partial (LIKE) matching on
	 * email, phone and billing name, supporting both HPOS and legacy storage.
	 */
	private function search_order_ids( string $term ): array {
		global $wpdb;

		$ids = array();

		// Pure number: the order ID itself is always a candidate.
		if ( ctype_digit( $term ) && wc_get_order( (int) $term ) ) {
			$ids[] = (int) $term;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$hpos = $this->hpos_enabled();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( str_contains( $term, '@' ) ) {
			$found = $hpos
				? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND billing_email LIKE %s ORDER BY id DESC LIMIT 100", $like ) )
				: $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_email' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 100", $like ) );
		} elseif ( preg_match( '/^[0-9+\-\s()]+$/', $term ) ) {
			$digits = '%' . $wpdb->esc_like( preg_replace( '/[\s\-()]/', '', $term ) ) . '%';
			$strip  = static fn( string $col ): string => "REPLACE(REPLACE(REPLACE(REPLACE({$col}, ' ', ''), '-', ''), '(', ''), ')', '')";
			$found  = $hpos
				? $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND {$strip('phone')} LIKE %s ORDER BY order_id DESC LIMIT 100", $digits ) )
				: $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_phone' AND {$strip('meta_value')} LIKE %s ORDER BY post_id DESC LIMIT 100", $digits ) );
		} else {
			$found = $hpos
				? $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND CONCAT_WS(' ', first_name, last_name) LIKE %s ORDER BY order_id DESC LIMIT 100", $like ) )
				: $wpdb->get_col(
					$wpdb->prepare(
						"SELECT fm.post_id FROM {$wpdb->postmeta} fm
						 INNER JOIN {$wpdb->postmeta} lm ON lm.post_id = fm.post_id AND lm.meta_key = '_billing_last_name'
						 WHERE fm.meta_key = '_billing_first_name' AND CONCAT_WS(' ', fm.meta_value, lm.meta_value) LIKE %s
						 ORDER BY fm.post_id DESC LIMIT 100",
						$like
					)
				);
		}
		// phpcs:enable

		return array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $found ) ) ) );
	}

	/**
	 * Status counts for the filter chips.
	 */
	public function counts(): WP_REST_Response {
		return rest_ensure_response( $this->order_status_counts() );
	}

	/**
	 * Whitelists and sanitises an address payload before it reaches
	 * WC_Order::set_address(); unknown keys are dropped defensively.
	 *
	 * @param array $address Raw address fields.
	 */
	private function sanitize_address( array $address ): array {
		$allowed = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		return array_map( 'sanitize_text_field', array_intersect_key( $address, array_flip( $allowed ) ) );
	}

	/**
	 * Creates a manual order.
	 */
	public function create_order( WP_REST_Request $request ) {
		$order = wc_create_order( array( 'created_via' => 'wfcp' ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = (array) $request->get_json_params();

		if ( ! empty( $params['customer_id'] ) ) {
			$order->set_customer_id( (int) $params['customer_id'] );
		}
		if ( ! empty( $params['billing'] ) && is_array( $params['billing'] ) ) {
			$order->set_address( $this->sanitize_address( $params['billing'] ), 'billing' );
		}
		foreach ( (array) ( $params['items'] ?? array() ) as $item ) {
			$product = wc_get_product( (int) ( $item['product_id'] ?? 0 ) );
			if ( $product ) {
				$order->add_product( $product, max( 1, (int) ( $item['quantity'] ?? 1 ) ) );
			}
		}

		$order->calculate_totals();
		$order->save();

		$this->audit( 'order.create', 'order', $order->get_id() );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Order detail.
	 */
	public function get_order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Edits billing/shipping/customer note.
	 */
	public function update_order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$params = (array) $request->get_json_params();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! empty( $params[ $type ] ) && is_array( $params[ $type ] ) ) {
				$order->set_address( $this->sanitize_address( $params[ $type ] ), $type );
			}
		}
		if ( isset( $params['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $params['customer_note'] ) );
		}

		$order->save();
		$this->audit( 'order.update', 'order', $order->get_id() );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Quick status change.
	 */
	public function update_status( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			return new WP_Error( 'wfcp_invalid', __( 'Invalid order status.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$old = $order->get_status();
		$order->update_status( $status, sprintf( '[123Admin] %s:', wp_get_current_user()->display_name ) );

		$this->audit( 'order.status', 'order', $order->get_id(), array( 'from' => $old, 'to' => $status ) );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Moves an order to trash.
	 */
	public function delete_order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$order->delete( (bool) $request->get_param( 'force' ) );
		$this->audit( 'order.delete', 'order', (int) $request['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Order notes (history + internal notes).
	 */
	public function list_notes( WP_REST_Request $request ) {
		$notes = wc_get_order_notes( array( 'order_id' => (int) $request['id'] ) );
		return rest_ensure_response(
			array(
				'items' => array_map(
					static fn( $note ) => array(
						'id'       => $note->id,
						'content'  => $note->content,
						'author'   => $note->added_by,
						'customer' => (bool) $note->customer_note,
						'date'     => $note->date_created ? $note->date_created->date_i18n( 'Y-m-d H:i' ) : '',
					),
					$notes
				),
			)
		);
	}

	/**
	 * Adds an order note.
	 */
	public function add_note( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$content = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $content ) {
			return new WP_Error( 'wfcp_invalid', __( 'Note cannot be empty.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$order->add_order_note( $content, (bool) $request->get_param( 'customer_note' ), true );
		$this->audit( 'order.note', 'order', $order->get_id() );

		return $this->list_notes( $request );
	}

	/**
	 * Adds a product line to an order.
	 */
	public function add_item( WP_REST_Request $request ) {
		$order   = wc_get_order( (int) $request['id'] );
		$product = wc_get_product( (int) $request->get_param( 'product_id' ) );

		if ( ! $order instanceof WC_Order || ! $product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order or product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$order->add_product( $product, max( 1, (int) $request->get_param( 'quantity' ) ) );
		$order->calculate_totals();
		$order->save();

		$this->audit( 'order.item_add', 'order', $order->get_id(), array( 'product' => $product->get_id() ) );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Changes the quantity of a line item.
	 */
	public function update_item( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$item = $order->get_item( (int) $request['item_id'] );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order item not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$qty = max( 1, (int) $request->get_param( 'quantity' ) );
		$unit = (float) $item->get_subtotal() / max( 1, $item->get_quantity() );
		$item->set_quantity( $qty );
		$item->set_subtotal( (string) ( $unit * $qty ) );
		$item->set_total( (string) ( $unit * $qty ) );
		$item->save();

		$order->calculate_totals();
		$order->save();

		$this->audit( 'order.item_qty', 'order', $order->get_id(), array( 'item' => (int) $request['item_id'], 'qty' => $qty ) );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Removes a line item.
	 */
	public function remove_item( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wfcp_not_found', __( 'Order not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$order->remove_item( (int) $request['item_id'] );
		$order->calculate_totals();
		$order->save();

		$this->audit( 'order.item_remove', 'order', $order->get_id(), array( 'item' => (int) $request['item_id'] ) );

		return rest_ensure_response( $this->format_order( $order, true ) );
	}

	/**
	 * Bulk status change or delete.
	 */
	public function bulk( WP_REST_Request $request ) {
		$ids    = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$action = (string) $request->get_param( 'action' );

		if ( ! $ids || count( $ids ) > 200 ) {
			return new WP_Error( 'wfcp_invalid', __( 'Select between 1 and 200 orders.', 'wfcp' ), array( 'status' => 400 ) );
		}
		if ( 'delete' === $action && ! current_user_can( 'wfcp_orders_delete' ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'You do not have permission to do this.', 'wfcp' ), array( 'status' => 403 ) );
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( 'delete' === $action ) {
				$order->delete( false );
			} elseif ( str_starts_with( $action, 'status:' ) ) {
				$status = sanitize_key( substr( $action, 7 ) );
				if ( array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
					$order->update_status( $status, '[123Admin bulk]' );
				}
			} else {
				return new WP_Error( 'wfcp_invalid', __( 'Unknown bulk action.', 'wfcp' ), array( 'status' => 400 ) );
			}
			++$updated;
		}

		$this->audit( 'order.bulk', 'order', 0, compact( 'action', 'ids' ) );

		return rest_ensure_response( array( 'updated' => $updated ) );
	}

	/**
	 * CSV export of the (filtered) order list.
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limited = wfcp()->security->rate_limit( 'export', 10, 60 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$request->set_param( 'per_page', 100 );
		$rows = array();
		$page = 1;
		do {
			$request->set_param( 'page', $page );
			$batch = $this->list_orders( $request )->get_data();
			foreach ( $batch['items'] as $o ) {
				$rows[] = array( $o['number'], $o['date'], $o['status'], $o['customer'], $o['email'], $o['phone'], $o['total'], $o['payment_method'] );
			}
			++$page;
		} while ( $page <= min( 20, (int) $batch['total_pages'] ) );

		$this->audit( 'order.export', 'order', 0, array( 'rows' => count( $rows ) ) );

		return rest_ensure_response(
			array(
				'filename'  => 'orders-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'       => $this->to_csv( array( 'Number', 'Date', 'Status', 'Customer', 'Email', 'Phone', 'Total', 'Payment' ), $rows ),
				'truncated' => count( $rows ) < (int) $batch['total'],
			)
		);
	}

	/**
	 * Serialises an order for the SPA.
	 */
	private function format_order( WC_Order $order, bool $full = false ): array {
		$data = array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'customer'       => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'wfcp' ),
			'customer_id'    => $order->get_customer_id(),
			'email'          => $order->get_billing_email(),
			'phone'          => $order->get_billing_phone(),
			'total'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'items_count'    => $order->get_item_count(),
			'payment_method' => $order->get_payment_method_title(),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
		);

		if ( $full ) {
			$items = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				/** @var WC_Order_Item_Product $item */
				$items[] = array(
					'id'         => $item_id,
					'name'       => $item->get_name(),
					'product_id' => $item->get_product_id(),
					'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
					'quantity'   => $item->get_quantity(),
					'total'      => (float) $item->get_total(),
				);
			}

			$data += array(
				'items'             => $items,
				'subtotal'          => (float) $order->get_subtotal(),
				'discount'          => (float) $order->get_discount_total(),
				'shipping_total'    => (float) $order->get_shipping_total(),
				'tax_total'         => (float) $order->get_total_tax(),
				'refunded'          => (float) $order->get_total_refunded(),
				'customer_note'     => $order->get_customer_note(),
				'billing'           => $order->get_address( 'billing' ),
				'shipping'          => $order->get_address( 'shipping' ),
				'shipping_method'   => $order->get_shipping_method(),
				'transaction_id'    => $order->get_transaction_id(),
				'date_paid'         => $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d H:i' ) : '',
			);
		}

		return $data;
	}
}
