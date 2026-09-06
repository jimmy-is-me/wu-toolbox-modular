<?php
/**
 * Legacy procedural tree data/query helpers.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The tree data/query helpers the modern inc/ Data/REST layer and the PHPUnit
 * suite lean on most: fetching pages, counting children, shifting menu_order,
 * resolving a new post's status, and the insert_post_data / hierarchical-type
 * helpers. Consumed across the plugin via the cms_tpv_* shims.
 */
class Legacy_Query {

	/**
	 * Fix problem with WordPress insert_post_data. In update case
	 * WordPress use current timestamp and logged in user. In this case
	 * keep these originals from post to be updated:
	 *
	 * Namely post_author, post_modified and post_modified_gmt.
	 *
	 * Heikki Paananen, Kehitysvammaliitto ry (2015-01-09)
	 *
	 * @param array $data    Sanitized post data to be inserted.
	 * @param array $postarr Raw array of post data, including the post ID on update.
	 * @return array Filtered post data.
	 */
	public static function insert_post_data( $data, $postarr ) {

		if ( ! empty( $postarr['ID'] ) ) {
			$data['post_author']       = $postarr['post_author'];
			$data['post_modified']     = $postarr['post_modified'];
			$data['post_modified_gmt'] = $postarr['post_modified_gmt'];
		}

		return $data;
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
	 * @param string        $requested_status Raw status from the request (e.g. "draft", "published").
	 * @param \WP_Post_Type $post_type_object The post type the page is being added to.
	 * @return string One of "draft", "pending" or "publish".
	 */
	public static function resolve_new_post_status( $requested_status, $post_type_object ) {

		// The UI sends "published" for the publish option; normalize it.
		if ( 'published' === $requested_status ) {
			$requested_status = 'publish';
		}

		// Only allow the statuses the add-page UI actually offers.
		$allowed_statuses = array( 'draft', 'pending', 'publish' );
		if ( ! in_array( $requested_status, $allowed_statuses, true ) ) {
			$requested_status = 'draft';
		}

		// Enforce the post type's own publish capability.
		if ( 'publish' === $requested_status && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			$requested_status = 'pending';
		}

		return $requested_status;
	}

	/**
	 * Whether a post type may hold parent/child relationships — i.e. whether the
	 * tree should offer "make child" drops and "add inside" on its nodes.
	 *
	 * Simply what the post type itself declares. Until todo 71 this fake-treated
	 * `post` as hierarchical, inherited from the pre-takeover plugin: dropping a
	 * post onto another post wrote a post_parent that WordPress offers no UI for
	 * (`post` doesn't even support page-attributes) and reads nowhere — not in
	 * permalinks, the template hierarchy, or the Posts list table. The nesting
	 * existed only inside this plugin's tree, where it also took the dragged post
	 * off the top level, so a stray mid-row drop could make a post look lost.
	 *
	 * This governs what may be WRITTEN, not what is DISPLAYED: the tree is built
	 * from post_parent regardless (see get_pages()), so posts that were nested
	 * while the old rule applied still render as children and can be dragged back
	 * out.
	 *
	 * @param \WP_Post_Type $post_type_object The post type object to inspect.
	 * @return bool True if the post type is treated as hierarchical.
	 */
	public static function is_post_type_hierarchical( $post_type_object ) {
		return (bool) $post_type_object->hierarchical;
	}

	/**
	 * The `orderby` this plugin's tree actually displays siblings in — single
	 * source of truth so every place that queries/walks siblings by this order
	 * (self::get_pages() below, and Tree_Mutations::add_pages()'s "make room"
	 * loop) stays in lock-step. A caller that queried by a different order while
	 * shifting menu_order values to make room could decide a sibling had
	 * "passed" a reference page when visually (per THIS order) it hadn't,
	 * bumping the wrong posts.
	 *
	 * @phpstan-return 'menu_order title'
	 * @return string
	 */
	public static function sibling_orderby() {
		return 'menu_order title';
	}

	/**
	 * Get the pages
	 *
	 * @param array|null $args Query args (post_type, parent, view); merged over defaults.
	 * @return array Array of page objects (each with an ID property).
	 */
	public static function get_pages( $args = null ) {

		global $wpdb;

		$defaults = array(
			'post_type' => 'post',
			'parent'    => '',
			'view'      => 'all', // all | public | trash.
		);
		$r        = wp_parse_args( $args, $defaults );

		$get_posts_args = array(
			'fields'              => 'ids',
			'numberposts'         => -1,
			'orderby'             => self::sibling_orderby(),
			'order'               => 'ASC',
			'ignore_sticky_posts' => 1,
			'post_type'           => $r['post_type'],
		);
		if ( $r['parent'] ) {
			$get_posts_args['post_parent'] = $r['parent'];
		} else {
			$get_posts_args['post_parent'] = '0';
		}
		if ( $r['view'] === 'all' ) {
			$get_posts_args['post_status'] = 'any'; // "any" seems to get all but auto-drafts
		} elseif ( $r['view'] === 'all_with_trash' ) {

			// Like "all" (full hierarchy, honouring $parent) but also including trashed
			// pages, so the Trash status filter can render trashed matches under their
			// (non-trash) ancestors as context. "any" deliberately excludes trash, so
			// list the not-excluded-from-search statuses explicitly and append trash.
			$get_posts_args['post_status'] = array_merge(
				array_values( get_post_stati( array( 'exclude_from_search' => false ) ) ),
				array( 'trash' )
			);

		} elseif ( $r['view'] === 'trash' ) {

			$get_posts_args['post_status'] = 'trash';

			// if getting trash, just get all pages, don't care about parent?
			// because otherwise we have to mix trashed pages and pages with other statuses. messy.
			$get_posts_args['post_parent'] = null;

		} elseif ( $r['view'] === 'draft' ) {

			// Status filter: show all drafts flat (a draft's parent may be published
			// and absent from this view, so the hierarchy can't be preserved).
			$get_posts_args['post_status'] = 'draft';
			$get_posts_args['post_parent'] = null;

		} elseif ( $r['view'] === 'pending' ) {

			$get_posts_args['post_status'] = 'pending';
			$get_posts_args['post_parent'] = null;

		} elseif ( $r['view'] === 'mine' ) {

			// "Mine" = the current user's posts of this type, any status, flat.
			$get_posts_args['post_status'] = 'any';
			$get_posts_args['post_parent'] = null;
			$get_posts_args['author']      = get_current_user_id();

		} else {
			$get_posts_args['post_status'] = 'publish';
		}

		// Does not work with plugin role scoper. Don't know why, but this should fix it.
		remove_action( 'get_pages', array( 'ScoperHardway', 'flt_get_pages' ), 1 );

		// Does not work with plugin ALO EasyMail Newsletter.
		remove_filter( 'get_pages', 'ALO_exclude_page' );

		$pages = get_posts( $get_posts_args );

		// Drop anything the current user may not see (todo 30 finding 1, reworked
		// for todo 59). This filters the result rather than the query because
		// get_posts() runs with suppress_filters => true, so Post_Visibility's
		// posts_where clause cannot reach it — and flipping that flag would newly
		// expose the tree to every third-party posts_* filter on the site. The
		// listing is unpaginated (numberposts => -1), so filtering here drops
		// exactly the hidden rows and never truncates the visible ones.
		$cms_tpv_pt_obj = get_post_type_object( $get_posts_args['post_type'] );

		if ( $cms_tpv_pt_obj && Post_Visibility::is_restricted( $cms_tpv_pt_obj ) ) {
			// One query to prime, so the per-post check below reads from cache
			// instead of firing a get_post() query per row.
			_prime_post_caches( $pages, false, false );

			$pages = array_values(
				array_filter(
					$pages,
					static function ( $page_id ) use ( $cms_tpv_pt_obj ) {
						$post = get_post( $page_id );

						return $post && Post_Visibility::can_see_post( $post, $cms_tpv_pt_obj );
					}
				)
			);
		}

		// Apply the standard WordPress get_pages filter so other plugins can adjust the list.
		// Note: get_pages filter uses orderby comma separated and with the key sort_column.
		$get_posts_args['sort_column'] = str_replace( ' ', ', ', $get_posts_args['orderby'] );

		// We only fetch ids above, but if we run the get_pages filter we need to send pages as object.

		$pages_as_objects = array();

		foreach ( $pages as $page_id ) {

			$one_page           = new \stdClass();
			$one_page->ID       = $page_id;
			$pages_as_objects[] = $one_page;

		}

		// Deliberately fire WordPress' own core 'get_pages' filter so other plugins can
		// adjust the list exactly as they would for a native get_pages() call.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$pages_as_objects = apply_filters( 'get_pages', $pages_as_objects, $get_posts_args );

		return $pages_as_objects;
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
	 * @param int[]  $parent_ids Parent post IDs to count children for.
	 * @param string $view       View filter ('all', 'all_with_trash', or a published-only view).
	 * @param string $post_type  Post type slug of the children being counted.
	 * @return array Map of [parent_id => child_count].
	 */
	public static function get_child_counts( $parent_ids, $view, $post_type ) {

		global $wpdb;

		$parent_ids = array_filter( array_map( 'intval', (array) $parent_ids ) );
		if ( empty( $parent_ids ) ) {
			return array();
		}

		if ( $view === 'all' ) {
			// "any" in WP_Query == all statuses not excluded from search.
			$statuses = array_values( get_post_stati( array( 'exclude_from_search' => false ) ) );
		} elseif ( $view === 'all_with_trash' ) {
			// The Trash filter's base tree: the "any" set plus trash, so a node whose
			// only children are trashed still gets an expand arrow to reach them.
			$statuses = array_merge(
				array_values( get_post_stati( array( 'exclude_from_search' => false ) ) ),
				array( 'trash' )
			);
		} else {
			$statuses = array( 'publish' );
		}

		$id_placeholders     = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// Match the listing's visibility rule exactly, so the expand arrow and the
		// "(N)" badge never promise children the user can't see (todo 30 finding 1,
		// reworked for todo 59).
		$visibility_sql    = '';
		$visibility_params = array();
		$cms_tpv_pt_obj    = get_post_type_object( $post_type );

		if ( $cms_tpv_pt_obj ) {
			list( $visibility_sql, $visibility_params ) = Post_Visibility::where_sql( $cms_tpv_pt_obj, $wpdb->posts );
		}

		// This direct aggregate query is safe and intentional (audited in todo 32): the
		// only interpolated parts are placeholder strings ($id_placeholders /
		// $status_placeholders are comma-joined %d/%s) and a literal author clause —
		// every value is bound through the prepare() args array. $wpdb->posts is a core
		// table name, and this per-request child-count roll-up is deliberately uncached.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = $wpdb->prepare(
			"SELECT post_parent, COUNT(*) AS child_count
			 FROM $wpdb->posts
			 WHERE post_parent IN ($id_placeholders)
			   AND post_type = %s
			   AND post_status IN ($status_placeholders)
			   $visibility_sql
			 GROUP BY post_parent",
			array_merge( $parent_ids, array( $post_type ), $statuses, $visibility_params )
		);

		$counts = array();
		foreach ( $wpdb->get_results( $sql ) as $row ) {
			$counts[ (int) $row->post_parent ] = (int) $row->child_count;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $counts;
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
	 * @param int    $delta      Amount to add to menu_order (negative shifts down).
	 * @param string $where      WHERE clause with placeholders. Caller-controlled literal
	 *                           (never user input) — the values go through $where_args.
	 * @param array  $where_args Values for the WHERE placeholders.
	 * @return int[] IDs of the posts that were shifted.
	 */
	public static function bump_menu_order( $delta, $where, $where_args ) {

		global $wpdb;

		// Resolve which posts the shift touches *before* changing menu_order — the WHERE
		// may filter on menu_order itself — so the right caches get invalidated.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE {$where}", $where_args ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET menu_order = menu_order + %d WHERE {$where}", array_merge( array( $delta ), $where_args ) ) );

		$ids = array_map( 'intval', $ids );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		return $ids;
	}
}
