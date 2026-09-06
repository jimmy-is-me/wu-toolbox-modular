<?php
/**
 * Admin analytics helpers
 *
 * Provides lightweight aggregate statistics for display in the admin header.
 *
 * @package Simple Points and Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a snapshot of key analytics values for admin UI.
 *
 * Returns array keys:
 * - earned              (int) Lifetime points earned (sum of all positive awards)
 * - redeemed            (int) Lifetime points redeemed
 * - net                 (int) Current total balance across all users
 * - in_circulation      (int) Same as net
 * - users_with_points   (int) Users who have ever earned points (> 0 lifetime)
 * - active_customers_30 (int) Distinct users with any points activity in last 30 days
 *
 *
 * Filters:
 * - spar_admin_analytics_snapshot_cache_ttl (int) TTL in seconds; default 300 (5 min)
 * - spar_admin_analytics_snapshot           (array) Final snapshot array
 */
function spar_admin_analytics_cache_key() {
	return 'spar_admin_analytics_snapshot_v4';
}

function spar_admin_analytics_build_lock_key() {
	return 'spar_admin_analytics_build_lock';
}

function spar_admin_analytics_stale_flag_key() {
	return 'spar_admin_analytics_stale_flag';
}

function spar_get_admin_analytics_snapshot() {
	// Static cache: free on any repeated call within the same PHP request.
	static $request_cache = null;
	if ( null !== $request_cache ) {
		return $request_cache;
	}

	$cache_key = spar_admin_analytics_cache_key();
	$lock_key  = spar_admin_analytics_build_lock_key();
	$stale_key = spar_admin_analytics_stale_flag_key();
	$ttl       = (int) apply_filters( 'spar_admin_analytics_snapshot_cache_ttl', 300 );
	$lock_ttl  = (int) apply_filters( 'spar_admin_analytics_build_lock_ttl', 20 );

	// Check the stale flag + transient together. Both go through the object cache on
	// sites that have one (Memcached/Redis), so this is a single fast lookup.
	$is_stale = (bool) get_transient( $stale_key );
	$cached   = get_transient( $cache_key );
	if ( is_array( $cached ) && ! $is_stale ) {
		$request_cache = $cached;
		return $request_cache;
	}

	global $wpdb;

	$logs_table   = $wpdb->prefix . 'spar_points_logs';
	$usermeta_tbl = $wpdb->usermeta;

	// --- Table-existence check (cached in object cache, not re-run on every page load) ---
	$exists_cache_key  = 'spar_logs_table_exists';
	$logs_table_exists = wp_cache_get( $exists_cache_key, 'spar' );
	if ( false === $logs_table_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table ) ? 'yes' : 'no';
		wp_cache_set( $exists_cache_key, $logs_table_exists, 'spar', 300 );
	}
	$logs_table_exists = ( 'yes' === $logs_table_exists );

	// Acquire a short build lock to prevent query stampedes on cache miss.
	// If another process holds the lock and we still have a stale snapshot, return it.
	$acquired_lock = false;
	if ( $logs_table_exists ) {
		if ( get_transient( $lock_key ) ) {
			if ( is_array( $cached ) ) {
				$request_cache = $cached;
				return $request_cache;
			}
			// No stale snapshot available — fall through and compute without touching the lock.
		} else {
			set_transient( $lock_key, 1, max( 5, $lock_ttl ) );
			$acquired_lock = true;
		}
	}

	$earned_raw   = 0;
	$redeemed_raw = 0;
	$active_30    = 0;

	if ( $logs_table_exists ) {
		// Single-pass: earned + redeemed totals AND active-30 count in one query.
		$since_str = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN (points > 0 OR type = 'add')    THEN points    ELSE 0 END), 0) AS earned,
					COALESCE(SUM(CASE WHEN (points < 0 OR type = 'remove') THEN ABS(points) ELSE 0 END), 0) AS redeemed,
					COUNT(DISTINCT CASE WHEN date >= %s THEN user_id END) AS active_30
				FROM %i",
				$since_str,
				$logs_table
			),
			ARRAY_A
		);
		$earned_raw   = isset( $row['earned'] )    ? (int) $row['earned']    : 0;
		$redeemed_raw = isset( $row['redeemed'] )  ? (int) $row['redeemed']  : 0;
		$active_30    = isset( $row['active_30'] ) ? (int) $row['active_30'] : 0;
	}

	// Single-pass usermeta query: in-circulation balance + users-with-earned-points.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$um_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				COALESCE(SUM(CASE WHEN meta_key = %s THEN CAST(meta_value AS SIGNED) ELSE 0 END), 0) AS in_circ,
				SUM(CASE WHEN meta_key = %s AND CAST(meta_value AS SIGNED) > 0 THEN 1 ELSE 0 END) AS users_with_earned
			FROM %i
			WHERE meta_key IN (%s, %s)",
			'_spar_points',
			'_spar_total_earned_points',
			$usermeta_tbl,
			'_spar_points',
			'_spar_total_earned_points'
		),
		ARRAY_A
	);
	$in_circ              = isset( $um_row['in_circ'] )         ? (int) $um_row['in_circ']         : 0;
	$users_with_earned    = isset( $um_row['users_with_earned'] ) ? (int) $um_row['users_with_earned'] : 0;

	$snapshot = array(
		'earned'              => max( 0, $earned_raw ),
		'redeemed'            => max( 0, $redeemed_raw ),
		'net'                 => max( 0, $in_circ ),
		'in_circulation'      => max( 0, $in_circ ),
		'users_with_points'   => max( 0, $users_with_earned ),
		'active_customers_30' => max( 0, $active_30 ),
	);

	$snapshot = apply_filters( 'spar_admin_analytics_snapshot', $snapshot );
	set_transient( $cache_key, $snapshot, max( 0, $ttl ) );
	delete_transient( $stale_key );
	if ( $acquired_lock ) {
		delete_transient( $lock_key );
	}

	$request_cache = $snapshot;
	return $request_cache;
}

/**
 * Get the store's outstanding points liability.
 *
 * Converts the current points in circulation (sum of all user balances) into a
 * monetary value using the configured points redemption rate for the store currency.
 *
 * Returns array keys:
 * - points   (int)    Points currently in circulation
 * - amount   (float)  Monetary value of those points at the redemption rate
 * - currency (string) Store currency code
 * - rate     (array)  [ 'points' => float, 'amount' => float ] conversion used
 * - has_rate (bool)   Whether a usable redemption rate is configured
 */
function spar_get_points_liability_snapshot() {
	$snapshot = spar_get_admin_analytics_snapshot();
	$points   = isset( $snapshot['in_circulation'] ) ? (int) $snapshot['in_circulation'] : 0;
	$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

	$rate = function_exists( 'spar_get_redeem_rate_for_currency' )
		? spar_get_redeem_rate_for_currency( $currency )
		: array( 'points' => 0.0, 'amount' => 0.0 );

	$has_rate = ( (float) $rate['points'] > 0 && (float) $rate['amount'] > 0 );
	$amount   = ( $has_rate && function_exists( 'spar_calculate_redemption_amount' ) )
		? spar_calculate_redemption_amount( $points, $currency )
		: 0.0;

	$liability = array(
		'points'   => $points,
		'amount'   => (float) $amount,
		'currency' => $currency,
		'rate'     => $rate,
		'has_rate' => $has_rate,
	);

	/**
	 * Filter the computed points liability snapshot.
	 *
	 * @param array $liability Liability data (points, amount, currency, rate, has_rate).
	 */
	return apply_filters( 'spar_points_liability_snapshot', $liability );
}

/**
 * Get a snapshot of outstanding (claimed but unused) reward voucher coupons.
 *
 * Returns array keys:
 * - count       (int)   Published reward-voucher coupons that are unused and unexpired
 * - fixed_value (float) Combined value of the fixed-amount vouchers among them
 */
function spar_get_outstanding_vouchers_snapshot() {
	$cache_key = 'spar_outstanding_vouchers_snapshot';
	$cached    = wp_cache_get( $cache_key, 'spar' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate, cached below.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS cnt,
				COALESCE(SUM(CASE WHEN dt.meta_value IN ('fixed_cart', 'fixed_product') THEN CAST(amt.meta_value AS DECIMAL(18,2)) ELSE 0 END), 0) AS fixed_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} rv ON rv.post_id = p.ID AND rv.meta_key = %s AND rv.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} amt ON amt.post_id = p.ID AND amt.meta_key = 'coupon_amount'
			LEFT JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = 'discount_type'
			LEFT JOIN {$wpdb->postmeta} uc ON uc.post_id = p.ID AND uc.meta_key = 'usage_count'
			LEFT JOIN {$wpdb->postmeta} ul ON ul.post_id = p.ID AND ul.meta_key = 'usage_limit'
			LEFT JOIN {$wpdb->postmeta} de ON de.post_id = p.ID AND de.meta_key = 'date_expires'
			WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'
				AND ( ul.meta_value IS NULL OR ul.meta_value = '' OR CAST(ul.meta_value AS UNSIGNED) = 0 OR COALESCE(CAST(uc.meta_value AS UNSIGNED), 0) < CAST(ul.meta_value AS UNSIGNED) )
				AND ( de.meta_value IS NULL OR de.meta_value = '' OR CAST(de.meta_value AS UNSIGNED) >= %d )",
			'_spar_reward_voucher',
			'1',
			time()
		),
		ARRAY_A
	);

	$snapshot = array(
		'count'       => isset( $row['cnt'] ) ? (int) $row['cnt'] : 0,
		'fixed_value' => isset( $row['fixed_value'] ) ? (float) $row['fixed_value'] : 0.0,
	);

	wp_cache_set( $cache_key, $snapshot, 'spar', 300 );

	return $snapshot;
}

/**
 * Helper to format large numbers compactly (e.g., 12.3k, 4.5M) while keeping i18n
 * for decimals. Falls back to number_format_i18n() for small values.
 *
 * @param int|float $number
 * @return string
 */
function spar_format_compact_number( $number ) {
	$number = (float) $number;
	if ( $number >= 1000000 ) {
		/* translators: %s: formatted number in millions (e.g. 4.5). */
		return sprintf( _x( '%sM', 'millions short format', 'simple-points-and-rewards' ), number_format_i18n( $number / 1000000, 1 ) );
	}
	if ( $number >= 1000 ) {
		/* translators: %s: formatted number in thousands (e.g. 12.3). */
		return sprintf( _x( '%sk', 'thousands short format', 'simple-points-and-rewards' ), number_format_i18n( $number / 1000, 1 ) );
	}
	return number_format_i18n( $number );
}

/**
 * Invalidate cached analytics snapshot whenever points change.
 */
function spar_admin_analytics_invalidate() {
	// Mark snapshot stale; keep existing until next rebuild to avoid stampede.
	set_transient( spar_admin_analytics_stale_flag_key(), 1, 120 );
}
add_action( 'spar_after_points_update', 'spar_admin_analytics_invalidate' );
add_action( 'spar_points_added', 'spar_admin_analytics_invalidate' );
add_action( 'spar_points_removed', 'spar_admin_analytics_invalidate' );
