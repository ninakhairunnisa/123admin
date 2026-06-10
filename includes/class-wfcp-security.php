<?php
/**
 * Security helpers: rate limiting, request hardening.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centralised security services used by the router and REST layer.
 *
 * - REST authentication relies on WordPress cookies + the standard
 *   wp_rest nonce (CSRF protection).
 * - Every route additionally performs a capability check.
 * - Write operations are rate limited per user.
 */
class WFCP_Security {

	private WFCP_Settings $settings;
	private WFCP_Audit_Log $audit_log;

	public function __construct( WFCP_Settings $settings, WFCP_Audit_Log $audit_log ) {
		$this->settings  = $settings;
		$this->audit_log = $audit_log;
	}

	/**
	 * Sliding-window rate limiter backed by transients.
	 *
	 * @param string $bucket Logical bucket, e.g. "write" or "export".
	 * @param int    $limit  Max requests per window.
	 * @param int    $window Window length in seconds.
	 *
	 * @return true|\WP_Error
	 */
	public function rate_limit( string $bucket, int $limit = 60, int $window = 60 ) {
		/**
		 * Filters rate-limit thresholds.
		 *
		 * @param array  $config { limit, window }.
		 * @param string $bucket Bucket name.
		 */
		$config = apply_filters( 'wfcp_rate_limit', compact( 'limit', 'window' ), $bucket );

		$key   = 'wfcp_rl_' . md5( $bucket . '|' . get_current_user_id() . '|' . WFCP_Audit_Log::client_ip() );
		$state = get_transient( $key );

		if ( ! is_array( $state ) || $state['reset'] < time() ) {
			$state = array(
				'count' => 0,
				'reset' => time() + (int) $config['window'],
			);
		}

		++$state['count'];
		set_transient( $key, $state, max( 1, $state['reset'] - time() ) );

		if ( $state['count'] > (int) $config['limit'] ) {
			$this->audit_log->record( 'security.rate_limited', 'security', 0, array( 'bucket' => $bucket ) );
			return new WP_Error(
				'wfcp_rate_limited',
				__( 'Too many requests. Please slow down and try again shortly.', 'wfcp' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Sends hardening headers for the standalone panel page.
	 */
	public function send_panel_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Cache-Control: no-store, max-age=0' );
	}

	/**
	 * Exposes the audit log writer.
	 */
	public function audit(): WFCP_Audit_Log {
		return $this->audit_log;
	}
}
