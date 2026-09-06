<?php
/**
 * Plugin Name: CMS Tree Page View
 * Plugin URI: https://eskapism.se/code-playground/cms-tree-page-view/
 * Description: Adds a CMS-like tree view of all your pages, like the view often found in a page-focused CMS. Use the tree view to edit, view, add pages and search pages (very useful if you have many pages). And with drag and drop you can rearrange the order of your pages. Page management won't get any easier than this!
 * Text Domain: cms-tree-page-view
 * Version: 2.5.2
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Pär Thernström
 * Author URI: https://eskapism.se/
 * License: GPL2
 *
 * @package cms-tree-page-view
 */

/*
	Copyright 2010  Pär Thernström (email: par.thernstrom@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMS_TPV_VERSION', '2.5.2' );
define( 'CMS_TPV_NAME', 'CMS Tree Page View' );

require __DIR__ . '/functions.php';
require_once __DIR__ . '/inc/class-autoloader.php';

add_action(
	'rest_api_init',
	function () {
		( new CMS_Tree_Page_View\REST\Tree_Controller() )->register_routes();
		( new CMS_Tree_Page_View\REST\Mutation_Controller() )->register_routes();
		( new CMS_Tree_Page_View\REST\Activity_Controller() )->register_routes();
	}
);

// Simple History (https://simple-history.com/) is an optional dependency: this
// action is only fired by Simple History itself, so registration is a no-op
// (and the logger class is never loaded) when it isn't installed/active.
//
// The action has fired since Simple History 2.1, but Simple_History_Logger
// extends \Simple_History\Loggers\Logger, which only exists from 4.0. Without
// the guard below, registering on an older Simple History autoloads our class
// and fatals on the missing parent — so check the parent, not the action.
add_action(
	'simple_history/add_custom_logger',
	function ( $simple_history ) {
		if ( ! class_exists( '\\Simple_History\\Loggers\\Logger' ) ) {
			return;
		}

		$simple_history->register_logger( 'CMS_Tree_Page_View\\Integrations\\Simple_History_Logger' );
	}
);

// Find the plugin directory URL, honouring mu-plugin / network-plugin loads.
$cms_tpv_plugin_file = __FILE__;
if ( isset( $cms_tpv_mu_plugin ) ) {
	$cms_tpv_plugin_file = $cms_tpv_mu_plugin;
}
if ( isset( $cms_tpv_network_plugin ) ) {
	$cms_tpv_plugin_file = $cms_tpv_network_plugin;
}
if ( isset( $cms_tpv_plugin ) ) {
	$cms_tpv_plugin_file = $cms_tpv_plugin;
}

define( 'CMS_TPV_PLUGIN_FILE', $cms_tpv_plugin_file );
define( 'CMS_TPV_URL', plugin_dir_url( $cms_tpv_plugin_file ) );

// Translations load automatically: WordPress just-in-time loads language packs from
// translate.wordpress.org (WP 4.6+), so no load_plugin_textdomain() call is needed.

// On admin init: add styles and scripts.
add_action( 'admin_init', 'cms_tpv_admin_init' );
add_action( 'admin_enqueue_scripts', 'cms_tpv_admin_enqueue_scripts' );
add_action( 'admin_enqueue_scripts', array( 'CMS_Tree_Page_View\\Tree_View_Screen', 'enqueue' ) );
CMS_Tree_Page_View\Dashboard\Dashboard_Screen::register_hooks();
CMS_Tree_Page_View\Integrations\Elementor_Integration::register_hooks();
CMS_Tree_Page_View\Integrations\Simple_History_Integration::register_hooks();
CMS_Tree_Page_View\Data\Post_Visibility::register_hooks();
add_action( 'admin_init', 'cms_tpv_save_settings' );
add_action( 'admin_init', 'cms_tpv_maybe_show_welcome_notice' );

// Hook onto dashboard and admin menu.
add_action( 'admin_menu', 'cms_tpv_admin_menu' );
add_action( 'current_screen', 'cms_tpv_add_tree_view_link_to_list_screen' );

// Activation.
define( 'CMS_TPV_MOVE_PERMISSION', 'move_cms_tree_view_page' );
register_activation_hook( __FILE__, 'cms_tpv_install' );
register_uninstall_hook( __FILE__, 'cms_tpv_uninstall' );

// Catch upgrade. Moved from plugins_loaded to init to be able to use wp_roles.
add_action( 'init', 'cms_tpv_plugins_loaded', 1 );
