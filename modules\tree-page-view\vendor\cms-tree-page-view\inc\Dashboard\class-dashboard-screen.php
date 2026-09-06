<?php
/**
 * Dashboard widget screen: registers the per-post-type quick-nav widgets and
 * mounts the React app into them.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Dashboard;

use CMS_Tree_Page_View\Tree_View_Screen;

/**
 * Registers and renders the dashboard "… Tree" quick-nav widgets (React).
 */
class Dashboard_Screen {

	const ROOT_CLASS = 'cms-tpv-dashboard-root';

	/**
	 * Hook registration entry point (called from index.php).
	 */
	public static function register_hooks() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widgets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Post types enabled for the dashboard, for the current (capable) user.
	 *
	 * @return string[] Registered post type slugs, or empty when not permitted.
	 */
	private static function enabled_post_types() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return array();
		}
		$options = \CMS_Tree_Page_View\Settings\Options::get();
		$types   = array();
		foreach ( (array) $options['dashboard'] as $post_type ) {
			if ( get_post_type_object( $post_type ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}

	/**
	 * Register one dashboard widget per enabled post type.
	 */
	public static function add_widgets() {
		foreach ( self::enabled_post_types() as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			$widget_name      = sprintf(
				/* translators: %1$s: post type name (e.g. "Pages") */
				_x( '%1$s Tree', 'name of dashboard', 'cms-tree-page-view' ),
				$post_type_object->labels->singular_name
			);
			wp_add_dashboard_widget(
				"cms_tpv_dashboard_widget_{$post_type}",
				$widget_name,
				static function () use ( $post_type ) {
					Dashboard_Screen::render_widget( $post_type );
				}
			);
		}
	}

	/**
	 * Render one widget's React mount container.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function render_widget( $post_type ) {
		$tree_url = \CMS_Tree_Page_View\Admin\Menu::get_tree_view_url( $post_type );
		// No "Loading…" placeholder text: React fills this container with a
		// skeleton the moment it mounts, and a competing text line only flashes
		// and reads as a second, conflicting loading state. The <noscript>
		// fallback still gives a plain link when JavaScript is off.
		// The inline min-height mirrors the stylesheet's .cms-tpv-dashboard-root
		// rule so the widget reserves its height from first paint — before
		// build/style-index.css has applied — instead of briefly collapsing to a
		// sliver until the CSS (and then React) catches up.
		printf(
			'<div class="%1$s" data-post-type="%2$s" style="min-height:140px">',
			esc_attr( self::ROOT_CLASS ),
			esc_attr( $post_type )
		);

		// Without the post type ticked "In menu" there is no Tree View page to open,
		// so the fallback link is dropped rather than rendered with an empty href
		// that reloads the dashboard (todo 57). The React app handles the same case
		// by linking each row to its own editor instead.
		if ( '' !== $tree_url ) {
			printf(
				'<noscript><a href="%1$s">%2$s</a></noscript>',
				esc_url( $tree_url ),
				esc_html__( 'Open full tree', 'cms-tree-page-view' )
			);
		}

		echo '</div>';
	}

	/**
	 * Enqueue the React bundle + per-post-type bootstrap map, dashboard only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}
		$types = self::enabled_post_types();
		if ( empty( $types ) ) {
			return;
		}

		$asset_path = dirname( __DIR__, 2 ) . '/build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => CMS_TPV_VERSION,
			);

		wp_enqueue_script(
			Tree_View_Screen::HANDLE,
			CMS_TPV_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style = dirname( __DIR__, 2 ) . '/build/style-index.css';
		if ( file_exists( $style ) ) {
			wp_enqueue_style( Tree_View_Screen::HANDLE, CMS_TPV_URL . 'build/style-index.css', array( 'wp-components' ), $asset['version'] );
		}

		$map = array();
		foreach ( $types as $post_type ) {
			$map[ $post_type ] = Tree_View_Screen::bootstrap_data( $post_type );
		}

		// wp_add_inline_script + wp_json_encode, not wp_localize_script, so the
		// bootstrap's PHP booleans stay booleans in JS — see the matching note
		// in Tree_View_Screen::enqueue().
		wp_add_inline_script(
			Tree_View_Screen::HANDLE,
			'var cmsTpvDashboard = ' . wp_json_encode( $map ) . ';',
			'before'
		);
	}
}
