<?php
/**
 * Elementor integration: an "Edit in Elementor" link in the tree's detail card.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Integrations;

/**
 * Adds an "Edit in Elementor" link to pages Elementor actually built.
 *
 * Elementor is an optional dependency: with it inactive, every guard below bails
 * and the filter returns its input untouched, so nothing renders and nothing
 * errors.
 *
 * Deliberately no fallback path. Earlier versions of this feature (1.6.6) built
 * the URL by string-replacing action=edit with action=elementor on every page
 * whenever the plugin was active — no per-page check, no capability check of its
 * own. Asking Elementor for its own edit URL is both more correct and immune to
 * a URL scheme change on their side. If the API is missing we show no link,
 * which is the right answer: either Elementor isn't there, or it's a version so
 * different that guessing a URL would more likely produce a broken link.
 */
class Elementor_Integration {

	/**
	 * Wire the filter. Called once from the plugin bootstrap.
	 */
	public static function register_hooks(): void {
		add_filter( 'cms_tree_page_view_post_edit_links', array( __CLASS__, 'add_edit_link' ), 10, 2 );
	}

	/**
	 * Append the Elementor edit link for pages built with Elementor.
	 *
	 * @param array<mixed> $links   Links collected so far.
	 * @param int          $page_id Post ID.
	 * @return array<mixed>
	 */
	public static function add_edit_link( array $links, int $page_id ): array {
		$document = self::get_document( $page_id );

		if ( ! $document ) {
			return $links;
		}

		if ( ! $document->is_built_with_elementor() ) {
			return $links;
		}

		// Elementor's own permission check, on top of the canEdit gate the filter
		// is already wrapped in.
		if ( ! $document->is_editable_by_current_user() ) {
			return $links;
		}

		$links[] = array(
			'id'    => 'elementor',
			'label' => __( 'Edit in Elementor', 'cms-tree-page-view' ),
			'url'   => $document->get_edit_url(),
		);

		return $links;
	}

	/**
	 * Elementor's document object for a post, or null when unavailable.
	 *
	 * A class_exists() check only proves the class was autoloaded, not that
	 * Elementor bootstrapped: Plugin::$instance is null until it initializes, so
	 * reaching straight for ::$instance->documents can fatal on a null property.
	 * isset() covers both a null $instance and a missing documents property at
	 * once.
	 *
	 * No method_exists() checks on the document itself — those are Elementor's
	 * documented public API, and guarding them would turn a loud, greppable fatal
	 * into a link that silently stops appearing.
	 *
	 * @param int $page_id Post ID.
	 * @return \Elementor\Core\Base\Document|null
	 */
	private static function get_document( int $page_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		if ( ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return null;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $page_id );

		return $document ? $document : null;
	}
}
