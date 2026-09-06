<?php
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Get WP Swings Points and Rewards user data
 * 
 * @param int $user_id WordPress user ID
 * @return array|false Array of user data or false if user not found
 */
function spar_migrate_wpswings_points_rewards_get_user_data( $user_id ) {
    // Validate user ID
    if ( empty( $user_id ) || $user_id <= 0 ) {
        return false;
    }
    
    // Get WordPress user
    $wp_user = get_userdata( $user_id );
    if ( ! $wp_user ) {
        return false;
    }
    
    $user_email = $wp_user->user_email;

    // 1. Available Points
    $available_points = (int) get_user_meta( $user_id, 'wps_wpr_points', true );

    // 2. Total Used Points
    // Confirmed in class-points-rewards-for-woocommerce-public.php line 2573
    $total_used_points = (int) get_user_meta( $user_id, 'wps_wpr_redeemed_points', true );

    // 3. Total Earned Points
    // Confirmed in class-points-rewards-for-woocommerce-public.php line 2088
    // 'wps_wpr_overall__accumulated_points' tracks lifetime points for badges.
    $accumulated_points = (int) get_user_meta( $user_id, 'wps_wpr_overall__accumulated_points', true );
    
    // Fallback: If accumulated points meta is missing or less than current+used, calculate it manually.
    $calculated_total = $available_points + $total_used_points;
    $total_earned_points = max( $accumulated_points, $calculated_total );

    // 4. Membership Level
    $level_id = get_user_meta( $user_id, 'membership_level', true );
    
    // 5. Banned Status
    // This plugin often uses 'on' for the checkbox value in admin settings.
    $is_restricted = get_user_meta( $user_id, 'wps_wpr_restrict_user', true );
    $is_banned_user = ( ! empty( $is_restricted ) && ( 'on' === $is_restricted || 'yes' === $is_restricted ) ) ? 1 : 0;

    // 6. Referral Code
    $refer_code = get_user_meta( $user_id, 'wps_points_referral', true );

    // 7. Birthday
    $birthday = get_user_meta( $user_id, '_my_bday', true );

    // 8. Generated Coupons (Active coupons created by redeeming points)
    // Note: Based on code review, this plugin generates standard WC Coupons dynamically or stores them in session/logs, 
    // but 'wps_wpr_user_log' or 'points_details' contains the history.
    // We will retrieve the raw log array if needed, but for migration, we usually just need the points balance.
    $points_log = get_user_meta( $user_id, 'points_details', true );

    // Return user data array
    return [
        'user_id'             => $user_id,
        'user_email'          => $user_email,
        'available_points'    => $available_points,
        'total_earned_points' => $total_earned_points,
        'total_used_points'   => $total_used_points,
        'birthday'            => $birthday ? $birthday : '',
        'level_id'            => $level_id ? $level_id : 0,
        'refer_code'          => $refer_code ? (string) $refer_code : '',
        'is_banned_user' => $is_banned_user,
    ];
}