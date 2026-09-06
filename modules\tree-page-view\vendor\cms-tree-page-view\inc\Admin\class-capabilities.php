<?php
/**
 * Role capabilities for the tree-move permission.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants and removes the custom move capability (CMS_TPV_MOVE_PERMISSION) on the
 * roles that are allowed to reorder the tree.
 */
class Capabilities {

	/**
	 * Roles that receive the tree-move capability.
	 *
	 * @return array<string, string[]> Role name => capabilities to grant.
	 */
	private static function role_caps() {
		return array(
			'administrator' => array( CMS_TPV_MOVE_PERMISSION ),
			'editor'        => array( CMS_TPV_MOVE_PERMISSION ),
		);
	}

	/**
	 * Grant the tree-move capability to the administrator and editor roles.
	 */
	public static function setup() {
		foreach ( self::role_caps() as $role => $caps ) {
			self::add_caps_to_role( $role, $caps );
		}
	}

	/**
	 * Remove the tree-move capability from the administrator and editor roles.
	 */
	public static function remove() {
		foreach ( self::role_caps() as $role => $caps ) {
			self::remove_caps_from_role( $role, $caps );
		}
	}

	/**
	 * Add an array of capabilities to a role.
	 *
	 * @param string   $role Role name to add the capabilities to.
	 * @param string[] $caps Capabilities to add.
	 */
	public static function add_caps_to_role( $role, $caps ) {
		global $wp_roles;

		if ( $wp_roles->is_role( $role ) ) {
			$role = get_role( $role );
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove an array of capabilities from a role.
	 *
	 * @param string   $role Role name to remove the capabilities from.
	 * @param string[] $caps Capabilities to remove.
	 */
	public static function remove_caps_from_role( $role, $caps ) {
		global $wp_roles;

		if ( $wp_roles->is_role( $role ) ) {
			$role = get_role( $role );
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
