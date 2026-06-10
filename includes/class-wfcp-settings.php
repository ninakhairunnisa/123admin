<?php
/**
 * Plugin settings storage and access.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the wfcp_settings option: panel slug, allowed roles and the
 * per-role permission matrix.
 */
class WFCP_Settings {

	public const OPTION_KEY = 'wfcp_settings';

	private ?array $cache = null;

	/**
	 * Default settings.
	 */
	public function defaults(): array {
		return array(
			'slug'        => '123admin',
			'roles'       => array( 'administrator', 'shop_manager' ),
			'permissions' => array(
				'shop_manager' => WFCP_Capabilities::all_caps(),
			),
			'theme'       => 'auto',
			'per_page'    => 25,
			'low_stock'   => (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
		}
		return $this->cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Persists settings after sanitisation.
	 *
	 * @param array $settings Partial settings to merge and save.
	 */
	public function update( array $settings ): bool {
		$current = $this->all();
		$merged  = array_merge( $current, $settings );

		$merged['slug'] = $this->sanitize_slug( (string) ( $merged['slug'] ?? '123admin' ) );

		$merged['roles'] = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $merged['roles'] ),
				static fn( $role ) => null !== get_role( $role )
			)
		);
		if ( ! in_array( 'administrator', $merged['roles'], true ) ) {
			$merged['roles'][] = 'administrator';
		}

		$permissions = array();
		foreach ( (array) $merged['permissions'] as $role => $caps ) {
			$role = sanitize_key( $role );
			if ( null === get_role( $role ) ) {
				continue;
			}
			$permissions[ $role ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $caps ), WFCP_Capabilities::all_caps() ) );
		}
		$merged['permissions'] = $permissions;

		$merged['theme']     = in_array( $merged['theme'], array( 'auto', 'light', 'dark' ), true ) ? $merged['theme'] : 'auto';
		$merged['per_page']  = max( 5, min( 100, (int) $merged['per_page'] ) );
		$merged['low_stock'] = max( 0, (int) $merged['low_stock'] );

		$this->cache = $merged;
		update_option( self::OPTION_KEY, $merged, false );

		// The panel slug is part of the rewrite rules; refresh them when it changes.
		if ( get_option( 'wfcp_active_slug' ) !== $merged['slug'] ) {
			update_option( 'wfcp_active_slug', $merged['slug'], false );
			update_option( 'wfcp_flush_rewrite', 1, false );
		}

		/**
		 * Fires after 123Admin settings have been saved.
		 *
		 * @param array $merged New settings.
		 */
		do_action( 'wfcp_settings_updated', $merged );

		return true;
	}

	/**
	 * Sanitises the panel slug (e.g. 123admin, store, panel, control, manager).
	 *
	 * @param string $slug Raw slug.
	 */
	public function sanitize_slug( string $slug ): string {
		$slug = trim( sanitize_title_with_dashes( $slug ), '-/' );
		$reserved = array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'shop', 'cart', 'checkout', 'my-account' );
		if ( '' === $slug || in_array( $slug, $reserved, true ) ) {
			$slug = '123admin';
		}
		return $slug;
	}

	/**
	 * Returns the configured panel slug.
	 */
	public function slug(): string {
		return $this->sanitize_slug( (string) $this->get( 'slug', '123admin' ) );
	}

	/**
	 * Returns the absolute panel URL, e.g. https://site.com/123admin.
	 */
	public function panel_url(): string {
		return home_url( '/' . $this->slug() . '/' );
	}

	/**
	 * Roles allowed to enter the panel.
	 */
	public function allowed_roles(): array {
		$roles = (array) $this->get( 'roles', array( 'administrator' ) );
		if ( ! in_array( 'administrator', $roles, true ) ) {
			$roles[] = 'administrator';
		}
		/**
		 * Filters roles that may access the panel.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return apply_filters( 'wfcp_allowed_roles', $roles );
	}

	/**
	 * Capability list configured for a given role.
	 *
	 * @param string $role Role slug.
	 */
	public function role_permissions( string $role ): array {
		if ( 'administrator' === $role ) {
			return WFCP_Capabilities::all_caps();
		}
		$matrix = (array) $this->get( 'permissions', array() );
		return (array) ( $matrix[ $role ] ?? array() );
	}
}
