<?php
/**
 * Post_Visibility class file.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which posts of a given type may the current user see in the tree?
 *
 * Single source of truth for the whole read surface: the level listing, search,
 * the status filters, the child counts, the status-tab counts and the detail
 * endpoint all resolve visibility here, so they cannot disagree with each other.
 *
 * The rule is core's own, from map_meta_cap()'s `read_post`: a public status is
 * visible to everyone, your own post is always visible, `private` needs
 * read_private_posts, and anything else (another author's draft, pending,
 * future, trashed) needs edit_others_posts.
 *
 * Deliberately NOT "only your own posts", which is what core's flat edit.php
 * list table does (wp_edit_posts_query()) and what this plugin copied in 1.7.1.
 * A tree is walked from the root down, so restricting every level to your own
 * posts also removes the ancestors the walk goes through — which left users who
 * may only edit their own pages staring at an empty tree (todo 59).
 *
 * Two forms, because the callers genuinely differ:
 *
 * - where_sql() for queries that must filter in the database — the aggregate
 *   counts, and search_nodes() where a LIMIT would otherwise be spent on rows
 *   the user can't see. Applied through the posts_where filter below.
 * - can_see_post() for Legacy_Query::get_pages(), which runs through get_posts()
 *   with suppress_filters => true, so no posts_where filter of ours can fire
 *   there. Turning that flag off would newly expose the tree to every
 *   third-party posts_* filter on the site, which is not a change this fix
 *   should be making. That listing is unpaginated (numberposts => -1), so
 *   filtering its result in PHP is exact, not lossy.
 *
 * PostVisibilityTest pins the two forms to the same result set.
 */
class Post_Visibility {

	/**
	 * Private query var marking a WP_Query as one of ours. The posts_where
	 * filter below appends its clause only to queries carrying it, so the
	 * restriction can never leak into an unrelated query running in the same
	 * call stack.
	 */
	const QUERY_VAR = 'cms_tpv_restrict_post_type';

	/**
	 * Register the posts_where filter. Called from index.php.
	 */
	public static function register_hooks() {
		add_filter( 'posts_where', array( __CLASS__, 'filter_posts_where' ), 10, 2 );
	}

	/**
	 * Is the current user limited to what they may read, rather than seeing
	 * everything of this type?
	 *
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @return bool
	 */
	public static function is_restricted( \WP_Post_Type $post_type_object ): bool {
		return ! current_user_can( $post_type_object->cap->edit_others_posts );
	}

	/**
	 * Statuses a restricted user may see regardless of who wrote the post.
	 *
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @return string[]
	 */
	public static function readable_statuses( \WP_Post_Type $post_type_object ): array {
		$statuses = array_values( get_post_stati( array( 'public' => true ) ) );

		if ( current_user_can( $post_type_object->cap->read_private_posts ) ) {
			$statuses[] = 'private';
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * May the current user see this specific post?
	 *
	 * @param \WP_Post      $post             Post.
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @return bool
	 */
	public static function can_see_post( \WP_Post $post, \WP_Post_Type $post_type_object ): bool {
		if ( ! self::is_restricted( $post_type_object ) ) {
			return true;
		}

		if ( (int) $post->post_author === get_current_user_id() ) {
			return true;
		}

		return in_array( $post->post_status, self::readable_statuses( $post_type_object ), true );
	}

	/**
	 * The same rule as SQL, for callers that must filter in the database.
	 *
	 * Returns the template and its params rather than prepared SQL so each
	 * caller prepares once in its own context — no nested prepare(), and no
	 * chance of a second pass re-processing an already-substituted value.
	 *
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @param string        $table            Posts table name (usually $wpdb->posts).
	 * @return array{0: string, 1: array<int, mixed>} [ sql, params ]; [ '', [] ] when unrestricted.
	 */
	public static function where_sql( \WP_Post_Type $post_type_object, string $table ): array {
		if ( ! self::is_restricted( $post_type_object ) ) {
			return array( '', array() );
		}

		$statuses = self::readable_statuses( $post_type_object );
		$user_id  = get_current_user_id();

		// No public status registered at all (not reachable with core's own
		// statuses, but the filterable status registry makes it expressible):
		// fall back to "your own posts only" rather than an empty IN () clause.
		if ( empty( $statuses ) ) {
			return array( " AND {$table}.post_author = %d", array( $user_id ) );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = " AND ( {$table}.post_status IN ( {$placeholders} ) OR {$table}.post_author = %d )";

		return array( $sql, array_merge( $statuses, array( $user_id ) ) );
	}

	/**
	 * The 'posts_where' callback: append the clause to queries carrying QUERY_VAR.
	 *
	 * $query is optional even though core always passes it. This filter is
	 * registered globally, so it runs for every query on the site including the
	 * front end, and plugins do fire the hook themselves with only the WHERE
	 * string. WP_Hook hands the caller's arguments over unsliced whenever the
	 * callback accepts at least that many, so a second *required* parameter here
	 * would be a fatal ArgumentCountError on somebody else's one-argument call.
	 *
	 * @param string         $where Existing WHERE clause.
	 * @param \WP_Query|null $query The query being built, when the caller passes one.
	 * @return string
	 */
	public static function filter_posts_where( $where, $query = null ) {
		global $wpdb;

		if ( ! $query instanceof \WP_Query ) {
			return $where;
		}

		$post_type = $query->get( self::QUERY_VAR );

		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return $where;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( empty( $post_type_object ) ) {
			return $where;
		}

		list( $sql, $params ) = self::where_sql( $post_type_object, $wpdb->posts );

		if ( '' === $sql ) {
			return $where;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built by where_sql() from placeholder strings only; every value is bound through the $params array.
		return $where . $wpdb->prepare( $sql, $params );
	}
}
