<?php
/**
 * Logs page moves and page creation to Simple History (https://simple-history.com/),
 * Pär's other WordPress plugin.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Third-party Simple History logger for this plugin's tree mutations.
 *
 * Registered via the `simple_history/add_custom_logger` action (see index.php),
 * which only fires when Simple History is active. That action predates the
 * namespaced base class below by several major versions, so index.php also
 * checks \Simple_History\Loggers\Logger (4.0+) exists before registering —
 * otherwise an older Simple History autoloads this file and fatals on the
 * missing parent. Nothing else in the plugin may reference this class.
 */
class Simple_History_Logger extends \Simple_History\Loggers\Logger {

	/**
	 * Logger slug (shown in Simple History's logger list/settings).
	 *
	 * @var string
	 */
	public $slug = 'CMS_Tree_Page_View_Logger';

	/**
	 * Return info about this logger.
	 *
	 * @inheritDoc
	 */
	public function get_info() {
		return array(
			'name'        => __( 'CMS Tree Page View', 'cms-tree-page-view' ),
			'description' => __( 'Logs pages moved, reparented, or added via the page tree.', 'cms-tree-page-view' ),
			// Simple History renders this as a small "via …" attribution next to
			// each log row's time (get_log_row_header_using_plugin_output()) and
			// exposes it as the REST `via` field — so every event this logger
			// emits is marked as coming from the page tree, in Simple History and
			// in this plugin's own activity card. Logger-level, not a per-event
			// context key; matches how Simple History's bundled loggers do it.
			'name_via'    => __( 'Using plugin CMS Tree Page View', 'cms-tree-page-view' ),
			'capability'  => 'edit_pages',
			'messages'    => array(
				'page_moved'        => __( 'Moved "{page_title}" in the page tree', 'cms-tree-page-view' ),
				'page_type_changed' => __( 'Changed "{page_title}" from {old_post_type} to {new_post_type} via the page tree', 'cms-tree-page-view' ),
				'page_added'        => __( 'Added new page "{page_title}" via the page tree', 'cms-tree-page-view' ),
				// Retained so events logged by the previous bulk-add code still
				// render; no longer emitted (on_pages_added() now logs one
				// 'page_added' per page — see its docblock).
				'pages_added'       => __( 'Added {count} new pages via the page tree, including "{page_title}"', 'cms-tree-page-view' ),
			),
			'labels'      => array(
				'search' => array(
					'label'     => __( 'Page tree', 'cms-tree-page-view' ),
					'label_all' => __( 'All page tree activity', 'cms-tree-page-view' ),
					'options'   => array(
						__( 'Pages moved or added via the page tree', 'cms-tree-page-view' ) => array(
							'page_moved',
							'page_type_changed',
							'page_added',
							'pages_added',
						),
					),
				),
			),
		);
	}

	/**
	 * Hook the tree mutation events this logger reports on.
	 *
	 * @inheritDoc
	 */
	public function loaded(): void {
		add_action( 'cms_tree_page_view_node_move_finish', array( $this, 'on_node_move_finish' ), 10, 4 );
		add_action( 'cms_tree_page_view_pages_added', array( $this, 'on_pages_added' ), 10, 4 );
	}

	/**
	 * Log a completed move/reorder — see Tree_Mutations::move()'s docblock for
	 * the hook's exact contract.
	 *
	 * $post_node isn't type-hinted \WP_Post despite that being the only value
	 * Tree_Mutations::move() itself ever fires this action with — it's a public
	 * action name, so nothing stops another plugin/theme firing it with
	 * something else, hence the instanceof guard below.
	 *
	 * @param mixed  $post_node      Moved post, as it was BEFORE the move.
	 * @param mixed  $_post_ref_node Reference post the move was relative to (unused —
	 *                               kept for parity with the fixed hook signature).
	 * @param string $_type          "before", "after", or "inside" (unused — see above).
	 * @param array  $post_to_save   The wp_update_post() array the move actually applied.
	 */
	public function on_node_move_finish( $post_node, $_post_ref_node, $_type, $post_to_save ) {
		if ( ! $post_node instanceof \WP_Post ) {
			return;
		}

		$new_post_type = isset( $post_to_save['post_type'] ) ? (string) $post_to_save['post_type'] : $post_node->post_type;

		// Every move() branch (before/after/inside) sets post_type to the
		// reference node's post_type — a cross-post-type move is reachable via
		// any of them (e.g. the legacy multi-tree Dashboard view lets you drop
		// before/after a row in a different tree), not just "inside" drops.
		if ( $new_post_type !== $post_node->post_type ) {
			$this->info_message(
				'page_type_changed',
				array(
					'page_title'    => $post_node->post_title,
					// 'post_id'/'post_type' are Simple History's own context-key
					// convention (matching its SimplePostLogger). Using them is what
					// lets this event surface in the per-page history card, which
					// matches events on the 'post_id' context filter — a plugin-
					// specific key like 'page_id' would never match and the event
					// stayed invisible.
					'post_id'       => $post_node->ID,
					'post_type'     => $new_post_type,
					'old_post_type' => $post_node->post_type,
					'new_post_type' => $new_post_type,
					// Group a run of reparents into one feed row. Deliberately omits
					// the post id (unlike Simple History's post logger) so moves of
					// DIFFERENT pages collapse together — see 'page_moved' below.
					// Kept distinct from 'page_moved' so a reparent doesn't fold into
					// plain reorders. post_id stays in context above, so the per-page
					// history card still matches.
					'_occasionsID'  => self::class . '/page_type_changed',
				)
			);
			return;
		}

		$this->info_message(
			'page_moved',
			array(
				'page_title'   => $post_node->post_title,
				'post_id'      => $post_node->ID,
				'post_type'    => $new_post_type,
				// A burst of drags is one REST /move call each, so this logs once per
				// moved page. Set an occasionsID that omits the post id so consecutive
				// reorders collapse into a single "Moved X + N similar events" row
				// instead of flooding the log. Simple History md5-hashes this string
				// (with the logger slug) into the row's occasionsID and drops the key
				// from the stored context; post_id above is untouched.
				'_occasionsID' => self::class . '/page_moved',
			)
		);
	}

	/**
	 * Log one or more pages created via the tree — see
	 * Tree_Mutations::add_pages()'s docblock for the hook's exact contract.
	 *
	 * @param int[]  $created_ids  IDs of the newly created pages.
	 * @param string $post_type    Post type of the created pages.
	 * @param int    $_ref_post_id Reference post the pages were placed relative to (0 for a
	 *                             root add); unused here — kept for parity with the hook signature.
	 * @param string $_position    "after" or "inside" (unused — see above).
	 */
	public function on_pages_added( $created_ids, $post_type, $_ref_post_id, $_position ) {
		if ( empty( $created_ids ) ) {
			return;
		}

		// Log one 'page_added' per created page — each carrying its OWN post_id —
		// rather than a single bulk summary. The per-page history card matches
		// events on the 'post_id' context filter, so a bulk event (which can only
		// carry one representative post_id) would leave every other created page
		// with no "created" entry in its own history. The shared '_occasionsID'
		// below (which omits the post id) makes Simple History collapse the run of
		// events into one "N similar events" row in the global feed, so creating
		// many pages at once stays tidy there too. The 'pages_added' message is
		// kept registered in get_info() only so any events logged by the previous
		// bulk-summary code still render; it is no longer emitted.
		foreach ( $created_ids as $created_id ) {
			$post = get_post( $created_id );
			$this->info_message(
				'page_added',
				array(
					'page_title'   => $post ? $post->post_title : '',
					'post_id'      => (int) $created_id,
					'post_type'    => $post_type,
					// Constant across the batch (no post id) so a multi-page add
					// groups into a single feed row; post_id above still gives each
					// page its own per-page history entry.
					'_occasionsID' => self::class . '/page_added',
				)
			);
		}
	}
}
