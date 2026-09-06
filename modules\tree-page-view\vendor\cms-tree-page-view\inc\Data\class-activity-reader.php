<?php
/**
 * Reads Simple History activity for the React Tree View screen.
 *
 * A thin wrapper over Simple History's public Log_Query API. Simple History is an
 * OPTIONAL dependency: every method is safe to call when it is not installed —
 * is_available() returns false and the feed methods return an empty array.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple History activity reader (optional dependency, degrades gracefully).
 */
class Activity_Reader {

	/**
	 * Whether Simple History's query API is present.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( '\Simple_History\Log_Query' );
	}

	/**
	 * Global content-activity feed: recent post-logger events.
	 *
	 * @param int    $per_page  Max events.
	 * @param string $post_type Post type to scope the feed to. Empty for every post type.
	 * @return list<array<string,mixed>>
	 */
	public static function recent( int $per_page, string $post_type = '' ): array {
		$args = array(
			'posts_per_page' => $per_page,
			// SimplePostLogger covers core post events (edit/publish/trash/…);
			// CMS_Tree_Page_View_Logger adds this plugin's own tree moves,
			// reparents, and adds. Without the second slug the global feed
			// silently dropped every tree-originated event.
			'loggers'        => array( 'SimplePostLogger', 'CMS_Tree_Page_View_Logger' ),
		);
		if ( '' !== $post_type ) {
			$args['context_filters'] = array( 'post_type' => $post_type );
		}
		return self::run( $args );
	}

	/**
	 * Per-page feed: recent events for one post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $per_page Max events.
	 * @return list<array<string,mixed>>
	 */
	public static function for_post( int $post_id, int $per_page ): array {
		return self::run(
			array(
				'posts_per_page'  => $per_page,
				'context_filters' => array( 'post_id' => $post_id ),
			)
		);
	}

	/**
	 * Run a Log_Query and normalize the rows. Returns [] when SH is unavailable
	 * or the query errors.
	 *
	 * @param array<string,mixed> $args Log_Query args.
	 * @return list<array<string,mixed>>
	 */
	private static function run( array $args ): array {
		if ( ! self::is_available() ) {
			return array();
		}

		// @phpstan-ignore-next-line (Simple_History\Log_Query is an optional dependency, guarded above)
		$result = ( new \Simple_History\Log_Query() )->query( $args );
		if ( is_wp_error( $result ) || empty( $result['log_rows'] ) ) {
			return array();
		}

		$items = array();
		foreach ( $result['log_rows'] as $row ) {
			$items[] = self::normalize( $row );
		}
		return $items;
	}

	/**
	 * Map one Simple History log row to the stable activity-item shape.
	 *
	 * @param object $row Simple History log row.
	 * @return array<string,mixed>
	 */
	private static function normalize( object $row ): array {
		$context = isset( $row->context ) ? (array) $row->context : array();

		$message = isset( $row->message ) ? (string) $row->message : '';
		if ( class_exists( '\Simple_History\Helpers' ) ) {
			// PHPStan recognizes the class_exists() guard above and doesn't flag this
			// optional-dependency call, so no static-analysis suppression is needed here.
			$message = \Simple_History\Helpers::interpolate( $message, $context, $row );
		}
		$message = wp_strip_all_tags( $message );

		$timestamp = isset( $row->date ) ? strtotime( $row->date . ' UTC' ) : false;
		$formatted = $timestamp
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			? sprintf( __( '%s ago', 'cms-tree-page-view' ), human_time_diff( $timestamp ) )
			: '';

		$user_id = isset( $context['_user_id'] ) ? (int) $context['_user_id'] : 0;
		$user    = __( 'System', 'cms-tree-page-view' );
		if ( $user_id ) {
			$user_obj = get_userdata( $user_id );
			if ( $user_obj ) {
				$user = $user_obj->display_name;
			}
		}

		$message_key = isset( $context['_message_key'] ) ? (string) $context['_message_key'] : '';

		return array(
			'id'            => isset( $row->id ) ? (int) $row->id : 0,
			'date'          => isset( $row->date ) ? (string) $row->date : '',
			'dateFormatted' => $formatted,
			'message'       => $message,
			'user'          => $user,
			'postId'        => isset( $context['post_id'] ) ? (int) $context['post_id'] : 0,
			'type'          => self::event_type( $message_key ),
		);
	}

	/**
	 * Map a Simple History message key to a coarse event type, used only to
	 * colour the activity feed's timeline dot (move = blue, add = green,
	 * edit = amber, trash = red). Keyed on the semantic message key rather than
	 * the interpolated, translated message text, so it holds across locales.
	 * Unknown keys fall back to 'other' (a neutral dot), so events from loggers
	 * this plugin doesn't specifically know about still render.
	 *
	 * @param string $message_key Simple History message key (context '_message_key').
	 * @return string One of 'move', 'add', 'edit', 'trash', 'other'.
	 */
	private static function event_type( string $message_key ): string {
		switch ( $message_key ) {
			case 'page_moved':
			case 'page_type_changed':
				return 'move';

			case 'page_added':
			case 'pages_added':
			case 'post_created':
				return 'add';

			case 'post_updated':
			case 'post_restored':
				return 'edit';

			case 'post_trashed':
			case 'post_deleted':
				return 'trash';

			default:
				return 'other';
		}
	}
}
