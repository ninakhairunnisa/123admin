<?php
/**
 * Settings REST controller.
 *
 * @package WFCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Panel configuration: slug, allowed roles, permission matrix.
 * Restricted to administrators (manage_options).
 */
class WFCP_Settings_Controller extends WFCP_REST_Controller {

	protected string $rest_base = 'settings';

	public function register_routes(): void {
		$this->route(
			'',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_only' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_only' ),
				),
			)
		);
	}

	/**
	 * Settings management requires full admin rights.
	 */
	public function admin_only() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wfcp_forbidden', __( 'Only administrators can manage panel settings.', 'wfcp' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Current settings plus the data needed to render the settings UI.
	 */
	public function get_settings(): WP_REST_Response {
		$settings = wfcp()->settings->all();

		$roles = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$roles[] = array(
				'slug' => $slug,
				'name' => translate_user_role( $role['name'] ),
			);
		}

		return rest_ensure_response(
			array(
				'settings'   => array(
					'slug'        => $settings['slug'],
					'roles'       => $settings['roles'],
					'permissions' => $settings['permissions'],
					'theme'       => $settings['theme'],
					'per_page'    => $settings['per_page'],
					'low_stock'   => $settings['low_stock'],
				),
				'all_roles'  => $roles,
				'cap_groups' => WFCP_Capabilities::GROUPS,
				'panel_url'  => wfcp()->settings->panel_url(),
			)
		);
	}

	/**
	 * Saves settings.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params  = (array) $request->get_json_params();
		$allowed = array_intersect_key( $params, array_flip( array( 'slug', 'roles', 'permissions', 'theme', 'per_page', 'low_stock' ) ) );

		wfcp()->settings->update( $allowed );
		$this->audit( 'settings.update', 'settings', 0, array( 'keys' => array_keys( $allowed ) ) );

		return $this->get_settings();
	}
}
