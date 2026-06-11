<?php
/**
 * Standalone panel router.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the panel at site.com/{slug} (default /123admin) as a fully
 * standalone page: no admin header/footer, no Gutenberg, no widgets,
 * no emoji scripts — only the panel's own CSS/JS is loaded.
 */
class WFCP_Router {

	private WFCP_Settings $settings;
	private WFCP_Capabilities $capabilities;

	public function __construct( WFCP_Settings $settings, WFCP_Capabilities $capabilities ) {
		$this->settings     = $settings;
		$this->capabilities = $capabilities;

		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_render_panel' ), 0 );
	}

	/**
	 * Registers rewrite rules for the panel, its service worker and manifest.
	 */
	public function register_rewrites(): void {
		$slug = preg_quote( $this->settings->slug(), '#' );

		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?wfcp_panel=app', 'top' );
		add_rewrite_rule( '^' . $slug . '/sw\.js$', 'index.php?wfcp_panel=sw', 'top' );
		add_rewrite_rule( '^' . $slug . '/manifest\.webmanifest$', 'index.php?wfcp_panel=manifest', 'top' );

		if ( get_option( 'wfcp_flush_rewrite' ) ) {
			delete_option( 'wfcp_flush_rewrite' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Adds the panel query var.
	 *
	 * @param array $vars Query vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'wfcp_panel';
		return $vars;
	}

	/**
	 * Intercepts panel requests very early and renders the standalone shell,
	 * bypassing the theme and the whole wp-admin stack.
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function maybe_render_panel( \WP $wp ): void {
		$view = $wp->query_vars['wfcp_panel'] ?? '';
		if ( '' === $view ) {
			return;
		}

		switch ( $view ) {
			case 'sw':
				$this->serve_service_worker();
				break;
			case 'manifest':
				$this->serve_manifest();
				break;
			default:
				$this->render_panel();
		}
		exit;
	}

	/**
	 * Renders the panel HTML shell for authorised users.
	 */
	private function render_panel(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->settings->panel_url() ) );
			exit;
		}

		if ( ! current_user_can( WFCP_Capabilities::ACCESS ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to access this panel. Contact your store administrator.', 'wfcp' ),
				esc_html__( 'Access denied', 'wfcp' ),
				array( 'response' => 403 )
			);
		}

		wfcp()->security->send_panel_headers();

		$boot     = $this->boot_config();
		$template = WFCP_DIR . 'templates/panel.php';

		/**
		 * Filters the panel template path, enabling white-label overrides.
		 *
		 * @param string $template Absolute template path.
		 */
		$template = apply_filters( 'wfcp_panel_template', $template );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		include $template;
	}

	/**
	 * Boot configuration injected into the SPA (nonce, caps, i18n, urls).
	 */
	public function boot_config(): array {
		$user     = wp_get_current_user();
		$locale   = get_user_locale();
		$is_rtl   = in_array( substr( $locale, 0, 2 ), array( 'fa', 'ar', 'he', 'ur' ), true );
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ) : '$';

		$config = array(
			'version'    => WFCP_VERSION,
			'apiRoot'    => esc_url_raw( rest_url( 'wfcp/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'panelUrl'   => $this->settings->panel_url(),
			'logoutUrl'  => wp_logout_url( home_url( '/' ) ),
			'wpAdminUrl' => admin_url(),
			'siteName'   => get_bloginfo( 'name' ),
			'locale'     => $locale,
			'rtl'        => $is_rtl,
			'theme'      => $this->settings->get( 'theme', 'auto' ),
			'perPage'    => (int) $this->settings->get( 'per_page', 25 ),
			'currency'   => $currency,
			'hasWoo'     => wfcp()->has_woocommerce(),
			'user'       => array(
				'id'     => $user->ID,
				'name'   => $user->display_name,
				'email'  => $user->user_email,
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
			'caps'       => $this->capabilities->current_user_caps(),
			'statuses'   => $this->order_statuses(),
			'i18n'       => WFCP_I18n::strings(),
			'assets'     => array(
				'css'  => $this->asset_url( 'assets/css/panel.css' ),
				'icon' => WFCP_URL . 'assets/img/icon.svg',
				'js'   => array(
					$this->asset_url( 'assets/js/app.js' ),
					$this->asset_url( 'assets/js/views.js' ),
				),
			),
		);

		/**
		 * Filters the SPA boot configuration.
		 *
		 * @param array $config Boot config.
		 */
		return apply_filters( 'wfcp_boot_config', $config );
	}

	/**
	 * Cache-busted asset URL based on file modification time.
	 *
	 * @param string $relative Relative path inside the plugin.
	 */
	private function asset_url( string $relative ): string {
		$file = WFCP_DIR . $relative;
		$ver  = file_exists( $file ) ? (string) filemtime( $file ) : WFCP_VERSION;
		return add_query_arg( 'v', $ver, WFCP_URL . $relative );
	}

	/**
	 * Serves a minimal same-scope service worker for PWA installability.
	 */
	private function serve_service_worker(): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		// App-shell cache only; API responses are always network-first.
		echo "const CACHE='wfcp-v" . esc_js( WFCP_VERSION ) . "';
self.addEventListener('install',e=>{self.skipWaiting();});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(n=>n!==CACHE).map(n=>caches.delete(n)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',e=>{
  const u=new URL(e.request.url);
  if(e.request.method!=='GET'||u.pathname.includes('/wp-json/')){return;}
  if(u.pathname.endsWith('.css')||u.pathname.endsWith('.js')||u.pathname.endsWith('.woff2')){
    e.respondWith(caches.open(CACHE).then(c=>c.match(e.request).then(r=>r||fetch(e.request).then(n=>{c.put(e.request,n.clone());return n;}))));
  }
});";
	}

	/**
	 * Serves the PWA web manifest.
	 */
	private function serve_manifest(): void {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'name'             => get_bloginfo( 'name' ) . ' – ' . __( 'Store Panel', 'wfcp' ),
				'short_name'       => __( 'Store Panel', 'wfcp' ),
				'start_url'        => $this->settings->panel_url(),
				'scope'            => $this->settings->panel_url(),
				'display'          => 'standalone',
				'background_color' => '#141218',
				'theme_color'      => '#6750a4',
				'icons'            => array(
					array(
						'src'   => WFCP_URL . 'assets/img/icon-192.png',
						'sizes' => '192x192',
						'type'  => 'image/png',
					),
					array(
						'src'     => WFCP_URL . 'assets/img/icon-512.png',
						'sizes'   => '512x512',
						'type'    => 'image/png',
						'purpose' => 'any maskable',
					),
					array(
						'src'   => WFCP_URL . 'assets/img/icon.svg',
						'sizes' => 'any',
						'type'  => 'image/svg+xml',
					),
				),
			)
		);
	}

	/**
	 * Order statuses exposed to the SPA, without internal checkout drafts.
	 */
	private function order_statuses(): array {
		if ( ! wfcp()->has_woocommerce() ) {
			return array();
		}
		$statuses = wc_get_order_statuses();
		unset( $statuses['wc-checkout-draft'] );
		return $statuses;
	}
}
