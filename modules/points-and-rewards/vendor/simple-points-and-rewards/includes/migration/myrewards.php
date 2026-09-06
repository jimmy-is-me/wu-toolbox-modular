<?php
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Get MyRewards (WooRewards) user data including points and birthday.
 *
 * @param int $user_id
 * @return array|false
 */
function spar_migrate_myrewards_get_user_data( $user_id ) {
    if ( empty( $user_id ) || $user_id <= 0 ) {
        return false;
    }

    $wp_user = get_userdata( $user_id );
    if ( ! $wp_user ) {
        return false;
    }

    global $wpdb;

    $user_email = $wp_user->user_email;

    // 1) Preferred: sum points from all Pools (handles stacks, expiry, etc.)
    $available_points = 0;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Integrating with the WooRewards plugin filter exactly as provided.
    $pools = apply_filters( 'lws_woorewards_get_pools_by_args', false, array(), $user_id );
    $pool_list = array();

    if ($pools) {
        // Try to normalize Pools collection
        if (is_object($pools) && method_exists($pools, 'asArray')) {
            $pool_list = $pools->asArray();
        } elseif (is_array($pools)) {
            $pool_list = $pools;
        } elseif (is_object($pools) && method_exists($pools, 'toArray')) {
            $pool_list = $pools->toArray();
        }
    }

    if (!empty($pool_list)) {
        foreach ($pool_list as $pool) {
            if (is_object($pool) && method_exists($pool, 'getPoints')) {
                $available_points += (int)$pool->getPoints($user_id);
            }
        }
    }

    // 2) Fallback: compute current balance from historic (earned - used)
    if ($available_points === 0) {
        $thistoric = $wpdb->base_prefix . 'lws_wr_historic';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $thistoric ) ) === $thistoric );
        if ( $table_exists ) {
            $balance = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(points_moved),0) AS balance
                     FROM %i
                     WHERE user_id = %d",
                    $thistoric,
                    $user_id
                )
            );
            if ($balance !== null) {
                $available_points = (int)$balance;
            }
        }
    }

    // Totals from historic table
    $total_earned_points = 0;
    $total_used_points   = 0;

    $thistoric = $wpdb->base_prefix . 'lws_wr_historic';
    $table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $thistoric ) ) === $thistoric );

    if ( $table_exists ) {
        $sums = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN points_moved > 0 THEN points_moved ELSE 0 END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN points_moved < 0 THEN -points_moved ELSE 0 END), 0) AS used
                 FROM %i
                 WHERE user_id = %d",
                $thistoric,
                $user_id
            ),
            ARRAY_A
        );

        if ( $sums ) {
            $total_earned_points = (int) $sums['earned'];
            $total_used_points   = (int) $sums['used'];
        }
    }

    // Birthday: try common meta keys, normalize to Y-m-d if parseable
    $birthday_meta_keys = array(
        'lws_woorewards_birthday',
        'lws_woorewards_birthday_date',
        'lws_wr_birthdate',
        'wr_birthday',
        'birthday',
    );
    $birthday = '';
    foreach ( $birthday_meta_keys as $k ) {
        $v = get_user_meta( $user_id, $k, true );
        if ( ! empty( $v ) ) {
            $ts = strtotime( (string) $v );
            $birthday = $ts ? gmdate( 'Y-m-d', $ts ) : (string) $v;
            break;
        }
    }

    return array(
        'user_id'             => $user_id,
        'user_email'          => $user_email,
        'available_points'    => (int)$available_points,
        'total_earned_points' => $total_earned_points,
        'total_used_points'   => $total_used_points,
        'birthday'            => $birthday,
        'level_id'            => 0,
        'refer_code'          => '',
        'is_banned_user'      => 0,
    );
}