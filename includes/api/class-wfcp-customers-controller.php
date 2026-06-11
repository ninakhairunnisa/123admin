<?php
/**
 * Customers REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer/user management: search, profile, edit, role change,
 * block/unblock, notes, export.
 */
class WFCP_Customers_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'customers';

	public function register_routes(): void {
		$this->route(
			'',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_customers' ),
				'permission_callback' => $this->permission( 'wfcp_users_view' ),
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
					'callback'            => array( $this, 'get_customer' ),
					'permission_callback' => $this->permission( 'wfcp_users_view' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_customer' ),
					'permission_callback' => $this->permission( 'wfcp_users_edit' ),
				),
			)
		);

		$this->route(
			'/(?P<id>\d+)/block',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_block' ),
				'permission_callback' => $this->permission( 'wfcp_users_block' ),
			)
		);

		$this->route(
			'/(?P<id>\d+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => $this->permission( 'wfcp_users_edit' ),
			)
		);
	}

	/**
	 * Records the user's last login time.
	 */
	public static function track_login( string $login, WP_User $user ): void {
		update_user_meta( $user->ID, 'wfcp_last_login', time() );
	}

	/**
	 * Prevents blocked users from logging in.
	 *
	 * @param null|\WP_User|\WP_Error $user Authentication result so far.
	 */
	public static function block_authentication( $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, 'wfcp_blocked', true ) ) {
			return new WP_Error( 'wfcp_blocked', __( 'Your account has been suspended. Please contact the store.', 'wfcp' ) );
		}
		return $user;
	}

	/**
	 * Customer list with quick search.
	 */
	public function list_customers( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		$args = array(
			'number'      => $pagination['per_page'],
			'paged'       => $pagination['page'],
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		);

		$role = (string) $request->get_param( 'role' );
		if ( $role && 'all' !== $role ) {
			$args['role'] = sanitize_key( $role );
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$query = new WP_User_Query( $args );
		$users = $query->get_results();
		$stats = $this->bulk_stats( wp_list_pluck( $users, 'ID' ) );
		$items = array_map(
			fn( WP_User $user ) => $this->format_customer( $user, false, $stats[ $user->ID ] ?? array( 'orders' => 0, 'spent' => 0.0 ) ),
			$users
		);

		return $this->list_response( $items, (int) $query->get_total(), $pagination['page'], $pagination['per_page'] );
	}

	/**
	 * Order count + lifetime spend for a page of users in one GROUP BY query
	 * (instead of two queries per row).
	 *
	 * @param int[] $user_ids User IDs.
	 *
	 * @return array<int, array{orders:int, spent:float}>
	 */
	private function bulk_stats( array $user_ids ): array {
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}

		global $wpdb;

		$paid    = array_map( static fn( $s ) => "wc-{$s}", wc_get_is_paid_statuses() );
		$ph      = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
		$ids_sql = implode( ',', $user_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->hpos_enabled()
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT customer_id AS uid, COUNT(*) AS orders, SUM(total_amount) AS spent
					 FROM {$wpdb->prefix}wc_orders
					 WHERE type = 'shop_order' AND customer_id IN ({$ids_sql}) AND status IN ({$ph})
					 GROUP BY customer_id",
					$paid
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CAST(cm.meta_value AS UNSIGNED) AS uid, COUNT(*) AS orders, SUM(tm.meta_value + 0) AS spent
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} cm ON cm.post_id = p.ID AND cm.meta_key = '_customer_user' AND cm.meta_value IN ({$ids_sql})
					 INNER JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_order_total'
					 WHERE p.post_type = 'shop_order' AND p.post_status IN ({$ph})
					 GROUP BY cm.meta_value",
					$paid
				),
				ARRAY_A
			);
		// phpcs:enable

		$stats = array();
		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row['uid'] ] = array(
				'orders' => (int) $row['orders'],
				'spent'  => (float) $row['spent'],
			);
		}

		return $stats;
	}

	/**
	 * Customer profile with order history and lifetime stats.
	 */
	public function get_customer( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'wfcp_not_found', __( 'User not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$data = $this->format_customer( $user, true );

		$orders         = wc_get_orders(
			array(
				'customer_id' => $user->ID,
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$data['orders'] = array_map(
			static fn( WC_Order $order ) => array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'status' => $order->get_status(),
				'total'  => (float) $order->get_total(),
				'date'   => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			),
			$orders
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Edits profile fields and role.
	 */
	public function update_customer( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'wfcp_not_found', __( 'User not found.', 'wfcp' ), array( 'status' => 404 ) );
		}
		if ( $denied = $this->deny_unmanageable( $user ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			return $denied;
		}
		if ( user_can( $user, 'administrator' ) && ! current_user_can( 'administrator' ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'Only administrators can edit administrator accounts.', 'wfcp' ), array( 'status' => 403 ) );
		}

		$params = (array) $request->get_json_params();
		$update = array( 'ID' => $user->ID );

		if ( isset( $params['first_name'] ) ) {
			$update['first_name'] = sanitize_text_field( $params['first_name'] );
		}
		if ( isset( $params['last_name'] ) ) {
			$update['last_name'] = sanitize_text_field( $params['last_name'] );
		}
		if ( isset( $params['email'] ) && is_email( $params['email'] ) ) {
			$update['user_email'] = sanitize_email( $params['email'] );
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $params['phone'] ) ) {
			update_user_meta( $user->ID, 'billing_phone', sanitize_text_field( $params['phone'] ) );
		}

		// Role changes are restricted: only admins may assign privileged roles.
		if ( ! empty( $params['role'] ) ) {
			$role = sanitize_key( $params['role'] );
			$privileged = array( 'administrator', 'editor', 'shop_manager' );
			if ( null === get_role( $role ) ) {
				return new WP_Error( 'wfcp_invalid', __( 'Unknown role.', 'wfcp' ), array( 'status' => 400 ) );
			}
			if ( in_array( $role, $privileged, true ) && ! current_user_can( 'promote_users' ) ) {
				return new WP_Error( 'wfcp_forbidden', __( 'You cannot assign this role.', 'wfcp' ), array( 'status' => 403 ) );
			}
			$user->set_role( $role );
		}

		$this->audit( 'user.update', 'user', $user->ID, array( 'fields' => array_keys( $params ) ) );

		return rest_ensure_response( $this->format_customer( get_userdata( $user->ID ), true ) );
	}

	/**
	 * Canonical WordPress per-user authorisation. This respects core role
	 * hierarchies and WooCommerce's policy that shop managers may only
	 * manage customer accounts (woocommerce_shop_manager_editable_roles),
	 * which panel capabilities alone must never bypass.
	 *
	 * @param WP_User $user Target account.
	 *
	 * @return \WP_Error|null Error when the current user may not manage it.
	 */
	private function deny_unmanageable( WP_User $user ): ?WP_Error {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'You are not allowed to manage this account.', 'wfcp' ), array( 'status' => 403 ) );
		}
		return null;
	}

	/**
	 * Blocks or unblocks an account.
	 */
	public function toggle_block( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'wfcp_not_found', __( 'User not found.', 'wfcp' ), array( 'status' => 404 ) );
		}
		if ( $denied = $this->deny_unmanageable( $user ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			return $denied;
		}
		if ( user_can( $user, 'administrator' ) || $user->ID === get_current_user_id() ) {
			return new WP_Error( 'wfcp_forbidden', __( 'This account cannot be blocked.', 'wfcp' ), array( 'status' => 403 ) );
		}

		$block = (bool) $request->get_param( 'blocked' );
		if ( $block ) {
			update_user_meta( $user->ID, 'wfcp_blocked', 1 );
			// Terminate all active sessions immediately.
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		} else {
			delete_user_meta( $user->ID, 'wfcp_blocked' );
		}

		$this->audit( $block ? 'user.block' : 'user.unblock', 'user', $user->ID );

		return rest_ensure_response( array( 'blocked' => $block ) );
	}

	/**
	 * Adds an internal note to a customer record.
	 */
	public function add_note( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'wfcp_not_found', __( 'User not found.', 'wfcp' ), array( 'status' => 404 ) );
		}
		if ( $denied = $this->deny_unmanageable( $user ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			return $denied;
		}

		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $note ) {
			return new WP_Error( 'wfcp_invalid', __( 'Note cannot be empty.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$notes   = (array) get_user_meta( $user->ID, 'wfcp_notes', true );
		$notes[] = array(
			'note'   => $note,
			'author' => wp_get_current_user()->display_name,
			'date'   => current_time( 'mysql' ),
		);
		update_user_meta( $user->ID, 'wfcp_notes', array_slice( $notes, -50 ) );

		$this->audit( 'user.note', 'user', $user->ID );

		return rest_ensure_response( array( 'notes' => array_values( array_filter( $notes ) ) ) );
	}

	/**
	 * CSV export of customers.
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
			$batch = $this->list_customers( $request )->get_data();
			foreach ( $batch['items'] as $c ) {
				$rows[] = array( $c['id'], $c['name'], $c['email'], $c['phone'], $c['role'], $c['orders_count'], $c['total_spent'], $c['registered'] );
			}
			++$page;
		} while ( $page <= min( 20, (int) $batch['total_pages'] ) );

		$this->audit( 'user.export', 'user', 0, array( 'rows' => count( $rows ) ) );

		return rest_ensure_response(
			array(
				'filename'  => 'customers-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'       => $this->to_csv( array( 'ID', 'Name', 'Email', 'Phone', 'Role', 'Orders', 'Total spent', 'Registered' ), $rows ),
				'truncated' => count( $rows ) < (int) $batch['total'],
			)
		);
	}

	/**
	 * Serialises a user for the SPA.
	 */
	private function format_customer( WP_User $user, bool $full = false, ?array $stats = null ): array {
		$last_login = (int) get_user_meta( $user->ID, 'wfcp_last_login', true );

		$data = array(
			'id'           => $user->ID,
			'name'         => $user->display_name,
			'email'        => $user->user_email,
			'phone'        => (string) get_user_meta( $user->ID, 'billing_phone', true ),
			'role'         => (string) ( $user->roles[0] ?? '' ),
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			'blocked'      => (bool) get_user_meta( $user->ID, 'wfcp_blocked', true ),
			'registered'   => mysql2date( 'Y-m-d', $user->user_registered ),
			'last_login'   => $last_login ? wp_date( 'Y-m-d H:i', $last_login ) : '',
			'orders_count' => null !== $stats ? $stats['orders'] : wc_get_customer_order_count( $user->ID ),
			'total_spent'  => null !== $stats ? $stats['spent'] : (float) wc_get_customer_total_spent( $user->ID ),
		);

		if ( $full ) {
			$customer = new WC_Customer( $user->ID );
			$data += array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'billing'    => $customer->get_billing(),
				'shipping'   => $customer->get_shipping(),
				'notes'      => array_values( array_filter( (array) get_user_meta( $user->ID, 'wfcp_notes', true ) ) ),
			);
		}

		return $data;
	}
}
