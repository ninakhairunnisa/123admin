<?php
/**
 * Products REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product management built on the WooCommerce CRUD layer.
 */
class WFCP_Products_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'products';

	public function register_routes(): void {
		$this->route(
			'',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_products' ),
					'permission_callback' => $this->permission( 'wfcp_products_view' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product' ),
					'permission_callback' => $this->permission( 'wfcp_products_create' ),
				),
			)
		);

		$this->route(
			'/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $this->permission( 'wfcp_products_edit' ),
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
			'/taxonomies',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'taxonomies' ),
				'permission_callback' => $this->permission( 'wfcp_products_view' ),
			)
		);

		$this->route(
			'/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product' ),
					'permission_callback' => $this->permission( 'wfcp_products_view' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_product' ),
					'permission_callback' => $this->permission( 'wfcp_products_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_product' ),
					'permission_callback' => $this->permission( 'wfcp_products_delete' ),
				),
			)
		);

		$this->route(
			'/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_product' ),
				'permission_callback' => $this->permission( 'wfcp_products_create' ),
			)
		);

		$this->route(
			'/(?P<id>\d+)/variations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_variations' ),
				'permission_callback' => $this->permission( 'wfcp_products_view' ),
			)
		);

		$this->route(
			'/variations/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_variation' ),
				'permission_callback' => $this->permission( 'wfcp_products_edit' ),
			)
		);
	}

	/**
	 * Product list with instant search and advanced filters.
	 */
	public function list_products( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => $request->get_param( 'status' ) ?: array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $pagination['per_page'],
			'paged'          => $pagination['page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$sku_ids = $this->ids_by_sku( $search );
			if ( $sku_ids ) {
				$args['post__in'] = $sku_ids;
			} else {
				$args['s'] = $search;
			}
		}

		if ( $request->get_param( 'category' ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => (int) $request->get_param( 'category' ),
			);
		}

		switch ( (string) $request->get_param( 'view' ) ) {
			case 'low_stock':
				// Variations included: stock is usually managed per-variation.
				$args['post_type']    = array( 'product', 'product_variation' );
				$args['meta_query'][] = array( 'key' => '_manage_stock', 'value' => 'yes' );
				$args['meta_query'][] = array(
					'key'     => '_stock',
					'value'   => array( 1, (int) wfcp()->settings->get( 'low_stock', 2 ) ),
					'type'    => 'NUMERIC',
					'compare' => 'BETWEEN',
				);
				break;
			case 'out_of_stock':
				$args['meta_query'][] = array( 'key' => '_stock_status', 'value' => 'outofstock' );
				break;
			case 'no_image':
				$args['meta_query'][] = array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' );
				break;
			case 'on_sale':
				$on_sale = wc_get_product_ids_on_sale() ?: array( 0 );
				// A SKU search may already have constrained post__in; intersect
				// instead of overwriting so both filters apply.
				$args['post__in'] = isset( $args['post__in'] )
					? ( array_values( array_intersect( $args['post__in'], $on_sale ) ) ?: array( 0 ) )
					: $on_sale;
				break;
			case 'best_sellers':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				break;
			case 'worst_sellers':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				break;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );
			if ( $product ) {
				$items[] = $this->format_product( $product );
			}
		}

		return $this->list_response( $items, (int) $query->found_posts, $pagination['page'], $pagination['per_page'] );
	}

	/**
	 * Quick product create.
	 */
	public function create_product( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'wfcp_invalid', __( 'Product name is required.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' === $request->get_param( 'status' ) ? 'publish' : 'draft' );

		$result = $this->apply_fields( $product, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$product->save();
		$this->audit( 'product.create', 'product', $product->get_id(), array( 'name' => $name ) );

		return rest_ensure_response( $this->format_product( $product, true ) );
	}

	/**
	 * Single product detail.
	 */
	public function get_product( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_product( $product, true ) );
	}

	/**
	 * Updates a product. Price and stock changes additionally require the
	 * dedicated wfcp_products_price / wfcp_products_stock capabilities.
	 */
	public function update_product( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$result = $this->apply_fields( $product, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$product->save();
		$this->audit( 'product.update', 'product', $product->get_id(), array( 'fields' => array_keys( (array) $request->get_json_params() ) ) );

		return rest_ensure_response( $this->format_product( $product, true ) );
	}

	/**
	 * Moves a product to trash (or force-deletes when force=1).
	 */
	public function delete_product( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$product->delete( (bool) $request->get_param( 'force' ) );
		$this->audit( 'product.delete', 'product', (int) $request['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Duplicates a product as a draft copy.
	 */
	public function duplicate_product( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'wfcp_not_found', __( 'Product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			include_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		}
		$duplicator = new WC_Admin_Duplicate_Product();
		$copy       = $duplicator->product_duplicate( $product );

		$this->audit( 'product.duplicate', 'product', $copy->get_id(), array( 'source' => $product->get_id() ) );

		return rest_ensure_response( $this->format_product( $copy, true ) );
	}

	/**
	 * Bulk operations: delete, publish/draft, price by percent, stock set/adjust.
	 */
	public function bulk( WP_REST_Request $request ) {
		$ids    = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$action = (string) $request->get_param( 'action' );
		$value  = $request->get_param( 'value' );

		if ( ! $ids || count( $ids ) > 200 ) {
			return new WP_Error( 'wfcp_invalid', __( 'Select between 1 and 200 products.', 'wfcp' ), array( 'status' => 400 ) );
		}

		$cap_map = array(
			'delete'       => 'wfcp_products_delete',
			'price_pct'    => 'wfcp_products_price',
			'stock_set'    => 'wfcp_products_stock',
			'stock_adjust' => 'wfcp_products_stock',
		);
		if ( isset( $cap_map[ $action ] ) && ! current_user_can( $cap_map[ $action ] ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'You do not have permission to do this.', 'wfcp' ), array( 'status' => 403 ) );
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			switch ( $action ) {
				case 'delete':
					$product->delete( false );
					break;
				case 'publish':
				case 'draft':
					$product->set_status( $action );
					$product->save();
					break;
				case 'price_pct':
					$pct = (float) $value;
					foreach ( array( 'regular', 'sale' ) as $type ) {
						$getter = "get_{$type}_price";
						$setter = "set_{$type}_price";
						$price  = (float) $product->{$getter}( 'edit' );
						if ( $price > 0 ) {
							$product->{$setter}( (string) round( $price * ( 1 + $pct / 100 ), wc_get_price_decimals() ) );
						}
					}
					$product->save();
					break;
				case 'stock_set':
					$product->set_manage_stock( true );
					$product->set_stock_quantity( max( 0, (int) $value ) );
					$product->save();
					break;
				case 'stock_adjust':
					if ( $product->managing_stock() ) {
						wc_update_product_stock( $product, abs( (int) $value ), (int) $value >= 0 ? 'increase' : 'decrease' );
					}
					break;
				default:
					return new WP_Error( 'wfcp_invalid', __( 'Unknown bulk action.', 'wfcp' ), array( 'status' => 400 ) );
			}
			++$updated;
		}

		$this->audit( 'product.bulk', 'product', 0, compact( 'action', 'ids' ) );

		return rest_ensure_response( array( 'updated' => $updated ) );
	}

	/**
	 * Lists variations of a variable product.
	 */
	public function list_variations( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'wfcp_not_found', __( 'Variable product not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$items = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$items[] = array(
					'id'            => $variation->get_id(),
					'attributes'    => wc_get_formatted_variation( $variation, true, false ),
					'sku'           => $variation->get_sku(),
					'regular_price' => $variation->get_regular_price(),
					'sale_price'    => $variation->get_sale_price(),
					'stock'         => $variation->get_stock_quantity(),
					'manage_stock'  => $variation->managing_stock(),
					'stock_status'  => $variation->get_stock_status(),
				);
			}
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Updates price/stock/SKU of a single variation.
	 */
	public function update_variation( WP_REST_Request $request ) {
		$variation = wc_get_product( (int) $request['id'] );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return new WP_Error( 'wfcp_not_found', __( 'Variation not found.', 'wfcp' ), array( 'status' => 404 ) );
		}

		$result = $this->apply_fields( $variation, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$variation->save();

		$this->audit( 'variation.update', 'product', $variation->get_id() );

		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * Product categories and tags for filter dropdowns.
	 */
	public function taxonomies(): WP_REST_Response {
		$format = static fn( $terms ) => array_map(
			static fn( WP_Term $t ) => array(
				'id'    => $t->term_id,
				'name'  => $t->name,
				'count' => $t->count,
			),
			is_array( $terms ) ? $terms : array()
		);

		return rest_ensure_response(
			array(
				'categories' => $format( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) ),
				'tags'       => $format( get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) ) ),
			)
		);
	}

	/**
	 * CSV export of the (filtered) product list.
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
			$batch = $this->list_products( $request )->get_data();
			foreach ( $batch['items'] as $item ) {
				$rows[] = array( $item['id'], $item['name'], $item['sku'], $item['regular_price'], $item['sale_price'], $item['stock'] ?? '', $item['stock_status'], $item['status'], $item['total_sales'] );
			}
			++$page;
		} while ( $page <= min( 20, (int) $batch['total_pages'] ) );

		$this->audit( 'product.export', 'product', 0, array( 'rows' => count( $rows ) ) );

		return rest_ensure_response(
			array(
				'filename'  => 'products-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'       => $this->to_csv( array( 'ID', 'Name', 'SKU', 'Regular price', 'Sale price', 'Stock', 'Stock status', 'Status', 'Total sales' ), $rows ),
				'truncated' => count( $rows ) < (int) $batch['total'],
			)
		);
	}

	/**
	 * Applies request fields to a product with per-field capability checks.
	 *
	 * @param WC_Product      $product Product (or variation).
	 * @param WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_fields( WC_Product $product, WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();

		$price_fields = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' );
		$stock_fields = array( 'stock_quantity', 'manage_stock', 'stock_status' );

		if ( array_intersect( $price_fields, array_keys( $params ) ) && ! current_user_can( 'wfcp_products_price' ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'You do not have permission to change prices.', 'wfcp' ), array( 'status' => 403 ) );
		}
		if ( array_intersect( $stock_fields, array_keys( $params ) ) && ! current_user_can( 'wfcp_products_stock' ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'You do not have permission to change stock.', 'wfcp' ), array( 'status' => 403 ) );
		}

		try {
			if ( isset( $params['name'] ) ) {
				$product->set_name( sanitize_text_field( $params['name'] ) );
			}
			if ( isset( $params['status'] ) && in_array( $params['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				$product->set_status( $params['status'] );
			}
			if ( isset( $params['short_description'] ) ) {
				$product->set_short_description( wp_kses_post( $params['short_description'] ) );
			}
			if ( isset( $params['description'] ) ) {
				$product->set_description( wp_kses_post( $params['description'] ) );
			}
			if ( isset( $params['regular_price'] ) ) {
				$product->set_regular_price( wc_format_decimal( $params['regular_price'] ) );
			}
			if ( isset( $params['sale_price'] ) ) {
				$product->set_sale_price( '' === $params['sale_price'] ? '' : wc_format_decimal( $params['sale_price'] ) );
			}
			if ( array_key_exists( 'date_on_sale_from', $params ) ) {
				$product->set_date_on_sale_from( $params['date_on_sale_from'] ?: null );
			}
			if ( array_key_exists( 'date_on_sale_to', $params ) ) {
				$product->set_date_on_sale_to( $params['date_on_sale_to'] ?: null );
			}
			if ( isset( $params['sku'] ) ) {
				$product->set_sku( wc_clean( $params['sku'] ) );
			}
			if ( isset( $params['manage_stock'] ) ) {
				$product->set_manage_stock( (bool) $params['manage_stock'] );
			}
			if ( isset( $params['stock_quantity'] ) && '' !== $params['stock_quantity'] ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( (int) $params['stock_quantity'] );
			}
			if ( isset( $params['stock_status'] ) && in_array( $params['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$product->set_stock_status( $params['stock_status'] );
			}
			if ( isset( $params['weight'] ) ) {
				$product->set_weight( wc_format_decimal( $params['weight'] ) );
			}
			if ( isset( $params['length'] ) ) {
				$product->set_length( wc_format_decimal( $params['length'] ) );
			}
			if ( isset( $params['width'] ) ) {
				$product->set_width( wc_format_decimal( $params['width'] ) );
			}
			if ( isset( $params['height'] ) ) {
				$product->set_height( wc_format_decimal( $params['height'] ) );
			}
			if ( isset( $params['categories'] ) && ! $product->is_type( 'variation' ) ) {
				$product->set_category_ids( array_map( 'intval', (array) $params['categories'] ) );
			}
			if ( isset( $params['tags'] ) && ! $product->is_type( 'variation' ) ) {
				$product->set_tag_ids( array_map( 'intval', (array) $params['tags'] ) );
			}
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( 'wfcp_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		/**
		 * Fires after panel fields have been applied to a product, before save.
		 *
		 * @param WC_Product $product Product being edited.
		 * @param array      $params  Raw request params.
		 */
		do_action( 'wfcp_product_apply_fields', $product, $params );

		return true;
	}

	/**
	 * Serialises a product for the SPA.
	 */
	private function format_product( WC_Product $product, bool $full = false ): array {
		$image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';

		$data = array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'type'          => $product->get_type(),
			'status'        => $product->get_status(),
			'sku'           => $product->get_sku(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'price'         => $product->get_price(),
			'on_sale'       => $product->is_on_sale(),
			'manage_stock'  => $product->managing_stock(),
			'stock'         => $product->get_stock_quantity(),
			'stock_status'  => $product->get_stock_status(),
			'image'         => $image,
			'total_sales'   => (int) $product->get_total_sales(),
			'permalink'     => $product->get_permalink(),
		);

		if ( $full ) {
			$data += array(
				'short_description' => $product->get_short_description(),
				'description'       => $product->get_description(),
				'date_on_sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'Y-m-d' ) : '',
				'date_on_sale_to'   => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'Y-m-d' ) : '',
				'weight'            => $product->get_weight(),
				'length'            => $product->get_length(),
				'width'             => $product->get_width(),
				'height'            => $product->get_height(),
				'categories'        => array_map( 'intval', $product->get_category_ids() ),
				'tags'              => array_map( 'intval', $product->get_tag_ids() ),
				'attributes'        => array_map(
					static fn( $attr ) => is_object( $attr ) ? array(
						'name'    => wc_attribute_label( $attr->get_name() ),
						'options' => $attr->get_options(),
					) : array(),
					$product->get_attributes()
				),
			);
		}

		return $data;
	}

	/**
	 * Finds product IDs matching a SKU (exact or prefix).
	 */
	private function ids_by_sku( string $sku ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 50",
				$sku
			)
		);

		return array_map( 'intval', $ids );
	}
}
