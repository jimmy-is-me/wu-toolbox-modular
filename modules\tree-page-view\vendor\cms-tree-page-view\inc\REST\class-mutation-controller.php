<?php
/**
 * REST write endpoints for the CMS Tree Page View React tree.
 *
 * Currently exposes POST /cms-tpv/v1/move, the drag-and-drop reorder. The actual
 * mutation is delegated to Tree_Mutations::move() — the same code the legacy AJAX
 * handler runs — so REST and AJAX stay in lock-step. This controller owns only the
 * REST concerns: nonce, authentication, and the both-nodes edit_post permission gate.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\REST;

use CMS_Tree_Page_View\Data\Tree_Data;
use CMS_Tree_Page_View\Data\Tree_Mutations;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write REST endpoints backing the React tree.
 */
class Mutation_Controller extends WP_REST_Controller {

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
			'/move',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'refId'    => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'type'     => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'before', 'after', 'inside' ),
						),
						// Post type + view of the requesting tree, so the response can
						// return the affected levels' new order for the client to apply
						// without a separate read round trip.
						'postType' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'view'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'all',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_pages' ),
					'permission_callback' => array( $this, 'create_pages_permissions_check' ),
					'args'                => array(
						'names'    => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
						'position' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'after', 'inside' ),
						),
						'status'   => array(
							'type'     => 'string',
							'required' => false,
							'default'  => 'draft',
							'enum'     => array( 'draft', 'pending', 'publish', 'published' ),
						),
						'refId'    => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'postType' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'view'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'all',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/trash',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_trash_item' ),
					'permission_callback' => array( $this, 'trash_permissions_check' ),
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
	 * Whether a user may edit a given post, honoring the plugin's filter.
	 *
	 * Mirrors the legacy cms_tpv_move_page() per-node check: edit_post for the
	 * post's own type, wrapped in the cms_tree_page_view_post_can_edit filter.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function user_can_edit_post( $post ) {
		$obj = get_post_type_object( $post->post_type );
		if ( ! $obj || ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $post->post_type ) ) {
			return false;
		}
		return (bool) apply_filters(
			'cms_tree_page_view_post_can_edit',
			current_user_can( $obj->cap->edit_post, $post->ID ),
			$post->ID
		);
	}

	/**
	 * Permission: logged in, both nodes resolvable, and the user may edit both.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cms_tpv_unauthorized', __( 'You must be logged in.', 'cms-tree-page-view' ), array( 'status' => 401 ) );
		}

		$node = get_post( (int) $request->get_param( 'id' ) );
		$ref  = get_post( (int) $request->get_param( 'refId' ) );

		if ( ! $node || ! $ref ) {
			return new WP_Error( 'cms_tpv_bad_request', __( 'Unknown page.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}

		if ( ! $this->user_can_edit_post( $node ) || ! $this->user_can_edit_post( $ref ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST /move
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$node = get_post( (int) $request->get_param( 'id' ) );
		$ref  = get_post( (int) $request->get_param( 'refId' ) );

		// Re-resolve defensively: permission_callback already verified both exist.
		if ( ! $node || ! $ref ) {
			return new WP_Error( 'cms_tpv_bad_request', __( 'Unknown page.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}

		if ( 'trash' === $node->post_status ) {
			return new WP_Error( 'cms_tpv_trashed', __( 'A page in the trash cannot be moved.', 'cms-tree-page-view' ), array( 'status' => 422 ) );
		}

		$type = $request->get_param( 'type' );
		if ( 'inside' === $type && ! $this->post_type_supports_children( $ref->post_type ) ) {
			return new WP_Error( 'cms_tpv_not_hierarchical', __( 'This post type does not support child pages.', 'cms-tree-page-view' ), array( 'status' => 422 ) );
		}

		// Capture the moved node's parent BEFORE the move — that's the "old" level
		// that loses the node; $node holds the pre-move snapshot even after the DB
		// update below.
		$old_parent = (int) $node->post_parent;
		$new_parent = 'inside' === $type ? (int) $ref->ID : (int) $ref->post_parent;

		$result = Tree_Mutations::move( $node->ID, $ref->ID, $type );

		if ( empty( $result['moved'] ) ) {
			return new WP_Error( 'cms_tpv_move_failed', __( 'The page could not be moved.', 'cms-tree-page-view' ), array( 'status' => 422 ) );
		}

		// Return the affected levels' new order so the client applies the truth
		// without a separate read round trip (and no reconcile-fetch race). Built
		// for the requesting tree's own post type + view.
		$post_type = (string) $request->get_param( 'postType' );
		if ( '' === $post_type ) {
			$post_type = $node->post_type;
		}
		$result['levels'] = Tree_Data::get_levels(
			array( $old_parent, $new_parent ),
			$post_type,
			(string) $request->get_param( 'view' )
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Whether the user may add a page in the requested position next to the ref.
	 *
	 * Mirrors the legacy cms_tpv_add_pages() gate: create_posts for the reference
	 * node's post type, wrapped in the cms_tree_page_view_post_user_can_add_inside
	 * / _after filter for the requested position.
	 *
	 * @param \WP_Post $ref      Reference post.
	 * @param string   $position after|inside.
	 * @return bool
	 */
	private function user_can_add_page( $ref, $position ) {
		$obj = get_post_type_object( $ref->post_type );
		if ( ! $obj || ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $ref->post_type ) ) {
			return false;
		}
		if ( 'inside' === $position && ! $this->post_type_supports_children( $ref->post_type ) ) {
			return false;
		}
		$can = current_user_can( $obj->cap->create_posts, $ref->ID );

		if ( 'inside' === $position ) {
			// "Inside" only appends among the reference's children; it never
			// renumbers the reference or its siblings. Core lets anyone who may
			// create a page choose any published page as its parent (the Page
			// Attributes dropdown lists exactly those), so create_posts is the
			// right and only gate here.
			return (bool) apply_filters( 'cms_tree_page_view_post_user_can_add_inside', $can, $ref->ID );
		}

		// "After" places the new page among the reference's siblings and bumps
		// their menu_order to make room (Tree_Mutations::add_pages()) — a write
		// against a level the caller may not own. create_posts doesn't prove that
		// right, so require edit_post on the reference as well, the same demand
		// POST /move makes of both its nodes. This matters since todo 59: the
		// tree lists other authors' published pages, so the card offers "Add
		// page → After" on them.
		$can = $can && current_user_can( $obj->cap->edit_post, $ref->ID );

		return (bool) apply_filters( 'cms_tree_page_view_post_user_can_add_after', $can, $ref->ID );
	}

	/**
	 * Whether a post type supports being a parent (child pages / "make child"
	 * drops). Mirrors the legacy cms_tpv_is_post_type_hierarchical() special
	 * case: 'post' is treated as hierarchical too, even though core registers
	 * it as non-hierarchical.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private function post_type_supports_children( $post_type ) {
		$obj = get_post_type_object( $post_type );
		return (bool) ( $obj && \CMS_Tree_Page_View\Data\Legacy_Query::is_post_type_hierarchical( $obj ) );
	}

	/**
	 * Permission: logged in, valid position, and either (refId>0) the ref post
	 * resolves and the user may add relative to it, or (refId<=0, root add) the
	 * postType resolves and the user has create_posts for it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_pages_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cms_tpv_unauthorized', __( 'You must be logged in.', 'cms-tree-page-view' ), array( 'status' => 401 ) );
		}

		$position = $request->get_param( 'position' );
		if ( ! in_array( $position, array( 'after', 'inside' ), true ) ) {
			return new WP_Error( 'cms_tpv_bad_request', __( 'Unknown page.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}

		$ref_id = (int) $request->get_param( 'refId' );

		if ( $ref_id > 0 ) {
			$ref = get_post( $ref_id );
			if ( ! $ref ) {
				return new WP_Error( 'cms_tpv_bad_request', __( 'Unknown page.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
			}
			if ( ! $this->user_can_add_page( $ref, $position ) ) {
				return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
			}
			return true;
		}

		// Root add: no reference post — check create_posts for the caller-supplied post
		// type, then still run it through the same cms_tree_page_view_post_user_can_add_
		// inside/_after filter every other add-page path honors (ref_post_id 0, since
		// there's no real reference post at the root) so a site hooking either filter
		// to further restrict page creation isn't silently bypassed here.
		$post_type = (string) $request->get_param( 'postType' );
		$obj       = $post_type ? get_post_type_object( $post_type ) : null;
		if ( ! $obj || ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $post_type ) ) {
			return new WP_Error( 'cms_tpv_bad_post_type', __( 'Unknown post type.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}
		$can = current_user_can( $obj->cap->create_posts );
		if ( 'inside' === $position ) {
			$can = apply_filters( 'cms_tree_page_view_post_user_can_add_inside', $can, 0 );
		} else {
			$can = apply_filters( 'cms_tree_page_view_post_user_can_add_after', $can, 0 );
		}
		if ( ! $can ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * POST /pages
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_pages( $request ) {
		$ref_id   = (int) $request->get_param( 'refId' );
		$position = $request->get_param( 'position' );

		if ( $ref_id > 0 ) {
			$ref = get_post( $ref_id );

			// Re-resolve defensively: permission_callback already verified it exists.
			if ( ! $ref ) {
				return new WP_Error( 'cms_tpv_bad_request', __( 'Unknown page.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
			}

			// The level the new pages join: inside the ref, or alongside it (its parent).
			$parent = 'inside' === $position ? (int) $ref->ID : (int) $ref->post_parent;

			$result = Tree_Mutations::add_pages(
				(array) $request->get_param( 'names' ),
				$position,
				(string) $request->get_param( 'status' ),
				$ref->ID
			);
		} else {
			// Root add.
			$parent = 0;
			$result = Tree_Mutations::add_pages(
				(array) $request->get_param( 'names' ),
				$position,
				(string) $request->get_param( 'status' ),
				0,
				(string) $request->get_param( 'postType' )
			);
		}

		if ( empty( $result['created'] ) ) {
			return new WP_Error( 'cms_tpv_add_failed', __( 'No pages could be added.', 'cms-tree-page-view' ), array( 'status' => 422 ) );
		}

		// Return the level the new pages joined so the client swaps its optimistic
		// placeholder for the real page(s) without a separate read round trip.
		$levels = Tree_Data::get_levels(
			array( $parent ),
			(string) $result['post_type'],
			(string) $request->get_param( 'view' )
		);

		return rest_ensure_response(
			array(
				'created'  => $result['created'],
				'postType' => $result['post_type'],
				'levels'   => $levels,
			)
		);
	}

	/**
	 * Permission: logged in, post resolvable, and the user may delete it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function trash_permissions_check( $request ) {
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

		// An id of an internal type the tree never lists (revision, attachment,
		// nav_menu_item) is "not found" for trashing, mirroring the read endpoint.
		if ( ! \CMS_Tree_Page_View\Settings\Options::is_manageable_post_type( $post->post_type ) ) {
			return new WP_Error( 'cms_tpv_not_found', __( 'Page not found.', 'cms-tree-page-view' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( $obj->cap->delete_post, $post->ID ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST /trash
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_trash_item( $request ) {
		$id = (int) $request->get_param( 'id' );

		// Re-resolve defensively: permission_callback already verified it exists.
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'cms_tpv_not_found', __( 'Page not found.', 'cms-tree-page-view' ), array( 'status' => 404 ) );
		}

		$trashed = Tree_Mutations::trash_page( $id );

		if ( null === $trashed ) {
			return new WP_Error( 'cms_tpv_trash_failed', __( 'The page could not be trashed.', 'cms-tree-page-view' ), array( 'status' => 422 ) );
		}

		return rest_ensure_response( array( 'trashed' => $trashed ) );
	}
}
