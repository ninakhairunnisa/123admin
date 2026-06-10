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
	 * Builds a CSV string from rows (with UTF-8 BOM so Excel renders Persian correctly).
	 *
	 * @param string[] $headers Column headers.
	 * @param array[]  $rows    Data rows.
	 */
	protected function to_csv( array $headers, array $rows ): string {
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $csv;
	}
}
