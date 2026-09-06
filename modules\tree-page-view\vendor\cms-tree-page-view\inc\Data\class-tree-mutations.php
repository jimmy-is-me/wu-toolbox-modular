<?php
/**
 * Tree write operations backing both the legacy AJAX move handler and the
 * cms-tpv/v1 REST move endpoint.
 *
 * One source of truth for the drag-and-drop reorder math. Callers own the nonce
 * check, $_POST parsing, and the both-nodes permission checks; this class only
 * performs the mutation (and the trash guard that protects it).
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists drag-and-drop reorders by updating menu_order / post_parent / post_type.
 */
class Tree_Mutations {

	/**
	 * Move a node before, after, or inside a reference node.
	 *
	 * Renumbers the destination sibling level to sequential, gap-free menu_order
	 * values in the order the tree actually displays them (see cms_tpv_sibling_orderby()),
	 * with the moved node spliced into its new slot. This mirrors the client's own
	 * optimistic splice (src/tree/moveMath.js) exactly, so the order the user saw
	 * the instant they dropped is the order the server persists — no post-save
	 * reshuffle. It also permanently de-ties the level: the legacy arithmetic only
	 * nudged menu_order around the moved node, so a level whose siblings shared a
	 * menu_order (the common default — new pages are all created at 0) would get
	 * re-sorted by the title tie-break on every drop, sending pages "somewhere
	 * completely different" from where they were dropped. The moved node adopts the
	 * reference node's post_type (cross-tree drag) and joins its level: inside the
	 * ref, or alongside it (its parent) for before/after. Does not check nonces or
	 * capabilities — the caller must do that before calling this.
	 *
	 * Fires `cms_tree_page_view_node_move_finish( WP_Post $post_node, WP_Post
	 * $post_ref_node, string $type, array $post_to_save )` once on success —
	 * $post_node is the PRE-move snapshot; $post_to_save is the moved node's own
	 * update array (its new post_type/menu_order/post_parent), so a listener can
	 * read the new values without a re-query.
	 *
	 * @param int    $node_id     ID of the node that was moved.
	 * @param int    $ref_node_id ID of the reference node the move is relative to.
	 * @param string $type        One of "before", "after", "inside".
	 * @return array{moved: bool, message: string}
	 */
	public static function move( $node_id, $ref_node_id, $type ) {

		$node_id     = (int) $node_id;
		$ref_node_id = (int) $ref_node_id;

		if ( ! $node_id || ! $ref_node_id ) {
			return array(
				'moved'   => false,
				'message' => 'missing nodes',
			);
		}

		$post_node     = get_post( $node_id );
		$post_ref_node = get_post( $ref_node_id );

		if ( ! $post_node || ! $post_ref_node ) {
			return array(
				'moved'   => false,
				'message' => 'invalid nodes',
			);
		}

		// First check that post_node (moved post) is not in trash. We do not move them.
		if ( 'trash' === $post_node->post_status ) {
			return array(
				'moved'   => false,
				'message' => 'node in trash',
			);
		}

		if ( ! in_array( $type, array( 'inside', 'before', 'after' ), true ) ) {
			return array(
				'moved'   => false,
				'message' => 'unknown type',
			);
		}

		// The moved node adopts the reference node's post type (cross-tree drag)
		// and joins its level: inside the ref, or alongside it (its parent).
		$new_post_type = $post_ref_node->post_type;
		$new_parent    = 'inside' === $type ? (int) $post_ref_node->ID : (int) $post_ref_node->post_parent;

		// The destination level's current display order — the exact array the
		// client spliced its optimistic move into. For a same-parent reorder the
		// moved node is already here; for a cross-parent/cross-type move it isn't
		// (it's still under its old parent). splice_order() handles both.
		$sibling_ids = get_posts(
			array(
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => Legacy_Query::sibling_orderby(),
				'order'            => 'ASC',
				'post_type'        => $new_post_type,
				'post_status'      => 'any',
				'post_parent'      => $new_parent,
				'suppress_filters' => false,
			)
		);
		$sibling_ids = array_map( 'intval', $sibling_ids );

		$ordered = self::splice_order( $sibling_ids, $node_id, $ref_node_id, $type );

		// Persist the moved node first: its new parent/type/order. Use
		// wp_update_post (not a raw menu_order write) since parent/type actually
		// change and its modified date should update — it genuinely moved.
		$post_to_save = array(
			'ID'          => $post_node->ID,
			'menu_order'  => array_search( $node_id, $ordered, true ),
			'post_parent' => $new_parent,
			'post_type'   => $new_post_type,
		);
		wp_update_post( $post_to_save );

		// Renumber the other siblings to their new slots. Only the ones whose
		// menu_order actually changed are written, and via a direct column update
		// (not wp_update_post) so a pure reorder doesn't bump their modified dates
		// or fire a save_post storm — matching cms_tpv_bump_menu_order()'s own
		// cache-safe write. Prime the sibling caches once so the current-order
		// lookup below reads from cache (the id-only get_posts above doesn't fill
		// the post cache), then skip any sibling already sitting in its target slot
		// — a drag near the top of a large level leaves most rows untouched, so
		// this avoids an UPDATE + cache flush per sibling across the whole level.
		if ( ! empty( $sibling_ids ) ) {
			_prime_post_caches( $sibling_ids, false, false );
		}
		foreach ( $ordered as $order => $sibling_id ) {
			if ( $sibling_id === $node_id ) {
				continue;
			}
			$sibling_post = get_post( $sibling_id );
			if ( $sibling_post && (int) $sibling_post->menu_order === (int) $order ) {
				continue;
			}
			self::set_menu_order( $sibling_id, $order );
		}

		$result = array(
			'moved'   => true,
			'message' => 'inside' === $type ? 'did inside' : ( 'before' === $type ? 'did before' : 'did after' ),
		);

		// Notify other plugins (cache plugins, etc.) that a reorder finished. Fired
		// once, here, on a successful move so the legacy AJAX handler and the REST
		// endpoint behave identically — see cms_tpv_move_page(). $post_node is the
		// PRE-move snapshot (fetched above, before wp_update_post()); $post_to_save
		// is the moved node's update array, so a listener can read the new
		// post_type/menu_order/post_parent from it without re-querying.
		do_action( 'cms_tree_page_view_node_move_finish', $post_node, $post_ref_node, $type, $post_to_save );

		return $result;
	}

	/**
	 * Compute a destination level's new child order for a move, mirroring the
	 * client's optimistic splice in src/tree/moveMath.js byte-for-byte so the
	 * server persists exactly what the user saw on drop:
	 *
	 *  - 'inside' — the moved node becomes the FIRST child of the destination.
	 *  - 'before' — inserted immediately before the reference sibling.
	 *  - 'after'  — inserted immediately after the reference sibling.
	 *
	 * The moved id is removed first (a no-op for a cross-parent move where it
	 * isn't in this level yet), then reinserted. A before/after whose reference
	 * isn't in the level (shouldn't happen — the ref defines the level) falls
	 * back to appending at the end rather than dropping the node.
	 *
	 * @param int[]  $child_ids Destination parent's ordered child ids.
	 * @param int    $node_id   Moved node id.
	 * @param int    $ref_id    Reference sibling (before/after); ignored for inside.
	 * @param string $type      One of "before", "after", "inside".
	 * @return int[] The new ordered child ids (moved node included).
	 */
	private static function splice_order( array $child_ids, $node_id, $ref_id, $type ) {
		$rest = array_values(
			array_filter(
				$child_ids,
				static function ( $child_id ) use ( $node_id ) {
					return $child_id !== $node_id;
				}
			)
		);

		if ( 'inside' === $type ) {
			array_unshift( $rest, $node_id );
			return $rest;
		}

		$idx = array_search( $ref_id, $rest, true );
		if ( false === $idx ) {
			$rest[] = $node_id;
			return $rest;
		}

		$insert_at = 'before' === $type ? $idx : $idx + 1;
		array_splice( $rest, $insert_at, 0, array( $node_id ) );
		return $rest;
	}

	/**
	 * Set a post's menu_order via a direct column write + cache clean, without
	 * firing wp_update_post() (so a pure reorder leaves post_modified and the
	 * save_post hooks untouched). Mirrors cms_tpv_bump_menu_order()'s cache-safe
	 * write; used by move() to renumber a level's siblings.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $menu_order New menu_order.
	 * @return void
	 */
	private static function set_menu_order( $post_id, $menu_order ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'menu_order' => (int) $menu_order ), array( 'ID' => (int) $post_id ) );
		clean_post_cache( (int) $post_id );
	}

	/**
	 * Create one or more pages relative to a reference node.
	 *
	 * Performs the create work of the legacy cms_tpv_add_pages(): the blank-name
	 * filtering, the status resolution through cms_tpv_resolve_new_post_status()
	 * (so a caller cannot publish without the post type's publish cap), the
	 * menu_order "make room" loop, and the wp_insert_post() arguments. The new
	 * pages adopt the reference node's post_type. Callers own the nonce check,
	 * $_POST parsing, and the add-inside/add-after permission checks — this method
	 * only performs the create work.
	 *
	 * Fires `cms_tree_page_view_pages_added( int[] $created, string $post_type,
	 * int $ref_post_id, string $position )` once on success (root adds included,
	 * with $ref_post_id 0 and $position 'after') — shared by the legacy AJAX
	 * handler and the REST endpoint, same as move()'s own move-finish hook.
	 *
	 * @param array  $names       New page titles (blank entries are skipped).
	 * @param string $position    One of "after" or "inside".
	 * @param string $status      Requested post status (resolved/whitelisted below).
	 * @param int    $ref_post_id Reference node the new pages are placed relative to.
	 * @param string $post_type   Post type slug, used only for the root-add branch
	 *                            (ref_post_id <= 0); ignored otherwise.
	 * @return array{created: int[], post_type: string}
	 */
	public static function add_pages( $names, $position, $status, $ref_post_id, $post_type = '' ) {

		$ref_post_id = (int) $ref_post_id;

		// Root add: no reference post to derive post_type/parent from. Append at
		// the bottom of the root level of the caller-supplied post type instead.
		if ( $ref_post_id <= 0 ) {
			$root_result = self::add_root_pages( self::sanitize_names( $names ), $status, (string) $post_type );
			if ( ! empty( $root_result['created'] ) ) {
				do_action( 'cms_tree_page_view_pages_added', $root_result['created'], $root_result['post_type'], 0, 'after' );
			}
			return $root_result;
		}

		// Mirror the legacy cms_tpv_add_pages(): keep post_author/post_modified
		// stable on the menu_order "make room" updates below. add_filter is
		// idempotent for an identical callback + priority (it runs once), so the
		// legacy AJAX handler adding the same filter too is harmless.
		add_filter( 'wp_insert_post_data', 'cms_tpv_insert_post_data', 99, 2 );

		$post_position = $position;

		// Remove possibly empty posts.
		$arr_post_names = self::sanitize_names( $names );

		$arr_post_names_count = count( $arr_post_names );

		$ref_post = get_post( $ref_post_id );

		if ( empty( $arr_post_names ) || null === $ref_post ) {
			return array(
				'created'   => array(),
				'post_type' => $ref_post ? $ref_post->post_type : '',
			);
		}

		$post_type_object = get_post_type_object( $ref_post->post_type );

		// Normalize + whitelist the requested status and enforce this post type's
		// own publish capability (security todo 30, finding 2).
		$post_status = Legacy_Query::resolve_new_post_status( $status, $post_type_object );

		$post_parent = 0;
		if ( 'after' === $post_position ) {
			$post_parent = $ref_post->post_parent;
		} elseif ( 'inside' === $post_position ) {
			$post_parent = $ref_post->ID;
		}

		// Make room for our new pages: get all pages at this level and increase the
		// menu_order of everything after the reference page by the number of new
		// posts. orderby comes from cms_tpv_sibling_orderby() — the single source
		// of truth for the order the tree actually displays these siblings in, also
		// used by cms_tpv_get_pages() — so this never drifts out of sync with it.
		// Ordering by menu_order alone left ties (multiple siblings sharing one
		// menu_order, which is common — new pages default to 0) broken by an
		// unrelated DB order, so the loop below could decide a sibling had "passed"
		// the reference page when visually it hadn't, bumping the wrong posts and
		// dropping the new page one slot off from where the user actually released it.
		$args  = array(
			'post_status'      => 'any',
			'post_type'        => $ref_post->post_type,
			'numberposts'      => -1,
			'offset'           => 0,
			'orderby'          => Legacy_Query::sibling_orderby(),
			'order'            => 'asc',
			'post_parent'      => $post_parent,
			'suppress_filters' => false,
		);
		$posts = get_posts( $args );

		$new_menu_order = 0;

		// If posts exist at this level, make room by increasing the menu order.
		if ( count( $posts ) > 0 ) {

			if ( 'after' === $post_position ) {

				$has_passed_ref_post = false;
				foreach ( $posts as $one_post ) {

					if ( $has_passed_ref_post ) {

						// +1 beyond $arr_post_names_count (not just enough room for the
						// new pages): a following sibling can share $ref_post's own
						// menu_order (ties are common — new pages default to 0), so
						// bumping by exactly the new-page count would leave it tied
						// with the last new page instead of unambiguously after it —
						// mirrors Tree_Mutations::move()'s own "+1 extra for tie
						// safety" bump.
						$post_update = array(
							'ID'                => $one_post->ID,
							'menu_order'        => $one_post->menu_order + $arr_post_names_count + 1,
							'post_author'       => (int) $one_post->post_author,
							'post_modified'     => $one_post->post_modified,
							'post_modified_gmt' => $one_post->post_modified_gmt,
						);
						wp_update_post( $post_update );

					}

					if ( ! $has_passed_ref_post && $ref_post->ID === $one_post->ID ) {
						$has_passed_ref_post = true;
					}
				}

				$new_menu_order = $ref_post->menu_order;

			} elseif ( 'inside' === $post_position ) {

				// Inside: place at beginning, so base off the first post's menu
				// order. -1 beyond $arr_post_names_count (not just enough room for
				// the new pages): the loop below does `++$new_menu_order` before
				// each insert, so without this the LAST new page would land
				// exactly on $posts[0]->menu_order — tied with it — and the tree's
				// "menu_order title" tie-break could then sort that new page
				// AFTER $posts[0] alphabetically, defeating "place at the very
				// beginning". Mirrors the "after" branch's own tie-safety margin
				// above.
				$new_menu_order = $posts[0]->menu_order - $arr_post_names_count - 1;

			}
		}

		$post_parent_id = null;
		if ( 'after' === $post_position ) {
			$post_parent_id = $ref_post->post_parent;
		} elseif ( 'inside' === $post_position ) {
			$post_parent_id = $ref_post->ID;
		}

		// Done making room — add the new pages.
		$arr_added_pages_ids = array();
		foreach ( $arr_post_names as $one_new_post_name ) {

			++$new_menu_order;
			$newpost_args = array(
				'menu_order'  => $new_menu_order,
				'post_parent' => $post_parent_id,
				'post_status' => $post_status, // Already resolved via cms_tpv_resolve_new_post_status().
				'post_title'  => $one_new_post_name,
				'post_type'   => $ref_post->post_type,
			);
			$new_post_id  = wp_insert_post( $newpost_args );

			if ( 0 === $new_post_id ) {
				continue;
			}

			$arr_added_pages_ids[] = $new_post_id;
		}

		if ( ! empty( $arr_added_pages_ids ) ) {
			do_action( 'cms_tree_page_view_pages_added', $arr_added_pages_ids, $ref_post->post_type, $ref_post_id, $post_position );
		}

		return array(
			'created'   => $arr_added_pages_ids,
			'post_type' => $ref_post->post_type,
		);
	}

	/**
	 * Trim and sanitize a raw list of page-title strings, dropping blank ones.
	 *
	 * Extracted from add_pages() so add_root_pages() can reuse the identical
	 * filtering without duplicating the loop.
	 *
	 * @param array $names Raw title strings.
	 * @return string[] Sanitized, non-blank titles.
	 */
	private static function sanitize_names( $names ) {
		$sanitized = array();
		foreach ( (array) $names as $one_name ) {
			if ( trim( $one_name ) ) {
				$sanitized[] = sanitize_text_field( $one_name );
			}
		}
		return $sanitized;
	}

	/**
	 * Create one or more pages at the root (post_parent = 0) of a post type,
	 * appended after the current bottom-most root-level page. No "make room"
	 * shifting is needed — nothing else moves when appending.
	 *
	 * @param string[] $names     Sanitized, non-blank titles.
	 * @param string   $status    Requested post status (resolved/whitelisted below).
	 * @param string   $post_type Post type slug; must be a registered post type.
	 * @return array{created: int[], post_type: string}
	 */
	private static function add_root_pages( $names, $status, $post_type ) {
		if ( empty( $names ) || '' === $post_type ) {
			return array(
				'created'   => array(),
				'post_type' => '',
			);
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return array(
				'created'   => array(),
				'post_type' => '',
			);
		}

		add_filter( 'wp_insert_post_data', 'cms_tpv_insert_post_data', 99, 2 );

		$post_status = Legacy_Query::resolve_new_post_status( $status, $post_type_object );

		// Base off the current bottom-most root-level post of this type (any
		// status) so new pages are appended after it; no such post -> start at 1.
		$bottom         = get_posts(
			array(
				'post_status'      => 'any',
				'post_type'        => $post_type,
				'numberposts'      => 1,
				'orderby'          => 'menu_order',
				'order'            => 'desc',
				'post_parent'      => 0,
				'suppress_filters' => false,
			)
		);
		$new_menu_order = $bottom ? (int) $bottom[0]->menu_order : 0;

		$created = array();
		foreach ( $names as $one_new_post_name ) {
			++$new_menu_order;
			$new_post_id = wp_insert_post(
				array(
					'menu_order'  => $new_menu_order,
					'post_parent' => 0,
					'post_status' => $post_status,
					'post_title'  => $one_new_post_name,
					'post_type'   => $post_type,
				)
			);

			if ( 0 === $new_post_id ) {
				continue;
			}

			$created[] = $new_post_id;
		}

		return array(
			'created'   => $created,
			'post_type' => $post_type,
		);
	}

	/**
	 * Move a page to the Trash.
	 *
	 * Thin wrapper over core wp_trash_post() so the REST controller stays free of
	 * post-state logic. Returns the trashed ID on success, null on failure (e.g.
	 * the post does not exist, or is already in the trash). Does not check
	 * capabilities — the caller must do that first.
	 *
	 * @param int $id Post ID.
	 * @return int|null Trashed post ID, or null on failure.
	 */
	public static function trash_page( int $id ): ?int {
		$id = (int) $id;
		if ( ! $id || ! get_post( $id ) ) {
			return null;
		}
		$result = wp_trash_post( $id );
		return $result ? $id : null;
	}
}
