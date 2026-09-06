<?php
/**
 * REST read endpoints for the CMS Tree Page View React tree.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\REST;

use CMS_Tree_Page_View\Data\Tree_Data;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only REST endpoints backing the React tree.
 */
class Tree_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'cms-tpv/v1';
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/tree',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'cms_tpv_post_type' => array(
							'type'              => 'string',
							'default'           => 'page',
							'sanitize_callback' => 'sanitize_key',
						),
						'parent'            => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'view'              => array(
							'type'    => 'string',
							'default' => 'all',
							// 'all_with_trash' is the base tree behind the Trash filter
							// tab: the full hierarchy plus trashed pages, so trashed
							// matches have a real tree row to render in (see Tree.jsx).
							'enum'    => array( 'all', 'all_with_trash', 'mine', 'publish', 'draft', 'pending', 'trash' ),
						),
						'cms_tpv_search'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filter'            => array(
							'type'    => 'string',
							'default' => '',
							// 'all' (and '') are accepted but treated as "no filter" —
							// they fall through to the normal per-level listing.
							'enum'    => array( '', 'all', 'mine', 'publish', 'draft', 'pending', 'trash' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/counts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_counts' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'cms_tpv_post_type' => array(
							'type'              => 'string',
							'default'           => 'page',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/page/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Resolve the post type object for a /tree request, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_Post_Type|null
	 */
	private function post_type_for( WP_REST_Request $request ) {
		$post_type = $request->get_param( 'cms_tpv_post_type' );
		return $post_type ? get_post_type_object( $post_type ) : null;
	}

	/**
	 * Permission: can the user edit this post type at all?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cms_tpv_unauthorized', __( 'You must be logged in.', 'cms-tree-page-view' ), array( 'status' => 401 ) );
		}
		$obj = $this->post_type_for( $request );
		if ( ! $obj || ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $obj->name ) ) {
			// Unknown, or a real-but-internal type the tree never shows
			// (revision, attachment, nav_menu_item) — refuse rather than list it.
			return new WP_Error( 'cms_tpv_bad_post_type', __( 'Unsupported post type.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( $obj->cap->edit_posts ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission for a single page request.
	 *
	 * Derives the post type from the post itself — never from a request param —
	 * to prevent a caller from bypassing the cap check by passing ?cms_tpv_post_type=page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cms_tpv_unauthorized', __( 'You must be logged in.', 'cms-tree-page-view' ), array( 'status' => 401 ) );
		}
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return new WP_Error( 'cms_tpv_not_found', __( 'Page not found.', 'cms-tree-page-view' ), array( 'status' => 404 ) );
		}
		$obj = get_post_type_object( $post->post_type );
		if ( ! $obj ) {
			return new WP_Error( 'cms_tpv_bad_post_type', __( 'Unknown post type.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}
		// The tree only manages UI-visible post types — an id belonging to an
		// internal type (revision, attachment, nav_menu_item) is "not found" as
		// far as the tree is concerned, even when the caller can edit_post it.
		// Answer 404 before the cap check so the detail endpoint never returns a
		// payload for an object the tree would never list (todo 52).
		if ( ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $post->post_type ) ) {
			return new WP_Error( 'cms_tpv_not_found', __( 'Page not found.', 'cms-tree-page-view' ), array( 'status' => 404 ) );
		}
		// Two gates, the same pair the tree listing applies — the type, then the
		// row.
		//
		// Type first: the caller must be able to edit *some* post of this type,
		// exactly as get_items_permissions_check() requires before listing one.
		// Without it, deriving the type from the post would let anyone pull node
		// data for a CPT they hold no capability for at all, just by knowing an
		// id — a published post on a restricted CPT is still that CPT's.
		if ( ! current_user_can( $obj->cap->edit_posts ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}

		// Then the row: the same visibility rule the listing uses (todo 59), so
		// anything the tree shows can also be selected. This is deliberately
		// looser than the old per-post edit_post check — everything sensitive in
		// the payload (excerpt, previewUrl, editLinks) stays gated on the node's
		// own canEdit inside get_detail(), so a page the user may read but not
		// edit yields a read-only card instead of an error.
		if ( ! \CMS_Tree_Page_View\Data\Post_Visibility::can_see_post( $post, $obj ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /tree
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$search = (string) $request->get_param( 'cms_tpv_search' );
		if ( '' !== trim( $search ) ) {
			$obj  = $this->post_type_for( $request );
			$type = $obj ? $obj->name : 'page';
			return rest_ensure_response( Tree_Data::search_nodes( $type, $search ) );
		}

		// A status filter (mine/publish/draft/pending/trash) returns the same
		// match+ancestors overlay shape as search — the client renders a tree of
		// matches with their ancestors as muted context. 'all' falls through to
		// the normal per-level hierarchical listing below.
		$filter = (string) $request->get_param( 'filter' );
		if ( '' !== $filter && 'all' !== $filter ) {
			$obj  = $this->post_type_for( $request );
			$type = $obj ? $obj->name : 'page';
			return rest_ensure_response( Tree_Data::filter_nodes( $type, $filter ) );
		}

		$nodes = Tree_Data::get_nodes(
			array(
				'post_type' => $request->get_param( 'cms_tpv_post_type' ),
				'parent'    => (int) $request->get_param( 'parent' ),
				'view'      => $request->get_param( 'view' ),
			)
		);
		return rest_ensure_response( $nodes );
	}

	/**
	 * GET /counts
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_counts( $request ) {
		$obj  = $this->post_type_for( $request );
		$type = $obj ? $obj->name : 'page';
		return rest_ensure_response( Tree_Data::get_status_counts( $type ) );
	}

	/**
	 * GET /page/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$detail = Tree_Data::get_detail( (int) $request->get_param( 'id' ) );
		if ( null === $detail ) {
			return new WP_Error( 'cms_tpv_not_found', __( 'Page not found.', 'cms-tree-page-view' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $detail );
	}
}
