<?php
/**
 * Simple History integration: a per-page history link on the tree rows.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Integrations;

/**
 * Adds a "Page history" action pointing at Simple History's own events page,
 * filtered to one post.
 *
 * Simple History is an optional dependency — the same one `Data\Activity_Reader`
 * already degrades around — so with it inactive every guard below bails and the
 * filter returns its input untouched.
 *
 * Deliberately no fallback URL. Simple History ships
 * `Helpers::get_filtered_history_url()`, which knows both where its admin page
 * lives (it moves between `admin.php` and `options-general.php` depending on the
 * menu setup) and how its context filters are spelled. Building that URL by hand
 * would break the first time either changes — the same reasoning that governs
 * `Elementor_Integration`.
 *
 * This link is the honest way to promote Simple History: it is useful only to
 * people who already run it, and it never appears on a site that does not. The
 * install pitch belongs in the detail card's activity feed, where it is
 * contextual and dismissible.
 */
class Simple_History_Integration {

	/**
	 * Wire the filter. Called once from the plugin bootstrap.
	 */
	public static function register_hooks(): void {
		add_filter( 'cms_tree_page_view_post_edit_links', array( __CLASS__, 'add_history_link' ), 20, 2 );
	}

	/**
	 * Append the "Page history" link for sites running Simple History.
	 *
	 * @param array<mixed> $links   Links collected so far.
	 * @param int          $page_id Post ID.
	 * @return array<mixed>
	 */
	public static function add_history_link( array $links, int $page_id ): array {
		// class_exists() alone is not enough: Helpers has existed since Simple
		// History 4.0, but get_filtered_history_url() only landed in 5.27.0. On
		// anything older the class guard passed and the call below fatalled.
		if ( ! method_exists( '\Simple_History\Helpers', 'get_filtered_history_url' ) ) {
			return $links;
		}

		// Simple History runs its own capability check on the page this links to,
		// so a user who lands there without permission gets its refusal rather
		// than ours. What we must not do is advertise a link to someone who can
		// see no history at all.
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $links;
		}

		// @phpstan-ignore staticMethod.notFound (Simple History 5.27.0+ only, guarded above)
		$url = \Simple_History\Helpers::get_filtered_history_url(
			array( 'context' => 'post_id:' . $page_id )
		);

		if ( ! is_string( $url ) || '' === $url ) {
			return $links;
		}

		$links[] = array(
			'id'       => 'history',
			'label'    => __( 'Page history', 'cms-tree-page-view' ),
			'url'      => $url,
			'icon'     => 'history',
			// The one action here that is worth a row icon: "who changed this?"
			// is asked while scanning, about a page you have not selected yet.
			'position' => 'both',
		);

		return $links;
	}
}
