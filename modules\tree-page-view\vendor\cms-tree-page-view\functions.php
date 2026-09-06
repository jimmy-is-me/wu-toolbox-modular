<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Example: filter per-post permissions.
 *
 * The three filters cms_tree_page_view_post_can_edit,
 * cms_tree_page_view_post_user_can_add_inside and
 * cms_tree_page_view_post_user_can_add_after each receive the current
 * boolean permission and a post ID and may return false to deny edit,
 * add-inside or add-after for that specific post.
 */

/**
 * Example: add extra actions to a page's row actions and detail card.
 *
 * Signature: apply_filters( 'cms_tree_page_view_post_edit_links', array $links, int $page_id, WP_Post $post )
 *
 * Lets an integration (e.g. a page builder) contribute extra "Edit in X"
 * entries to a page's ROW ACTIONS — the Edit / View / history icons that
 * appear after the title when a tree or dashboard-widget row is hovered or
 * focused — and to the detail card shown for a single selected page.
 * $links is the list built so far; return it with your own entries
 * appended. Each entry is:
 *
 *     array(
 *         'id'       => string, // required
 *         'label'    => string, // required
 *         'url'      => string, // required
 *         'icon'     => string, // optional
 *         'position' => string, // optional: 'card' (default), 'row' or 'both'
 *     )
 *
 * id, label and url are required — an entry missing any one of them is
 * dropped. `id` is run through sanitize_key() (lowercased and stripped to
 * [a-z0-9_-]) before use: it doubles as a React key and a CSS class
 * suffix, so an id that sanitizes down to an empty string also drops the
 * entry. `label` should already be translated by the integration — it is
 * used as-is. `url` must be an absolute URL (e.g. built with admin_url())
 * before it goes through esc_url_raw(): a schemeless relative URL such as
 * 'my-builder/edit?id=7' gets 'http://' silently prepended by
 * esc_url_raw(), producing a broken external link (a relative
 * 'post.php?...' style URL is fine — esc_url_raw() allows the bare
 * '^[a-z0-9-]+\.php' form).
 *
 * `icon` is the NAME of an icon the plugin ships, resolved client-side,
 * never markup: an SVG string from a filter would have to be kses'd
 * against an allowlist on every row, and an image URL could not inherit
 * currentColor. A single character is kept as-is and drawn as a
 * lettermark, which is the escape hatch for a builder with no shipped
 * icon — pass its initial.
 *
 * `position` decides where the entry appears: 'card' (the default, and
 * what an unknown value falls back to) is the detail card only, 'row' is
 * the row actions only, 'both' is both. Defaulting to 'card' is what keeps
 * an integration written before row actions existed — the bundled
 * Elementor one, for example — from suddenly drawing an icon on every row
 * of every level without anyone asking for it. Row entries are built for
 * every visible node of a level, so keep an opted-in callback cheap; the
 * post caches are primed first, so get_post_meta() is a cache hit, but the
 * integration's own per-row work is not.
 *
 * Duplicate ids collapse to the first one registered (compared after
 * sanitize_key()), so filter priority is both the display order and the
 * override mechanism: a lower priority number wins. The bundled Elementor
 * integration claims the id 'elementor' at priority 10 — hook at a lower
 * priority to take that slot instead.
 *
 * Both surfaces only run the filter for a user who can edit the post.
 */

/**
 * Check if a post type is ignored.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Settings\Options::is_post_type_ignored().
 *
 * @param string $post_type Post type slug to check.
 * @return bool True if the post type is ignored.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Settings\Options::is_post_type_ignored() instead.
 */
function cms_tpv_post_type_is_ignored( $post_type ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Settings\Options::is_post_type_ignored()' );
	return \CMS_Tree_Page_View\Settings\Options::is_post_type_ignored( $post_type );
}

/**
 * Returns a list of ignored post types.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Settings\Options::ignored_post_types().
 *
 * @return string[] Ignored post type slugs.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Settings\Options::ignored_post_types() instead.
 */
function cms_tpv_get_ignored_post_types() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Settings\Options::ignored_post_types()' );
	return \CMS_Tree_Page_View\Settings\Options::ignored_post_types();
}

/**
 * Fix problem with WordPress insert_post_data. In update case
 * WordPress use current timestamp and logged in user. In this case
 * keep these originals from post to be updated:
 *
 * Namely post_author, post_modified and post_modified_gmt.
 *
 * Heikki Paananen, Kehitysvammaliitto ry (2015-01-09)
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::insert_post_data().
 *
 * @param array $data    Sanitized post data to be inserted.
 * @param array $postarr Raw array of post data, including the post ID on update.
 * @return array Filtered post data.
 */
function cms_tpv_insert_post_data( $data, $postarr ) {
	return \CMS_Tree_Page_View\Data\Legacy_Query::insert_post_data( $data, $postarr );
}

/**
 * Resolve a safe post status for a newly added page from a caller-supplied value.
 *
 * Security hardening for the AJAX add-page handler (todo 30, finding 2):
 *  - normalizes the UI's "published" alias to "publish",
 *  - whitelists the status to the values the add-page UI offers (draft, pending,
 *    publish) so an arbitrary caller-supplied status can't be passed through to
 *    wp_insert_post(), and
 *  - enforces the *post type's own* publish capability (not the hardcoded core
 *    "publish_posts"), downgrading "publish" to "pending" when the current user
 *    may not publish that post type.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::resolve_new_post_status().
 *
 * @param string       $requested_status Raw status from the request (e.g. "draft", "published").
 * @param WP_Post_Type $post_type_object The post type the page is being added to.
 * @return string One of "draft", "pending" or "publish".
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::resolve_new_post_status() instead.
 */
function cms_tpv_resolve_new_post_status( $requested_status, $post_type_object ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::resolve_new_post_status()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::resolve_new_post_status( $requested_status, $post_type_object );
}


/**
 * Hide the admin bar inside the Tree View preview iframe.
 *
 * Back-compat shim (the show_admin_bar filter callback): delegates to
 * CMS_Tree_Page_View\Admin\Menu::hide_admin_bar_in_preview().
 *
 * @param bool $show Whether to show the admin bar.
 * @return bool
 */
function cms_tpv_hide_admin_bar_in_preview( $show ) {
	return \CMS_Tree_Page_View\Admin\Menu::hide_admin_bar_in_preview( $show );
}
add_filter( 'show_admin_bar', 'cms_tpv_hide_admin_bar_in_preview' );

/**
 * Detect if we are on a page that use CMS Tree Page View
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Menu::is_one_of_our_pages().
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Menu::is_one_of_our_pages() instead.
 */
function cms_tpv_is_one_of_our_pages() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Menu::is_one_of_our_pages()' );
	return \CMS_Tree_Page_View\Admin\Menu::is_one_of_our_pages();
}

/**
 * Add styles and scripts to pages that use the plugin
 *
 * Back-compat shim (and the admin_enqueue_scripts hook callback in index.php):
 * delegates to CMS_Tree_Page_View\Admin\Menu::admin_enqueue_scripts().
 */
function cms_tpv_admin_enqueue_scripts() {
	\CMS_Tree_Page_View\Admin\Menu::admin_enqueue_scripts();
}

/**
 * Run on admin_init: wire up the plugin row meta link and the promo box.
 *
 * Back-compat shim (and the admin_init hook callback in index.php): delegates to
 * CMS_Tree_Page_View\Admin\Menu::admin_init().
 */
function cms_tpv_admin_init() {
	\CMS_Tree_Page_View\Admin\Menu::admin_init();
}

/**
 * Formerly rendered the dismissible promo box shown above the tree wrapper.
 *
 * The promo box was removed in favour of the permanent "About this plugin"
 * card (Admin\Promo::help_card(), shown on the settings screen, and its React
 * twin AboutPluginCard in the Tree View's detail sidebar) — this shim is now a
 * deprecated no-op kept only for third-party callers.
 *
 * @deprecated 1.8.0 No replacement; the promo box was removed.
 */
function cms_tpv_promo_above_wrapper() {
	_deprecated_function( __FUNCTION__, '1.8.0' );
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
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Menu::add_tree_view_link_to_list_screen().
 */
function cms_tpv_add_tree_view_link_to_list_screen() {
	\CMS_Tree_Page_View\Admin\Menu::add_tree_view_link_to_list_screen();
}

/**
 * Print inline JS that inserts the "List | Tree" view switch into the
 * classic list screen's title row, right after the "Add New" button.
 *
 * Mirrors the React Tree View screen's <ListTreeToggle> markup/classes
 * exactly (.cms-tpv-view-switch / __option / is-active) so it looks and
 * behaves identically on both screens, just with List/Tree swapped: here
 * List is the current, non-interactive option and Tree links out.
 *
 * Back-compat shim (the admin_print_footer_scripts callback): delegates to
 * CMS_Tree_Page_View\Admin\Menu::print_classic_view_switch_script().
 */
function cms_tpv_print_classic_view_switch_script() {
	\CMS_Tree_Page_View\Admin\Menu::print_classic_view_switch_script();
}


/**
 * Formerly added a Settings link to the plugin's row meta (grey second row) on
 * the Plugins screen.
 *
 * The link moved to the plugin's action links (first row, before Deactivate —
 * the WP convention) via CMS_Tree_Page_View\Admin\Menu::set_plugin_action_links()
 * — this shim is now a deprecated no-op kept only for third-party callers.
 *
 * @param array $links Array of plugin row meta links.
 * @return array Unmodified array of plugin row meta links.
 *
 * @deprecated 1.8.0 No replacement; the Settings link moved to plugin action links.
 */
function cms_tpv_set_plugin_row_meta( $links ) {
	_deprecated_function( __FUNCTION__, '1.8.0' );
	return $links;
}


/**
 * Save settings, called on admin_init when saving the plugin's settings screen.
 *
 * Back-compat shim (and the admin_init hook callback in index.php): delegates to
 * CMS_Tree_Page_View\Settings\Options::save().
 */
function cms_tpv_save_settings() {
	\CMS_Tree_Page_View\Settings\Options::save();
}

/**
 * Resolve the admin-menu parent slug to attach a post type's "Tree View" submenu to,
 * honoring the post type's show_in_menu setting.
 *
 * Returns the parent slug, or '' when the post type has no admin menu to attach to
 * (in which case the caller should skip it rather than register an orphan submenu
 * that disappears or clobbers another plugin's menu item — todo 21).
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Menu::get_post_type_menu_parent().
 *
 * @param WP_Post_Type $post_type_object The post type object to resolve the menu parent for.
 * @return string
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Menu::get_post_type_menu_parent() instead.
 */
function cms_tpv_get_post_type_menu_parent( $post_type_object ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Menu::get_post_type_menu_parent()' );
	return \CMS_Tree_Page_View\Admin\Menu::get_post_type_menu_parent( $post_type_object );
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
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Menu::get_tree_view_url().
 *
 * @param string $post_type Post type slug.
 * @return string Admin URL, or '' when the post type has no Tree View menu.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Menu::get_tree_view_url() instead.
 */
function cms_tpv_get_tree_view_url( $post_type ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Menu::get_tree_view_url()' );
	return \CMS_Tree_Page_View\Admin\Menu::get_tree_view_url( $post_type );
}

/**
 * Add items to the wp admin menu.
 *
 * Back-compat shim (the admin_menu hook callback in index.php): delegates to
 * CMS_Tree_Page_View\Admin\Menu::admin_menu().
 */
function cms_tpv_admin_menu() {
	\CMS_Tree_Page_View\Admin\Menu::admin_menu();
}

/**
 * Output the plugin's settings page.
 *
 * Back-compat shim (and the add_submenu_page callback): delegates to
 * CMS_Tree_Page_View\Settings\Options::render_settings_page().
 */
function cms_tpv_options() {
	\CMS_Tree_Page_View\Settings\Options::render_settings_page();
}

/**
 * Load settings.
 *
 * Back-compat shim: delegates to CMS_Tree_Page_View\Settings\Options::get().
 *
 * @return array<string, mixed> Options with guaranteed 'dashboard' and 'menu' arrays.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Settings\Options::get() instead.
 */
function cms_tpv_get_options() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Settings\Options::get()' );
	return \CMS_Tree_Page_View\Settings\Options::get();
}

/**
 * Get the post type whose tree the current admin screen should show.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Menu::get_selected_post_type().
 *
 * @return string The selected post type slug, defaulting to 'post'.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Menu::get_selected_post_type() instead.
 */
function cms_tpv_get_selected_post_type() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Menu::get_selected_post_type()' );
	return \CMS_Tree_Page_View\Admin\Menu::get_selected_post_type();
}

/**
 * Determine if a post type is considered hierarchical
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical().
 *
 * @param WP_Post_Type $post_type_object The post type object to inspect.
 * @return bool True if the post type is treated as hierarchical.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical() instead.
 */
function cms_tpv_is_post_type_hierarchical( $post_type_object ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical( $post_type_object );
}


/**
 * Pages page
 * A page with the tree. Good stuff.
 *
 * Back-compat shim (the add_submenu_page callback): delegates to
 * CMS_Tree_Page_View\Admin\Menu::pages_page().
 */
function cms_tpv_pages_page() {
	\CMS_Tree_Page_View\Admin\Menu::pages_page();
}

/**
 * The `orderby` this plugin's tree actually displays siblings in — single
 * source of truth so every place that queries/walks siblings by this order
 * (cms_tpv_get_pages() below, and Tree_Mutations::add_pages()'s "make room"
 * loop) stays in lock-step. A caller that queried by a different order while
 * shifting menu_order values to make room could decide a sibling had
 * "passed" a reference page when visually (per THIS order) it hadn't,
 * bumping the wrong posts.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::sibling_orderby().
 *
 * @phpstan-return 'menu_order title'
 * @return string
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::sibling_orderby() instead.
 */
function cms_tpv_sibling_orderby() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::sibling_orderby()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::sibling_orderby();
}

/**
 * Get the pages
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::get_pages().
 *
 * @param array|null $args Query args (post_type, parent, view); merged over defaults.
 * @return array Array of page objects (each with an ID property).
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::get_pages() instead.
 */
function cms_tpv_get_pages( $args = null ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::get_pages()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::get_pages( $args );
}

/**
 * Performance helper: return a map of [parent_id => child_count] for all the
 * given parent IDs in a single query.
 *
 * Without this, cms_tpv_print_childs() would call cms_tpv_get_pages() once per
 * rendered node just to test "does this node have children?" — an N+1 query
 * pattern that dominates load time on large trees. Mirrors the post_status
 * semantics of cms_tpv_get_pages() ("all" => the WP_Query "any" set, otherwise
 * just "publish").
 *
 * Note: unlike cms_tpv_get_pages() this does not run the `get_pages` filter, so
 * the count may differ slightly if another plugin filters that list. childCount is
 * only used for the "(N)" label / expand arrow, so a cosmetic discrepancy there
 * is an acceptable trade for collapsing N queries into one.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::get_child_counts().
 *
 * @param int[]  $parent_ids Parent post IDs to count children for.
 * @param string $view       View filter ('all', 'all_with_trash', or a published-only view).
 * @param string $post_type  Post type slug of the children being counted.
 * @return array Map of [parent_id => child_count].
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::get_child_counts() instead.
 */
function cms_tpv_get_child_counts( $parent_ids, $view, $post_type ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::get_child_counts()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::get_child_counts( $parent_ids, $view, $post_type );
}

/**
 * Add $delta to the menu_order of every post matching a WHERE clause, and invalidate
 * the object cache of each post the shift touched.
 *
 * Reorder shifts sibling menu_order values with a raw SQL UPDATE for speed. Raw $wpdb
 * writes change the database but bypass WordPress' object cache, so on a host with a
 * persistent object cache the shifted siblings keep their old menu_order in cache. The
 * next request's get_post() then returns the stale value and the reorder arithmetic
 * ($ref->menu_order + 1) is built on outdated numbers — several siblings collapse onto
 * the same menu_order. This only reproduces with a persistent object cache, which is
 * why a clean install never shows it (upstream issue #12). Cleaning each affected
 * post's cache after the write keeps the cached and stored menu_order in sync.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Data\Legacy_Query::bump_menu_order().
 *
 * @param int    $delta      Amount to add to menu_order (negative shifts down).
 * @param string $where      WHERE clause with placeholders. Caller-controlled literal
 *                           (never user input) — the values go through $where_args.
 * @param array  $where_args Values for the WHERE placeholders.
 * @return int[] IDs of the posts that were shifted.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Data\Legacy_Query::bump_menu_order() instead.
 */
function cms_tpv_bump_menu_order( $delta, $where, $where_args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Data\Legacy_Query::bump_menu_order()' );
	return \CMS_Tree_Page_View\Data\Legacy_Query::bump_menu_order( $delta, $where, $where_args );
}


/**
 * Formerly showed a box with some donate links and thanks.
 *
 * The box was removed in favour of the permanent "About this plugin" card
 * (Admin\Promo::help_card(), shown on the settings screen, and its React twin
 * AboutPluginCard in the Tree View's detail sidebar) — this shim is now a
 * deprecated no-op kept only for third-party callers.
 *
 * @deprecated 1.8.0 No replacement; the promo box was removed.
 */
function cms_tpv_show_annoying_box() {
	_deprecated_function( __FUNCTION__, '1.8.0' );
}


/**
 * Activation hook callback.
 *
 * Back-compat shim (registered via register_activation_hook in index.php):
 * delegates to CMS_Tree_Page_View\Lifecycle\Installer::install().
 */
function cms_tpv_install() {
	\CMS_Tree_Page_View\Lifecycle\Installer::install();
}

/**
 * On admin_init, show the post-activation welcome notice once.
 *
 * Back-compat shim (and the hook callback registered in index.php): delegates to
 * CMS_Tree_Page_View\Admin\Welcome_Notice::maybe_show().
 */
function cms_tpv_maybe_show_welcome_notice() {
	\CMS_Tree_Page_View\Admin\Welcome_Notice::maybe_show();
}

/**
 * Render the dismissible welcome notice pointing at the tree view page.
 *
 * Back-compat shim (and the admin_notices callback name external code may
 * remove_action): delegates to
 * CMS_Tree_Page_View\Admin\Welcome_Notice::render().
 */
function cms_tpv_render_welcome_notice() {
	\CMS_Tree_Page_View\Admin\Welcome_Notice::render();
}

/**
 * Grant the tree-move capability to the administrator and editor roles.
 *
 * Back-compat shim: delegates to CMS_Tree_Page_View\Admin\Capabilities::setup().
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Capabilities::setup() instead.
 */
function cms_tvp_setup_caps() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Capabilities::setup()' );
	\CMS_Tree_Page_View\Admin\Capabilities::setup();
}

/**
 * Uninstall hook callback.
 *
 * Back-compat shim (registered via register_uninstall_hook in index.php):
 * delegates to CMS_Tree_Page_View\Lifecycle\Installer::uninstall().
 */
function cms_tpv_uninstall() {
	\CMS_Tree_Page_View\Lifecycle\Installer::uninstall();
}

/**
 * Adds an array of capabilities to a role.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Capabilities::add_caps_to_role().
 *
 * @param string   $role Role name to add the capabilities to.
 * @param string[] $caps Capabilities to add.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Capabilities::add_caps_to_role() instead.
 */
function cms_tpv_add_caps_to_role( $role, $caps ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Capabilities::add_caps_to_role()' );
	\CMS_Tree_Page_View\Admin\Capabilities::add_caps_to_role( $role, $caps );
}

/**
 * Remove an array of capabilities from a role.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Admin\Capabilities::remove_caps_from_role().
 *
 * @param string   $role Role name to remove the capabilities from.
 * @param string[] $caps Capabilities to remove.
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Admin\Capabilities::remove_caps_from_role() instead.
 */
function cms_tpv_remove_caps_from_role( $role, $caps ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Admin\Capabilities::remove_caps_from_role()' );
	\CMS_Tree_Page_View\Admin\Capabilities::remove_caps_from_role( $role, $caps );
}

/**
 * Setup some defaults.
 *
 * Back-compat shim: delegates to
 * CMS_Tree_Page_View\Settings\Options::setup_defaults().
 *
 * @deprecated 1.8.0 Use CMS_Tree_Page_View\Settings\Options::setup_defaults() instead.
 */
function cms_tpv_setup_defaults() {
	_deprecated_function( __FUNCTION__, '1.8.0', 'CMS_Tree_Page_View\Settings\Options::setup_defaults()' );
	\CMS_Tree_Page_View\Settings\Options::setup_defaults();
}

/**
 * On init, detect a plugin version change and run the upgrade routine.
 *
 * Back-compat shim (the init hook callback in index.php): delegates to
 * CMS_Tree_Page_View\Lifecycle\Installer::plugins_loaded().
 */
function cms_tpv_plugins_loaded() {
	\CMS_Tree_Page_View\Lifecycle\Installer::plugins_loaded();
}
