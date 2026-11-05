<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Removes Health Check candidate data during personal data erasure.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class PrivacyEraser {

	/**
	 * Memoised result of `candidates_table_exists()`. Cached on the
	 * instance after the first probe so a multi-subscription erasure
	 * batch only issues one SHOW TABLES query regardless of how many
	 * subscription ids it iterates.
	 *
	 * Cache lives on the instance, not statically — `WCS_Privacy`
	 * keeps a single eraser instance for the lifetime of the request,
	 * so per-instance caching is request-scoped without leaking across
	 * unit tests that construct fresh instances per case.
	 *
	 * @var bool|null
	 */
	private $candidates_table_exists_cache = null;

	/**
	 * Erases Health Check candidate rows linked to a subscription.
	 *
	 * @param \WC_Subscription $subscription Subscription being anonymized.
	 */
	public function erase_for_subscription( $subscription ): void {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$subscription_id = (int) $subscription->get_id();
		if ( 0 >= $subscription_id ) {
			return;
		}

		$this->delete_candidates_for_subscriptions( array( $subscription_id ) );
	}

	/**
	 * Erases Health Check candidate rows linked to a customer's subscriptions.
	 *
	 * @param string $email_address Email address for the erasure request.
	 * @param int    $page          Eraser page number. Unused; this eraser completes in one pass.
	 *
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public function erase_by_email( string $email_address, int $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', $email_address );
		if ( ! $user instanceof \WP_User ) {
			return $this->get_done_response();
		}

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return $this->get_done_response(
				array(
					__( 'WooCommerce Subscriptions is not available.', 'woocommerce-subscriptions' ),
				)
			);
		}

		// `subscriptions_per_page => -1` is intentional: the query is
		// scoped to a single user_id, which bounds the result in
		// practice — even a B2B organisation account with a large
		// subscription history is small relative to the store-wide
		// count, and WP's GDPR-erasure runner invokes this eraser
		// once per user. Paginating would add complexity for marginal
		// memory benefit and forfeit the single-pass guarantee (every
		// id collected in one call → no risk of a partial erase if
		// the user record is deleted mid-pagination).
		$subscriptions = wcs_get_subscriptions(
			array(
				'customer_id'            => $user->ID,
				'subscriptions_per_page' => -1,
				'subscription_status'    => 'any',
			)
		);

		$subscription_ids = array();
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription instanceof \WC_Subscription ) {
				$subscription_ids[] = (int) $subscription->get_id();
			}
		}

		$subscription_ids = array_values( array_unique( array_filter( $subscription_ids ) ) );
		if ( empty( $subscription_ids ) ) {
			return $this->get_done_response();
		}

		return $this->get_done_response( array(), $this->delete_candidates_for_subscriptions( $subscription_ids ) );
	}

	/**
	 * Deletes candidate rows for the provided subscription IDs.
	 *
	 * Short-circuits when the candidates table doesn't exist — that
	 * happens on stores where the Health Check tool is currently
	 * disabled (the table is created lazily by `Bootstrap::register()`
	 * gated on `is_enabled()`). Without the guard, every GDPR erasure
	 * on a tool-disabled store fires a DELETE against a missing table,
	 * leaving "Table doesn't exist" in `$wpdb->last_error` and the
	 * erasure log under WP_DEBUG.
	 *
	 * The check runs through `candidates_table_exists()` which caches
	 * the boolean on the instance — `WCS_Privacy` keeps a single
	 * eraser instance for the lifetime of the request, so per-instance
	 * caching collapses the SHOW TABLES probe to one query no matter
	 * how many subscriptions the erasure batch touches.
	 *
	 * @param array<int, int> $subscription_ids Subscription IDs.
	 *
	 * @return int Number of rows deleted.
	 */
	private function delete_candidates_for_subscriptions( array $subscription_ids ): int {
		global $wpdb;

		if ( ! $this->candidates_table_exists() ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $subscription_ids ), '%d' ) );
		$deleted      = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and placeholder list are generated internally.
				"DELETE FROM {$wpdb->prefix}wcs_health_check_candidates WHERE subscription_id IN ({$placeholders})",
				...$subscription_ids
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Probe whether the candidates table exists. Memoised on the
	 * instance via `$candidates_table_exists_cache`.
	 *
	 * @return bool
	 */
	private function candidates_table_exists(): bool {
		if ( null !== $this->candidates_table_exists_cache ) {
			return $this->candidates_table_exists_cache;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wcs_health_check_candidates';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->candidates_table_exists_cache = ( $found === $table );

		return $this->candidates_table_exists_cache;
	}

	/**
	 * Builds a standard WordPress eraser response.
	 *
	 * @param array<int, string> $messages      Eraser messages.
	 * @param int                $items_removed Number of items removed.
	 *
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	private function get_done_response( array $messages = array(), int $items_removed = 0 ): array {
		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
