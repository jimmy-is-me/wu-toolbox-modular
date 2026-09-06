<?php
/**
 * REST endpoint for the Simple History activity feed.
 *
 * GET /cms-tpv/v1/activity?post=<id|omitted>&per_page=<int>. Returns
 * { available:false, items:[] } when Simple History is not installed.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\REST;

use CMS_Tree_Page_View\Data\Activity_Reader;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only activity feed controller.
 */
class Activity_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'cms-tpv/v1';
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/activity',
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
						'post'              => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'per_page'          => array(
							'type'              => 'integer',
							'default'           => 8,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: logged in + the post type's edit_posts cap (mirrors the tree).
	 *
	 * Gate coarseness note (C2): this checks only the post type's `edit_posts` cap.
	 * The global feed returns SimplePostLogger events across all post types; the
	 * per-page feed returns events for whatever post_id the caller supplies — both
	 * are further bounded by Simple History's own per-logger read permissions inside
	 * Log_Query. get_items() additionally gates the per-post branch on `edit_post`
	 * for that specific post (C4).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cms_tpv_unauthorized', __( 'You must be logged in.', 'cms-tree-page-view' ), array( 'status' => 401 ) );
		}
		$obj = get_post_type_object( sanitize_key( (string) $request->get_param( 'cms_tpv_post_type' ) ) );
		if ( ! $obj ) {
			return new WP_Error( 'cms_tpv_bad_post_type', __( 'Unknown post type.', 'cms-tree-page-view' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( $obj->cap->edit_posts ) ) {
			return new WP_Error( 'cms_tpv_forbidden', __( 'You are not allowed to do that.', 'cms-tree-page-view' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /activity
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$post = (int) $request->get_param( 'post' );

		// Per-post gate runs before the availability short-circuit below: whether
		// or not Simple History is installed, an unauthorized caller must never
		// get a different response for a post they can't edit than for one they
		// can (C4 — tightens the type-wide edit_posts check in
		// get_items_permissions_check() to this specific post).
		if ( $post ) {
			$post_obj = get_post( $post );
			if ( ! $post_obj || ! current_user_can( 'edit_post', $post ) ) {
				return new WP_Error(
					'cms_tpv_forbidden',
					__( 'You are not allowed to view this activity.', 'cms-tree-page-view' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( ! Activity_Reader::is_available() ) {
			return rest_ensure_response(
				array(
					'available' => false,
					'items'     => array(),
				)
			);
		}

		$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$items = $post
			? Activity_Reader::for_post( $post, $per_page )
			: Activity_Reader::recent( $per_page, (string) $request->get_param( 'cms_tpv_post_type' ) );

		return rest_ensure_response(
			array(
				'available' => true,
				'items'     => $items,
			)
		);
	}
}
