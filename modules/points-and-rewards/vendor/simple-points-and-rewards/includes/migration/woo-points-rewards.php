<?php
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Get WooCommerce Points and Rewards user data for migration
 * 
 * @param int $user_id WordPress user ID
 * @return array|false Array of user data or false if user not found
 */
function spar_migrate_wc_points_rewards_get_user_data( $user_id ) {
    global $wpdb;

    // Validate user ID
    if ( empty( $user_id ) || $user_id <= 0 ) {
        return false;
    }
    
    // Get WordPress user
    $wp_user = get_userdata( $user_id );
    if ( ! $wp_user ) {
        return false;
    }
    
    // 1. Get Available Points
    // WC Points and Rewards stores the current balance in user meta.
    // Use null as a sentinel so that a retrieved value of 0 (a legitimate
    // zero balance) is never confused with "balance not found".
    $available_points = null;
    if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
        $woo_balance = WC_Points_Rewards_Manager::get_users_points( $user_id );
        if ( '' !== $woo_balance && null !== $woo_balance ) {
            $available_points = (int) $woo_balance;
        }
    } else {
        // Fallback to meta if class is somehow missing.
        $raw_meta = get_user_meta( $user_id, '_wc_points_balance', true );
        if ( '' !== $raw_meta ) {
            $available_points = (int) $raw_meta;
        }
    }
    
    // 2. Calculate Total Earned and Used Points
    // We need to query the log table to get accurate totals
    // Table name is usually wp_wc_points_rewards_user_points
    $table_name = $wpdb->prefix . 'wc_points_rewards_user_points';
    
    $total_earned = 0;
    $total_used = 0;

    // Check if table exists before querying to prevent errors.
    if ( spar_table_exists( $table_name ) ) {

        // Sum positive points for total earned.
		$total_earned = $wpdb->get_var( $wpdb->prepare(
			'SELECT SUM(points) FROM %i WHERE user_id = %d AND points > 0',
			$table_name,
            $user_id
		) );

        // Sum negative points for total used (result will be negative, so we abs it later).
		$total_used = $wpdb->get_var( $wpdb->prepare(
			'SELECT SUM(points) FROM %i WHERE user_id = %d AND points < 0',
			$table_name,
            $user_id
		) );
    }

    // 3. Get Birthday
    $birthday_date = '';
    $possible_birthday_keys = [ 'billing_birth_date', 'date_of_birth', 'birthday' ];
    foreach ( $possible_birthday_keys as $key ) {
        $val = get_user_meta( $user_id, $key, true );
        if ( ! empty( $val ) ) {
            // Attempt to format to Y-m-d
            $timestamp = strtotime( $val );
            if ( $timestamp ) {
                $birthday_date = date( 'Y-m-d', $timestamp );
                break;
            }
        }
    }

    // Normalize totals
    $total_earned = ! empty( $total_earned ) ? (int) $total_earned : 0;
    $total_used   = ! empty( $total_used ) ? abs( (int) $total_used ) : 0;

    // If the balance could not be determined at all (class unavailable and no
    // meta row exists), fall back to reconstructing from the log table so that
    // genuinely missing data is still carried over.  A retrieved value of 0
    // is a valid zero balance and must NOT be overwritten here.
    if ( null === $available_points && ( $total_earned - $total_used ) > 0 ) {
        $available_points = max( 0, $total_earned - $total_used );
    }

    if ( null === $available_points ) {
        $available_points = 0;
    }

    // Return user data array
    return [
        'user_id'             => $user_id,
        'user_email'          => $wp_user->user_email,
        'available_points'    => $available_points,
        'total_earned_points' => $total_earned,
        'total_used_points'   => $total_used,
        'birthday'            => $birthday_date,
        'level_id'            => 0,
        'refer_code'          => '',
        'is_banned_user'      => 0,
    ];
}