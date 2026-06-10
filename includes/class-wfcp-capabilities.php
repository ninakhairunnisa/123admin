<?php
/**
 * Panel capability system.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines the granular panel capabilities and grants them dynamically
 * to users based on the per-role permission matrix in settings.
 *
 * Capabilities are never written to the database; they are resolved at
 * runtime via the user_has_cap filter, so changing the matrix takes
 * effect immediately and uninstalling leaves no residue.
 */
class WFCP_Capabilities {

	public const ACCESS = 'wfcp_access';

	/** Capability groups shown in the settings UI. */
	public const GROUPS = array(
		'products' => array( 'view', 'create', 'edit', 'delete', 'stock', 'price' ),
		'orders'   => array( 'view', 'create', 'edit', 'delete', 'status', 'print' ),
		'users'    => array( 'view', 'edit', 'block' ),
		'reports'  => array( 'view', 'export' ),
	);

	private WFCP_Settings $settings;

	public function __construct( WFCP_Settings $settings ) {
		$this->settings = $settings;
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
	}

	/**
	 * Full list of panel capabilities, e.g. wfcp_products_edit.
	 *
	 * @return string[]
	 */
	public static function all_caps(): array {
		$caps = array( self::ACCESS );
		foreach ( self::GROUPS as $group => $actions ) {
			foreach ( $actions as $action ) {
				$caps[] = "wfcp_{$group}_{$action}";
			}
		}
		return $caps;
	}

	/**
	 * Grants wfcp_* capabilities according to the settings matrix.
	 *
	 * @param array    $allcaps All user capabilities.
	 * @param array    $caps    Required primitive caps.
	 * @param array    $args    Arguments (requested cap, user id, ...).
	 * @param \WP_User $user    User object.
	 */
	public function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		$requested = (string) ( $args[0] ?? '' );
		if ( ! str_starts_with( $requested, 'wfcp_' ) || ! $user instanceof WP_User || ! $user->exists() ) {
			return $allcaps;
		}

		// Blocked users never get panel access.
		if ( get_user_meta( $user->ID, 'wfcp_blocked', true ) ) {
			return $allcaps;
		}

		$allowed_roles = $this->settings->allowed_roles();
		$granted       = array();

		foreach ( (array) $user->roles as $role ) {
			if ( ! in_array( $role, $allowed_roles, true ) ) {
				continue;
			}
			$granted[] = self::ACCESS;
			$granted   = array_merge( $granted, $this->settings->role_permissions( $role ) );
		}

		/**
		 * Filters the panel capabilities granted to a user.
		 *
		 * @param string[] $granted Granted wfcp_* capabilities.
		 * @param \WP_User $user    User being checked.
		 */
		$granted = apply_filters( 'wfcp_user_caps', array_unique( $granted ), $user );

		foreach ( $granted as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Capabilities of the current user as cap => bool map (for the JS app).
	 */
	public function current_user_caps(): array {
		$map = array();
		foreach ( self::all_caps() as $cap ) {
			$map[ $cap ] = current_user_can( $cap );
		}
		return $map;
	}
}
