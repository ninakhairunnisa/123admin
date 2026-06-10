<?php
/**
 * Plugin Name:       123Admin – Fast Control Panel for WooCommerce
 * Plugin URI:        https://github.com/ninakhairunnisa/123admin
 * Description:       A blazing-fast, standalone, mobile-first control panel for WooCommerce stores. Manage products, orders, customers and reports without loading wp-admin.
 * Version:           1.0.0
 * Author:            123Admin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wfcp
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

define( 'WFCP_VERSION', '1.0.0' );
define( 'WFCP_FILE', __FILE__ );
define( 'WFCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WFCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WFCP_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, WFCP_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: required PHP version */
						__( '123Admin requires PHP %s or newer. The plugin is inactive.', 'wfcp' ),
						WFCP_MIN_PHP
					)
				)
			);
		}
	);
	return;
}

/**
 * Lightweight class autoloader for the WFCP_ prefix.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WFCP_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', $class ) );
		$file = 'class-' . $slug . '.php';
		foreach ( array( 'includes/', 'includes/api/' ) as $dir ) {
			$path = WFCP_DIR . $dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'WFCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WFCP_Activator', 'deactivate' ) );

// Declare WooCommerce HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Returns the main plugin instance.
 */
function wfcp(): WFCP_Plugin {
	return WFCP_Plugin::instance();
}

add_action( 'plugins_loaded', 'wfcp', 5 );
