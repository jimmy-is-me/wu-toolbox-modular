<?php
/**
 * Plugin lifecycle: activation, uninstall, and version-change upgrade.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Lifecycle;

use CMS_Tree_Page_View\Admin\Capabilities;
use CMS_Tree_Page_View\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the activation hook, the uninstall hook, and the on-init upgrade check.
 */
class Installer {

	/**
	 * Activation hook: seed default options, queue the welcome notice, and stamp
	 * the current version.
	 */
	public static function install() {
		// Queue a one-shot welcome notice for the next admin page load (todo 31).
		update_option( 'cms_tpv_welcome_notice', 'pending' );

		// First install or pre custom posts version: make sure pages are enabled by default.
		Options::setup_defaults();

		// Set to current version.
		update_option( 'cms_tpv_version', CMS_TPV_VERSION );
	}

	/**
	 * Uninstall hook: remove the tree-move capability from the roles that had it,
	 * and clean up the retired promo-box options (replaced by the permanent
	 * "About this plugin" card — Admin\Promo::help_card()).
	 */
	public static function uninstall() {
		Capabilities::remove();

		delete_option( 'cms_tpv_show_annoying_little_box' );
		delete_option( 'cms_tpv_show_promo' );
	}

	/**
	 * On init, detect a plugin version change and run the upgrade routine.
	 *
	 * Compares the stored plugin version against the current one; a mismatch means
	 * a fresh install or an upgrade, so bump the stored version and (re)grant the
	 * move capability.
	 */
	public static function plugins_loaded() {
		$installed_version = get_option( 'cms_tpv_version', 0 );

		if ( (string) $installed_version !== CMS_TPV_VERSION ) {

			// New version — upgrade the stored version to the current one.
			update_option( 'cms_tpv_version', CMS_TPV_VERSION );

			// Set up caps/permissions.
			Capabilities::setup();
		}
	}
}
