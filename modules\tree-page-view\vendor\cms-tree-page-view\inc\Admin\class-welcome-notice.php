<?php
/**
 * One-shot welcome admin notice shown after activation.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a single dismissible welcome notice after activation, pointing at the
 * tree view page (todo 31).
 *
 * A one-shot state machine on the 'cms_tpv_welcome_notice' option: activation
 * sets it to 'pending'; the first non-AJAX admin page load by a user who can edit
 * pages flips it to 'seen' and hooks the renderer, so the notice appears on
 * exactly one page load and never again.
 */
class Welcome_Notice {

	/**
	 * On admin init: if a welcome notice is pending and the current user is the
	 * tree's audience, consume the one-shot and hook the renderer.
	 *
	 * The renderer is registered under its global-function name
	 * (cms_tpv_render_welcome_notice) so existing remove_action() calls and the
	 * has_action() test assertion keep working.
	 */
	public static function maybe_show() {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( get_option( 'cms_tpv_welcome_notice' ) !== 'pending' ) {
			return;
		}

		// Only the tree's audience should see (and consume) the one-shot, so a
		// lower-privilege user logging in first does not waste it.
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		update_option( 'cms_tpv_welcome_notice', 'seen' );
		add_action( 'admin_notices', 'cms_tpv_render_welcome_notice' );
	}

	/**
	 * Render the dismissible welcome notice pointing at the tree view page.
	 */
	public static function render() {
		$tree_url     = \CMS_Tree_Page_View\Admin\Menu::get_tree_view_url( 'page' );
		$settings_url = \CMS_Tree_Page_View\Admin\Menu::get_settings_url();

		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'settings', 'cms-tree-page-view' ) . '</a>';

		if ( '' === $tree_url ) {

			// Pages are ticked in neither setting, so no tree page is registered and
			// there is nothing to open — a link would land on WordPress's "Sorry, you
			// are not allowed to access this page." (todo 57). Send them to settings,
			// which is where the tree gets turned on.
			$message = sprintf(
				/* translators: %1$s: link to the plugin's settings page, with the "settings" anchor text. */
				__( '<strong>CMS Tree Page View is ready!</strong> See all your pages as a tree — drag to reorder, plus inline edit, search, and add.<br>Choose which post types show a tree in the %1$s.', 'cms-tree-page-view' ),
				$settings_link
			);

		} else {

			$message = sprintf(
				/* translators: %1$s: link to the tree view page, on its own line, with the "Open the tree view" anchor text. %2$s: link to the settings page, with the "settings" anchor text. */
				__( '<strong>CMS Tree Page View is ready!</strong> See all your pages as a tree — drag to reorder, plus inline edit, search, and add.<br>%1$s<br>You can choose which post types show a tree in the %2$s.', 'cms-tree-page-view' ),
				'<a href="' . esc_url( $tree_url ) . '">' . __( 'Open the tree view &rarr;', 'cms-tree-page-view' ) . '</a>',
				$settings_link
			);
		}

		$allowed_html = array(
			'strong' => array(),
			'br'     => array(),
			'a'      => array( 'href' => array() ),
		);

		if ( function_exists( 'wp_admin_notice' ) ) {
			wp_admin_notice(
				$message,
				array(
					'type'               => 'success',
					'dismissible'        => true,
					'paragraph_wrap'     => true,
					'additional_classes' => array( 'cms-tpv-welcome-notice' ),
				)
			);
			return;
		}

		// Fallback for WP < 6.4 (declared floor is 6.0), where wp_admin_notice() is absent.
		echo '<div class="notice notice-success is-dismissible cms-tpv-welcome-notice"><p>' . wp_kses( $message, $allowed_html ) . '</p></div>';
	}
}
