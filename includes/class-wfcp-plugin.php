<?php
/**
 * Main plugin bootstrap.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin singleton. Wires together all components.
 */
final class WFCP_Plugin {

	private static ?WFCP_Plugin $instance = null;

	public WFCP_Settings $settings;
	public WFCP_Capabilities $capabilities;
	public WFCP_Router $router;
	public WFCP_Security $security;
	public WFCP_Audit_Log $audit_log;

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): WFCP_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		$this->settings     = new WFCP_Settings();
		$this->audit_log    = new WFCP_Audit_Log();
		$this->security     = new WFCP_Security( $this->settings, $this->audit_log );
		$this->capabilities = new WFCP_Capabilities( $this->settings );
		$this->router       = new WFCP_Router( $this->settings, $this->capabilities );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Last-login tracking and account blocking must run on every request.
		add_action( 'wp_login', array( 'WFCP_Customers_Controller', 'track_login' ), 10, 2 );
		add_filter( 'authenticate', array( 'WFCP_Customers_Controller', 'block_authentication' ), 99 );

		add_filter( 'plugin_action_links_' . plugin_basename( WFCP_FILE ), array( $this, 'plugin_action_links' ) );

		/**
		 * Fires after 123Admin has been fully loaded.
		 *
		 * @param WFCP_Plugin $plugin Plugin instance.
		 */
		do_action( 'wfcp_loaded', $this );
	}

	/**
	 * Loads the plugin text domain (POT/PO/MO under /languages).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wfcp', false, dirname( plugin_basename( WFCP_FILE ) ) . '/languages' );
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Registers all REST API controllers under the wfcp/v1 namespace.
	 */
	public function register_rest_routes(): void {
		if ( ! $this->has_woocommerce() ) {
			return;
		}

		$controllers = array(
			new WFCP_Dashboard_Controller(),
			new WFCP_Products_Controller(),
			new WFCP_Orders_Controller(),
			new WFCP_Customers_Controller(),
			new WFCP_Reports_Controller(),
			new WFCP_Settings_Controller(),
		);

		/**
		 * Filters the REST controllers registered by 123Admin.
		 * Allows extensions to add their own WFCP_REST_Controller instances.
		 *
		 * @param WFCP_REST_Controller[] $controllers Controllers.
		 */
		$controllers = apply_filters( 'wfcp_rest_controllers', $controllers );

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Adds an "Open panel" shortcut on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 */
	public function plugin_action_links( array $links ): array {
		$panel = sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( $this->settings->panel_url() ),
			esc_html__( 'Open Panel', 'wfcp' )
		);
		array_unshift( $links, $panel );
		return $links;
	}
}
