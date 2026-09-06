<?php
/**
 * Screen bootstrap for the standalone React Tree View admin page.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues and bootstraps the React Tree View on the standalone admin screen.
 */
class Tree_View_Screen {

	const HANDLE = 'cms-tpv-tree-view';

	/**
	 * Whether the current admin screen is a standalone Tree View page.
	 *
	 * @return bool
	 */
	public static function is_react_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$options = \CMS_Tree_Page_View\Settings\Options::get();
		foreach ( $options['menu'] as $one_post_type ) {
			if ( false !== strpos( $screen->id, "_page_cms-tpv-page-{$one_post_type}" ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The status-filter view the tree should open on.
	 *
	 * Mirrors the core Pages list: clicking "Tree" from a status tab
	 * (edit.php?post_status=draft) carries that status through, so the tree opens
	 * pre-filtered to it. Only statuses that have a matching tree tab are honored;
	 * anything else — no param, or Private/Scheduled, which have no tab — opens on
	 * 'all'.
	 *
	 * @return string One of all|publish|draft|pending|trash.
	 */
	private static function initial_view() {
		// A read-only display param (which filter tab to show) — it changes nothing
		// server-side, so it needs no nonce. It is sanitized before use.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
		$views  = array( 'publish', 'draft', 'pending', 'trash' );
		return in_array( $status, $views, true ) ? $status : 'all';
	}

	/**
	 * Bootstrap data passed to the React app.
	 *
	 * @param string $post_type Selected post type.
	 * @return array<string,mixed>
	 */
	public static function bootstrap_data( $post_type ) {
		$post_type = sanitize_key( $post_type );

		// The post type's registered Dashicon, when it uses one (custom post types
		// often do; built-in post/page leave this empty and fall back client-side
		// to the Gutenberg page icon). Built-in 'page'/'post' are excluded even
		// though core itself sets their menu_icon to a 'dashicons-' value
		// (dashicons-admin-page / dashicons-admin-post) — that's WP's own sidebar
		// menu icon, not a deliberate per-post-type choice, and the tree should
		// use the Gutenberg icon for them regardless.
		$pt_obj = get_post_type_object( $post_type );
		// @phpstan-ignore-next-line function.alreadyNarrowedType (is_string() guard kept for runtime safety: WP_Post_Type::$menu_icon defaults to null, but php-stubs/wordpress-stubs declares it as a non-nullable string)
		$menu_icon = ( $pt_obj && ! in_array( $post_type, array( 'page', 'post' ), true ) && is_string( $pt_obj->menu_icon ) && 0 === strpos( $pt_obj->menu_icon, 'dashicons-' ) )
			? $pt_obj->menu_icon
			: '';

		return array(
			'postType'            => $post_type,
			'postTypeLabel'       => $pt_obj ? $pt_obj->labels->name : $post_type,
			// Mirrors the legacy cms_tpv_is_post_type_hierarchical() special case:
			// 'post' is treated as hierarchical too, even though core itself
			// registers it as non-hierarchical.
			'hierarchical'        => (bool) ( $pt_obj && \CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical( $pt_obj ) ),
			'menuIcon'            => $menu_icon,
			'siteName'            => get_bloginfo( 'name' ),
			'view'                => self::initial_view(),
			'restNamespace'       => 'cms-tpv/v1',
			'restRoot'            => esc_url_raw( rest_url( 'cms-tpv/v1' ) ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'options'             => array(
				'showAuthor' => true,
				'showDate'   => true,
				'showPageId' => false,
			),
			'simpleHistoryActive' => defined( 'SIMPLE_HISTORY_VERSION' ) || class_exists( 'Simple_History\\Simple_History' ),
			'adminUrls'           => array(
				'addNew'               => admin_url( 'post' === $post_type ? 'post-new.php' : 'post-new.php?post_type=' . rawurlencode( $post_type ) ),
				'listView'             => admin_url( 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . rawurlencode( $post_type ) ),
				'settings'             => \CMS_Tree_Page_View\Admin\Menu::get_settings_url(),
				'treeViewUrl'          => \CMS_Tree_Page_View\Admin\Menu::get_tree_view_url( $post_type ),
				'simpleHistoryLog'     => admin_url( 'admin.php?page=simple_history_admin_menu_page' ),
				// Omitted (empty string) for a user who can't install plugins —
				// the Simple History promo falls back to a plain informational
				// line instead of a link/button they couldn't act on anyway.
				// Deep-links to Simple History's own plugin-details view (with
				// its Install Now button), not the installer's search results:
				// landing on a results list adds a "which card is it?" step
				// the promo's click never promised.
				'installSimpleHistory' => current_user_can( 'install_plugins' )
					? admin_url( 'plugin-install.php?tab=plugin-information&plugin=simple-history' )
					: '',
			),
			'l10n'                => array(
				'pageTree'    => __( 'Page Tree', 'cms-tree-page-view' ),
				'selectAPage' => __( 'Select a page in the tree to see its preview, details and history.', 'cms-tree-page-view' ),
				'loading'     => __( 'Loading…', 'cms-tree-page-view' ),
				// WordPress' own per-post-type "not found" label (e.g. "No pages
				// found." / "No products found."), already translated — so the
				// dashboard empty state reads correctly for every post type.
				'notFound'    => ( $pt_obj && isset( $pt_obj->labels->not_found ) )
					? $pt_obj->labels->not_found
					: __( 'No pages found.', 'cms-tree-page-view' ),
			),
		);
	}

	/**
	 * Enqueue the built bundle + localize bootstrap, only on the React screen.
	 */
	public static function enqueue() {
		if ( ! self::is_react_screen() ) {
			return;
		}

		$asset_path = dirname( __DIR__ ) . '/build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => CMS_TPV_VERSION,
			);

		wp_enqueue_script(
			self::HANDLE,
			CMS_TPV_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style = dirname( __DIR__ ) . '/build/style-index.css';
		if ( file_exists( $style ) ) {
			wp_enqueue_style( self::HANDLE, CMS_TPV_URL . 'build/style-index.css', array( 'wp-components' ), $asset['version'] );
		}

		// wp_add_inline_script + wp_json_encode, not wp_localize_script: localize
		// string-casts every scalar (PHP false → "", true → "1"), which forced JS
		// consumers into truthiness workarounds. JSON keeps booleans booleans, so
		// the bootstrap's types are honest for every consumer. Dashboard_Screen
		// emits its map the same way — keep the two in sync.
		wp_add_inline_script(
			self::HANDLE,
			'var cmsTpvTreeView = ' . wp_json_encode( self::bootstrap_data( \CMS_Tree_Page_View\Admin\Menu::get_selected_post_type() ) ) . ';',
			'before'
		);
	}

	/**
	 * Render the React mount container.
	 */
	public static function render_root() {
		echo '<div id="cms-tpv-react-root" class="cms-tpv-react-root"></div>';
	}
}
