<?php
/**
 * SMS gateway bridge.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends quick SMS messages to customers through whichever gateway plugin is
 * installed. Supported out of the box:
 *
 *  - The wfcp_send_sms filter (custom integrations; return true/false)
 *  - Persian WooCommerce SMS / پیامک ووکامرس فارسی (class PWSMS)
 *  - WP-SMS (wp_sms_send())
 *
 * All integration calls are guarded so a gateway API change can never fatal
 * the panel.
 */
class WFCP_SMS {

	/**
	 * Whether any SMS gateway is wired up.
	 */
	public static function is_available(): bool {
		return has_filter( 'wfcp_send_sms' )
			|| class_exists( 'PWSMS' )
			|| function_exists( 'wp_sms_send' );
	}

	/**
	 * Sends a message to a phone number.
	 *
	 * @param string $phone   Recipient phone number.
	 * @param string $message Message body.
	 *
	 * @return true|\WP_Error
	 */
	public static function send( string $phone, string $message ) {
		$phone   = preg_replace( '/[^0-9+]/', '', $phone );
		$message = trim( sanitize_textarea_field( $message ) );

		if ( '' === $phone ) {
			return new WP_Error( 'wfcp_sms_no_phone', __( 'Customer has no phone number.', 'wfcp' ), array( 'status' => 400 ) );
		}
		if ( '' === $message ) {
			return new WP_Error( 'wfcp_sms_empty', __( 'Message text is required.', 'wfcp' ), array( 'status' => 400 ) );
		}

		/**
		 * Allows custom SMS gateway integrations. Return true on success or
		 * false on failure; null falls through to the bundled integrations.
		 *
		 * @param bool|null $sent    Send result, null when unhandled.
		 * @param string    $phone   Recipient.
		 * @param string    $message Message body.
		 */
		$sent = apply_filters( 'wfcp_send_sms', null, $phone, $message );
		if ( null !== $sent ) {
			return $sent ? true : new WP_Error( 'wfcp_sms_failed', __( 'SMS could not be sent.', 'wfcp' ), array( 'status' => 500 ) );
		}

		// Persian WooCommerce SMS (پیامک ووکامرس فارسی).
		if ( class_exists( 'PWSMS' ) ) {
			try {
				$pwsms = is_callable( array( 'PWSMS', 'instance' ) ) ? PWSMS::instance() : new PWSMS();
				if ( is_callable( array( $pwsms, 'send_sms' ) ) ) {
					$result = $pwsms->send_sms(
						array(
							'mobile'  => $phone,
							'message' => $message,
						)
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return false === $result
						? new WP_Error( 'wfcp_sms_failed', __( 'SMS could not be sent.', 'wfcp' ), array( 'status' => 500 ) )
						: true;
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'wfcp_sms_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		}

		// WP-SMS plugin.
		if ( function_exists( 'wp_sms_send' ) ) {
			$result = wp_sms_send( array( $phone ), $message );
			return is_wp_error( $result ) ? $result : true;
		}

		return new WP_Error( 'wfcp_sms_unavailable', __( 'No SMS gateway plugin found.', 'wfcp' ), array( 'status' => 501 ) );
	}
}
