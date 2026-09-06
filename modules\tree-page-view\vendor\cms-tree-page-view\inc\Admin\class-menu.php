<?php
/**
 * Admin menu registration and the plugin's standalone tree screens.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the per-post-type "Tree View" admin submenus and the settings page,
 * routes the standalone tree screen, injects the classic-list "List | Tree" view
 * switch, adds the Plugins-screen "Settings" action link, and enqueues the
 * legacy stylesheet on the plugin's own pages. Consumed across the plugin via
 * the cms_tpv_* shims (which remain the registered hook callbacks).
 */
class Menu {

	/**
	 * Detect if we are on a page that use CMS Tree Page View
	 */
	public static function is_one_of_our_pages() {

		$options = \CMS_Tree_Page_View\Settings\Options::get();

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$current_screen = get_current_screen();
		$is_plugin_page = false;

		// Check if current page is one of the ones defined in $options["menu"].
		foreach ( $options['menu'] as $one_post_type ) {
			if ( strpos( $current_screen->id, "_page_cms-tpv-page-{$one_post_type}" ) !== false ) {
				$is_plugin_page = true;
				break;
			}
		}

		if ( $current_screen->id === 'settings_page_cms-tpv-options' ) {
			// Is settings page for plugin.
			$is_plugin_page = true;
		} elseif ( $current_screen->id === 'dashboard' && ! empty( $options['dashboard'] ) ) {
			// At least one post type is enabled to be visible on dashboard.
			$is_plugin_page = true;
		}

		return $is_plugin_page;
	}

	/**
	 * Add styles and scripts to pages that use the plugin
	 */
	public static function admin_enqueue_scripts() {

		// The React Tree View screen has its own build/ CSS (enqueued by
		// Tree_View_Screen::enqueue()); this legacy stylesheet still styles the
		// non-React pages that remain — the settings page (.cmtpv_options_form,
		// .cms-tpv-about-card).
		if ( self::is_one_of_our_pages() && ! \CMS_Tree_Page_View\Tree_View_Screen::is_react_screen() ) {
			wp_enqueue_style( 'cms_tpv_styles', CMS_TPV_URL . 'styles/styles.css', array(), CMS_TPV_VERSION );
		}
	}

	/**
	 * Run on admin_init: wire up the plugin action links' Settings link.
	 */
	public static function admin_init() {

		// Add a "Settings" action link to the plugin's row on the Plugins screen.
		// plugin_basename() of the resolved plugin file, not a hardcoded
		// 'cms-tree-page-view/index.php': the filter name must match the actual
		// install path, which differs in symlinked/renamed-folder checkouts.
		add_filter( 'plugin_action_links_' . plugin_basename( CMS_TPV_PLUGIN_FILE ), array( __CLASS__, 'set_plugin_action_links' ) );

		// @todo Register plugin settings via add_settings_section() / register_setting().
	}

	/**
	 * Prepend a "Settings" link to the plugin's action links on the Plugins
	 * screen (first row, before Deactivate — the WP convention), replacing the
	 * former plugin_row_meta placement (grey second row).
	 *
	 * @param array $links Array of plugin action links.
	 * @return array Filtered array of plugin action links.
	 */
	public static function set_plugin_action_links( $links ) {

		$settings_link = sprintf( '<a href="%s">%s</a>', esc_url( self::get_settings_url() ), esc_html__( 'Settings', 'cms-tree-page-view' ) );

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Hide the admin bar inside the Tree View preview iframe. The detail card loads
	 * the front-end preview with a cms_tpv_preview=1 marker; suppress the toolbar so
	 * the thumbnail shows only the page content.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function hide_admin_bar_in_preview( $show ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['cms_tpv_preview'] ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Add a "List | Tree" view switch to the classic edit.php list screen's
	 * title row, for post types that have the standalone React Tree View page
	 * registered (options['menu']). Lets a user jump from the WP list screen to
	 * the tree, and back again via the tree's own List/Tree toggle.
	 *
	 * Earlier versions appended a plain-text "View: List | Tree" to the
	 * views_{screen} filter (WP core's single-line .subsubsub status-filter
	 * list) — cramped among the All/Mine/Published/… counts and easy to miss.
	 * Core gives plugins no PHP hook inside the title row itself
	 * (<h1 class="wp-heading-inline">…<hr class="wp-header-end">, both written
	 * directly in wp-admin/edit.php, not filterable), so
	 * cms_tpv_print_classic_view_switch_script() inserts it there via a small
	 * footer script instead — the standard technique for adding controls next
	 * to a WP admin screen's title.
	 */
	public static function add_tree_view_link_to_list_screen() {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ! $current_screen || 'edit' !== $current_screen->base ) {
			return;
		}

		$options = \CMS_Tree_Page_View\Settings\Options::get();
		if ( ! in_array( $current_screen->post_type, $options['menu'], true ) ) {
			return;
		}

		/**
		 * Filters whether the "List | Tree" view switch is injected into the
		 * classic edit.php list screen for a post type. Return false to keep the
		 * Tree View submenu but not add the switch to the core Pages/Posts list
		 * screen (some users prefer core screens left untouched).
		 *
		 * @param bool   $show      Whether to show the switch. Default true.
		 * @param string $post_type The current list screen's post type.
		 */
		if ( ! apply_filters( 'cms_tree_page_view_show_classic_switch', true, $current_screen->post_type ) ) {
			return;
		}

		// The React screen's compiled stylesheet supplies .cms-tpv-view-switch's
		// rules; this classic list screen doesn't otherwise load it —
		// cms_tpv_is_one_of_our_pages() no longer matches the edit.php screen, so
		// cms_admin_enqueue_scripts() doesn't run here — so load the stylesheet
		// ourselves.
		wp_enqueue_style( 'cms_tpv_react_styles', CMS_TPV_URL . 'build/style-index.css', array(), CMS_TPV_VERSION );

		// Not admin_print_footer_scripts-{$hook_suffix}: $hook_suffix is the admin
		// PHP filename ("edit.php"), the same regardless of post type — not the
		// more specific $current_screen->id ("edit-page") already checked above.
		// The plain, screen-generic hook is used instead, gated by the same
		// current-screen check this function just did before registering it.
		add_action( 'admin_print_footer_scripts', 'cms_tpv_print_classic_view_switch_script' );
	}

	/**
	 * Print inline JS that inserts the "List | Tree" view switch into the
	 * classic list screen's title row, right after the "Add New" button.
	 *
	 * Mirrors the React Tree View screen's <ListTreeToggle> markup/classes
	 * exactly (.cms-tpv-view-switch / __option / is-active) so it looks and
	 * behaves identically on both screens, just with List/Tree swapped: here
	 * List is the current, non-interactive option and Tree links out.
	 */
	public static function print_classic_view_switch_script() {

		$current_screen = get_current_screen();
		$post_type      = $current_screen ? $current_screen->post_type : self::get_selected_post_type();
		$tree_url       = self::get_tree_view_url( $post_type );

		// Carry the current status tab over to the tree, so clicking "Tree" while
		// viewing Drafts (etc.) opens the tree pre-filtered to that status. Only the
		// statuses that have a matching tree tab are passed through; the tree reads
		// this back in Tree_View_Screen::initial_view(). Read-only display param, no
		// state change, so no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
		if ( in_array( $current_status, array( 'publish', 'draft', 'pending', 'trash' ), true ) ) {
			$tree_url = add_query_arg( 'post_status', $current_status, $tree_url );
		}

		// Same hardcoded SVG icons as the React <ListIcon>/<TreeIcon> (src/lib/icons.jsx),
		// so both screens render identical glyphs. Fixed, developer-authored markup —
		// no user data — so it needs no escaping.
		$list_icon = '<svg class="cms-tpv-view-switch__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15" aria-hidden="true" focusable="false"><path d="M3 5h14M3 10h14M3 15h14" /></svg>';
		$tree_icon = '<svg class="cms-tpv-view-switch__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true" focusable="false"><path d="M4 4h9M8 10h9M8 16h9M4 4v12M4 10h4M4 16h4" /></svg>';

		$markup = sprintf(
			'<span class="cms-tpv-view-switch cms-tpv-classic-view-toggle">'
				. '<span class="cms-tpv-view-switch__group">'
					. '<span class="cms-tpv-view-switch__option is-active" aria-current="page">%1$s%2$s</span>'
					. '<a class="cms-tpv-view-switch__option" href="%3$s">%4$s%5$s</a>'
				. '</span>'
			. '</span>',
			$list_icon,
			esc_html__( 'List', 'cms-tree-page-view' ),
			esc_url( $tree_url ),
			$tree_icon,
			esc_html__( 'Tree', 'cms-tree-page-view' )
		);
		?>
		<script>
		( function() {
			var heading = document.querySelector( '.wp-heading-inline' );
			if ( ! heading || ! heading.parentNode ) {
				return;
			}
			var addButton = heading.parentNode.querySelector( '.page-title-action' );
			var host = document.createElement( 'span' );
			host.innerHTML = <?php echo wp_json_encode( $markup ); ?>;
			var toggle = host.firstElementChild;
			var anchor = addButton || heading;
			anchor.parentNode.insertBefore( toggle, anchor.nextSibling );
		} )();
		</script>
		<?php
	}

	/**
	 * Resolve the admin-menu parent slug to attach a post type's "Tree View" submenu to,
	 * honoring the post type's show_in_menu setting.
	 *
	 * Returns the parent slug, or '' when the post type has no admin menu to attach to
	 * (in which case the caller should skip it rather than register an orphan submenu
	 * that disappears or clobbers another plugin's menu item — todo 21).
	 *
	 * @param \WP_Post_Type $post_type_object The post type object to resolve the menu parent for.
	 * @return string
	 */
	public static function get_post_type_menu_parent( $post_type_object ) {

		// No admin UI at all → nowhere to attach.
		if ( empty( $post_type_object->show_ui ) ) {
			return '';
		}

		$show_in_menu = $post_type_object->show_in_menu;

		// Explicitly hidden from the menu (show_in_menu = false) → nothing to attach to.
		if ( false === $show_in_menu ) {
			return '';
		}

		// Relocated under a custom top-level menu (show_in_menu = 'some-slug').
		if ( is_string( $show_in_menu ) && '' !== $show_in_menu ) {
			return $show_in_menu;
		}

		// Default location (show_in_menu = true): the post type's own top-level menu.
		if ( 'attachment' === $post_type_object->name ) {
			return 'upload.php';
		}
		if ( 'post' === $post_type_object->name ) {
			return 'edit.php';
		}
		return 'edit.php?post_type=' . $post_type_object->name;
	}

	/**
	 * Whether this post type has a Tree View page registered at all.
	 *
	 * Single source of truth for "is cms-tpv-page-{$post_type} a page WordPress
	 * knows about" — add_admin_menu() registers exactly this set, and
	 * get_tree_view_url() refuses to hand out a URL outside it. Keeping the two
	 * on one predicate is what stops the plugin linking to a page core never
	 * registered, which wp-admin/admin.php answers with "Sorry, you are not
	 * allowed to access this page." for every role, administrators included
	 * (todo 57).
	 *
	 * Only the "In menu" setting registers the page. Ticking a post type "On
	 * dashboard" asks for the widget, not for a screen — so its rows link to each
	 * page's own editor instead of deep-linking into a tree that isn't there.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function has_tree_view_page( $post_type ) {

		$options = \CMS_Tree_Page_View\Settings\Options::get();

		if ( ! in_array( $post_type, $options['menu'], true ) ) {
			return false;
		}

		if ( \CMS_Tree_Page_View\Settings\Options::is_post_type_ignored( $post_type ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( empty( $post_type_object ) ) {
			return false;
		}

		// No admin menu to attach to (show_ui/show_in_menu false) means there is
		// nowhere to register the page, so it has none.
		return '' !== self::get_post_type_menu_parent( $post_type_object );
	}

	/**
	 * Every post type that gets a Tree View page, in settings order.
	 *
	 * @return string[] Post type slugs.
	 */
	public static function get_tree_view_post_types() {

		$options = \CMS_Tree_Page_View\Settings\Options::get();

		return array_values( array_filter( $options['menu'], array( __CLASS__, 'has_tree_view_page' ) ) );
	}

	/**
	 * Build the URL of a post type's Tree View submenu page, mirroring how
	 * WordPress core itself builds submenu URLs (wp-admin/menu-header.php) for
	 * the parent cms_tpv_get_post_type_menu_parent() returns.
	 *
	 * Two cases, both required — getting either wrong 404s on capability with
	 * "Sorry, you are not allowed to access this page." instead of the tree:
	 * - Parent is a real wp-admin file (edit.php, upload.php, or a relocated
	 *   CPT's custom parent that happens to be a core file, e.g.
	 *   options-general.php): URL is that file + `page=` + the query string it
	 *   already carries (e.g. `edit.php?post_type=page&page=cms-tpv-page-page`
	 *   — the `post_type` part is NOT optional; core needs it to resolve which
	 *   registered submenu the request is for).
	 * - Parent is a plugin-registered custom top-level slug (not a real file):
	 *   core always routes submenus under those through `admin.php?page=`,
	 *   never `<parent-slug>?page=`.
	 *
	 * @param string $post_type Post type slug.
	 * @return string Admin URL, or '' when the post type has no Tree View menu.
	 */
	public static function get_tree_view_url( $post_type ) {

		// A URL to a page add_admin_menu() never registered is worse than no URL
		// at all: WordPress answers it with "Sorry, you are not allowed to access
		// this page." Callers get '' instead and are expected to degrade.
		if ( ! self::has_tree_view_page( $post_type ) ) {
			return '';
		}

		$post_type_object = get_post_type_object( $post_type );

		$parent_slug = self::get_post_type_menu_parent( $post_type_object );

		$file_part = explode( '?', $parent_slug, 2 )[0];
		if ( ! file_exists( ABSPATH . 'wp-admin/' . $file_part ) ) {
			return admin_url( 'admin.php?page=cms-tpv-page-' . $post_type );
		}

		$separator = ( false === strpos( $parent_slug, '?' ) ) ? '?' : '&';
		return admin_url( $parent_slug . $separator . 'page=cms-tpv-page-' . $post_type );
	}

	/**
	 * Admin URL of the plugin's settings screen (Settings → CMS Tree Page View).
	 *
	 * Single source for the settings-page URL; the slug itself is registered in
	 * add_admin_menu() via add_submenu_page( 'options-general.php', … ).
	 *
	 * @return string Admin URL.
	 */
	public static function get_settings_url() {
		return admin_url( 'options-general.php?page=cms-tpv-options' );
	}

	/**
	 * Add items to the wp admin menu.
	 */
	public static function admin_menu() {

		// has_tree_view_page() has already dropped ignored post types, unregistered
		// ones, and ones with no admin menu to attach to (show_in_menu=false: skip
		// rather than orphan the submenu / clobber another plugin's item — todo 21).
		foreach ( self::get_tree_view_post_types() as $one_post_type ) {

			$post_type_object = get_post_type_object( $one_post_type );

			// Attach the Tree View under the post type's ACTUAL admin menu location
			// (honoring show_in_menu), not a hardcoded edit.php?post_type=… parent.
			$slug = self::get_post_type_menu_parent( $post_type_object );

			$menu_name = _x( 'Tree View', 'name in menu', 'cms-tree-page-view' );
			/* translators: %1$s: the post type's plural name (e.g. "Pages"). */
			$page_title = sprintf( _x( '%1$s Tree View', 'title on page with tree', 'cms-tree-page-view' ), $post_type_object->labels->name );

			add_submenu_page( $slug, $page_title, $menu_name, $post_type_object->cap->edit_posts, "cms-tpv-page-$one_post_type", 'cms_tpv_pages_page' );
		}

		$page_title = apply_filters( 'cms_tree_page_view_options_page_title', CMS_TPV_NAME );
		$menu_title = apply_filters( 'cms_tree_page_view_options_menu_title', CMS_TPV_NAME );
		add_submenu_page( 'options-general.php', $page_title, $menu_title, 'manage_options', 'cms-tpv-options', 'cms_tpv_options' );
	}

	/**
	 * Get the post type whose tree the current admin screen should show.
	 *
	 * @return string The selected post type slug, defaulting to 'post'.
	 */
	public static function get_selected_post_type() {
		// Fix for Ozh' Admin Drop Down Menu that does something with the urls.
		// The edit.php?post_type=movies&page=cms-tpv-page-xmovies form works, but
		// the admin.php?page=cms-tpv-page-movies form does not.
		$post_type = null;
		// Read-only screen routing from the admin URL (which post type's tree to show);
		// nothing is mutated here, so there is no nonce to verify. Values are unslashed
		// and sanitized before use.
		if ( isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $post_type ) {
			// No post type, happens with ozh admin drop down, so get it via page instead.
			$page      = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = str_replace( 'cms-tpv-page-', '', $page );
		}

		if ( ! $post_type ) {
			$post_type = 'post';
		}
		return $post_type;
	}

	/**
	 * Pages page
	 * A page with the tree. Good stuff.
	 */
	public static function pages_page() {

		$post_type        = self::get_selected_post_type();
		$post_type_object = get_post_type_object( $post_type );

		// Bail on an unregistered post type (e.g. a crafted ?post_type= value).
		if ( empty( $post_type_object ) ) {
			wp_die( esc_html__( 'No posts found.', 'cms-tree-page-view' ) );
		}

		// This page is only ever registered (add_submenu_page(), cms_tpv_admin_menu())
		// under a "cms-tpv-page-{$post_type}" slug, which is exactly what
		// Tree_View_Screen::is_react_screen() detects — so it always renders the React
		// app; the pre-React <h2> + cms_tpv_print_common_tree_stuff() fallback is gone.
		?>
		<div class="wrap">
			<?php \CMS_Tree_Page_View\Tree_View_Screen::render_root(); ?>
		</div>
		<?php
	}
}
