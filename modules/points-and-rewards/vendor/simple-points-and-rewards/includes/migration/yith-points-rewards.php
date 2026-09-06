<?php
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Get user data from YITH WooCommerce Points and Rewards.
 *
 * @param int $user_id User ID.
 * @return array|false
 */
function spar_migrate_yith_points_rewards_get_user_data( $user_id ) {

    // Validate user ID
    if ( empty( $user_id ) || $user_id <= 0 ) {
        return false;
    }
    
    // Get WordPress user
    $wp_user = get_userdata( $user_id );
    if ( ! $wp_user ) {
        return false;
    }

	global $wpdb;

	$balance = get_user_meta( $user_id, '_ywpar_user_total_points', true );

	$table_name   = $wpdb->prefix . 'yith_ywpar_points_log';
	$total_earned = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount) FROM %i WHERE user_id = %d AND amount > 0', $table_name, $user_id ) );
	if ( null === $total_earned ) {
		$total_earned = $balance;
	}

	$birthday = get_user_meta( $user_id, 'yith_birthday', true );

	return array(
		'available_points'    => $balance,
		'total_earned_points' => $total_earned,
		'birthday'            => $birthday,
	);
}
