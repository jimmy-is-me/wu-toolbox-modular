<?php
/**
 * Migration tools for importing points from other plugins.
 *
 * @package SimplePointsAndRewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Include specific migration helpers.
$spar_migration_autoload_files = array(
	SPAR_PLUGIN_PATH . 'includes/migration/wployalty.php',
	SPAR_PLUGIN_PATH . 'includes/migration/myrewards.php',
	SPAR_PLUGIN_PATH . 'includes/migration/woo-points-rewards.php',
	SPAR_PLUGIN_PATH . 'includes/migration/points-rewards-wpswings.php',
	SPAR_PLUGIN_PATH . 'includes/migration/yith-points-rewards.php',
);

foreach ( $spar_migration_autoload_files as $spar_migration_file ) {
	if ( file_exists( $spar_migration_file ) ) {
		require_once $spar_migration_file;
	}
}

/**
 * Return the supported migration source plugins.
 *
 * @return array
 */
function spar_migration_get_plugin_definitions() {
	$plugins = array(
		'points_rewards_wpswings' => array(
			'label'              => esc_html__( 'Points and Rewards for WooCommerce by WP Swings', 'simple-points-and-rewards' ),
			'plugin_files'       => array( 'points-and-rewards-for-woocommerce/points-reward.php' ),
			'is_active_callback' => function() {
				return class_exists( 'Points_Rewards_For_WooCommerce' );
			},
		),
		'woo_points_rewards' => array(
			'label'              => esc_html__( 'WooCommerce Points and Rewards', 'simple-points-and-rewards' ),
			'plugin_files'       => array( 'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php' ),
			'is_active_callback' => function() {
				return class_exists( 'WC_Points_Rewards_Manager' );
			},
		),
		'wployalty' => array(
			'label'                => esc_html__( 'WPLoyalty', 'simple-points-and-rewards' ),
			'plugin_files'         => array(
				'wployalty/wp-loyalty-rules-lite.php',
			),
			'is_active_callback'   => function() {
				return class_exists( '\Wlr\App\Router' );
			},
		),
		'myrewards' => array(
			'label'              => esc_html__( 'MyRewards (Long Watch Studio)', 'simple-points-and-rewards' ),
			'plugin_files'       => array( 'woorewards/woorewards.php' ),
			'is_active_callback' => function() {
				return ( class_exists( '\LWS_WooRewards' ) );
			},
		),
		'yith_points_rewards' => array(
			'label'              => esc_html__( 'YITH WooCommerce Points and Rewards', 'simple-points-and-rewards' ),
			'plugin_files'       => array( 'yith-woocommerce-points-and-rewards/init.php' ),
			'is_active_callback' => function() {
				return defined( 'YITH_YWPAR_VERSION' ) || class_exists( 'YITH_WC_Points_Rewards' );
			},
		),
	);

	/**
	 * Allow custom integrations or overrides for migration plugin definitions.
	 *
	 * @param array $plugins Plugin definitions.
	 */
	return apply_filters( 'spar_migration_plugin_definitions', $plugins );
}

/**
 * Return the plugin keys that support migrating user status (active/banned).
 *
 * @return array
 */
function spar_migration_get_user_status_supported_plugins() {
	$supported = array( 'points_rewards_wpswings', 'wployalty' );

	return apply_filters( 'spar_migration_user_status_plugins', $supported );
}

/**
 * Determine if a plugin supports migrating user status.
 *
 * @param string $plugin_key Plugin key.
 * @return bool
 */
function spar_migration_plugin_supports_user_status( $plugin_key ) {
	if ( ! $plugin_key ) {
		return false;
	}

	$supported = spar_migration_get_user_status_supported_plugins();

	return in_array( $plugin_key, $supported, true );
}

/**
 * Retrieve an array of supported plugins that are currently active.
 *
 * @return array
 */
function spar_migration_get_active_plugins() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$definitions = spar_migration_get_plugin_definitions();
	$active      = array();

	if ( empty( $definitions ) || ! is_array( $definitions ) ) {
		$cache = array();
		return $cache;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	foreach ( $definitions as $key => $data ) {
		$plugin_files = array();
		if ( ! empty( $data['plugin_files'] ) && is_array( $data['plugin_files'] ) ) {
			$plugin_files = array_filter( array_map( 'strval', $data['plugin_files'] ) );
		}

		if ( empty( $plugin_files ) && ! empty( $data['plugin_file'] ) ) {
			$plugin_files[] = (string) $data['plugin_file'];
		}

		$detection = isset( $data['is_active_callback'] ) ? $data['is_active_callback'] : null;

		$detected = false;

		// Check plugin files first
		if ( ! empty( $plugin_files ) ) {
			foreach ( $plugin_files as $plugin_file ) {
				if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
					$detected = true;
					break;
				}
			}
		}

		// If not detected via plugin files, try the callback
		if ( ! $detected && is_callable( $detection ) ) {
			$detected = (bool) call_user_func( $detection, $data, $key );
		}

		/**
		 * Filter detection per plugin.
		 *
		 * @param bool  $detected Whether detected.
		 * @param array $data     Plugin definition data.
		 * @param string $key     Plugin key.
		 */
		$detected = (bool) apply_filters( 'spar_migration_plugin_detected', $detected, $data, $key );

		if ( $detected ) {
			$active[ $key ] = $data;
		}
	}

	$cache = $active;

	return $active;
}

/**
 * Determine if any supported plugins are active.
 *
 * @return bool
 */
function spar_migration_has_supported_plugins() {
	$active = spar_migration_get_active_plugins();

	return ! empty( $active );
}

add_action( 'admin_menu', 'spar_register_migration_admin_menu', 60 );
/**
 * Register the migration submenu when a supported plugin is active.
 */
function spar_register_migration_admin_menu() {
	if ( ! spar_migration_has_supported_plugins() ) {
		return;
	}

	add_submenu_page(
		'spar-settings',
		esc_html__( 'Migration', 'simple-points-and-rewards' ),
		esc_html__( 'Migration', 'simple-points-and-rewards' ),
		'manage_woocommerce',
		'spar-migration',
		'spar_render_migration_admin_page',
		55
	);
}

/**
 * Render the migration admin page.
 */
function spar_render_migration_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-points-and-rewards' ) );
	}

	$active_plugins = spar_migration_get_active_plugins();

	echo '<div class="wrap spar-admin-page spar-migration-page">';

	if ( function_exists( 'spar_render_admin_header' ) ) {
		spar_render_admin_header( esc_html__( 'Migration', 'simple-points-and-rewards' ) );
	} else {
		echo '<h1>' . esc_html__( 'Migration', 'simple-points-and-rewards' ) . '</h1>';
	}

	if ( empty( $active_plugins ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'No supported plugins are currently active.', 'simple-points-and-rewards' ) . '</p></div>';
		echo '</div>';
		return;
	}

	echo '<div class="spar-admin-content">';
	echo '<form id="spar-migration-form" method="post">';
	wp_nonce_field( 'spar_migration_nonce', 'spar_migration_nonce_field' );

	echo '<table class="form-table"><tbody>';
	echo '<tr>';
	echo '<th scope="row"><label for="spar_migration_plugin">' . esc_html__( 'Source plugin', 'simple-points-and-rewards' ) . '</label></th>';
	echo '<td>';
	echo '<select id="spar_migration_plugin" name="spar_migration_plugin" class="regular-text">';
	echo '<option value="">' . esc_html__( 'Select a plugin…', 'simple-points-and-rewards' ) . '</option>';

	foreach ( $active_plugins as $key => $data ) {
		$label = isset( $data['label'] ) ? $data['label'] : $key;
		echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
	}

	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Choose the plugin you want to migrate points from.', 'simple-points-and-rewards' ) . '</p>';
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="spar_migration_mode">' . esc_html__( 'Migration type', 'simple-points-and-rewards' ) . '</label></th>';
	echo '<td>';
	echo '<select id="spar_migration_mode" name="spar_migration_mode" class="regular-text">';
	echo '<option value="override">' . esc_html__( 'Override current points with source points', 'simple-points-and-rewards' ) . '</option>';
	echo '<option value="add">' . esc_html__( 'Add source points to current points', 'simple-points-and-rewards' ) . '</option>';
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Choose whether to replace existing balances or add to them.', 'simple-points-and-rewards' ) . '</p>';
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row">' . esc_html__( 'Data to migrate', 'simple-points-and-rewards' ) . '</th>';
	echo '<td>';
	echo '<fieldset>';
	echo '<label><input type="checkbox" name="spar_migration_data[]" value="balance" checked="checked"> ' . esc_html__( 'Current points balance', 'simple-points-and-rewards' ) . '</label><br>';
	echo '<label><input type="checkbox" name="spar_migration_data[]" value="total_earned" checked="checked"> ' . esc_html__( 'Total points earned (lifetime)', 'simple-points-and-rewards' ) . '</label><br>';
	echo '<label><input type="checkbox" name="spar_migration_data[]" value="birthday" checked="checked"> ' . esc_html__( 'Birthday date', 'simple-points-and-rewards' ) . '</label><br>';
	echo '<label><input type="checkbox" name="spar_migration_data[]" value="referral_code" checked="checked"> ' . esc_html__( 'Referral code/coupon', 'simple-points-and-rewards' ) . '</label><br>';
	echo '<label class="spar-migration-field spar-migration-field--user-status" style="display:none;"><input type="checkbox" name="spar_migration_data[]" value="user_status" checked="checked"> ' . esc_html__( 'Active or banned status', 'simple-points-and-rewards' ) . '</label><br>';
	echo '</fieldset>';
	echo '</td>';
	echo '</tr>';

	echo '</tbody></table>';

    echo '<p>';
    echo esc_html__( 'This will update the selected data for all customers within the Simple Points and Rewards plugin. A backup is recommended before proceeding.', 'simple-points-and-rewards' );
    echo '</p>';

	echo '<p class="submit">';
	echo '<button type="submit" id="spar-migration-start" class="button button-primary">' . esc_html__( 'Start migration', 'simple-points-and-rewards' ) . '</button> ';
	echo '<span class="spinner" style="float:none;"></span>';
	echo '</p>';

	echo '</form>';
	echo '<div id="spar-migration-log" class="spar-migration-log" aria-live="polite" aria-atomic="false">';
	echo '<h2>' . esc_html__( 'Migration log', 'simple-points-and-rewards' ) . '</h2>';
	echo '<div class="spar-migration-log__entries"></div>';
	echo '</div>';
	echo '</div>'; // .spar-admin-content
	echo '</div>'; // .wrap
}

/**
 * Fetch points information from the selected plugin for a user.
 *
 * @param int    $user_id User ID.
 * @param string $plugin  Plugin key.
 * @return array {
 *     @type float|null  $balance       Current points balance.
 *     @type float|null  $total_earned  Lifetime points earned.
 *     @type string|null $birthday      Birthday date in Y-m-d format when available.
 *     @type string|null $referral_code Referral code string when available.
 *     @type int|null    $is_banned_user Whether the user is banned (1) or active (0).
 * }
 */
function spar_migration_get_external_points( $user_id, $plugin ) {
	$data = array(
		'balance'       => null,
		'total_earned'  => null,
		'birthday'      => null,
		'referral_code' => null,
		'is_banned_user' => null,
	);

	switch ( $plugin ) {
		case 'points_rewards_wpswings':
			$wps_data = function_exists( 'spar_migrate_wpswings_points_rewards_get_user_data' ) ? spar_migrate_wpswings_points_rewards_get_user_data( $user_id ) : false;
			if ( $wps_data ) {
				$data['balance']       = isset( $wps_data['available_points'] ) ? $wps_data['available_points'] : null;
				$data['total_earned']  = isset( $wps_data['total_earned_points'] ) ? $wps_data['total_earned_points'] : null;
				$data['birthday']      = isset( $wps_data['birthday'] ) ? $wps_data['birthday'] : null;
				$data['referral_code'] = isset( $wps_data['refer_code'] ) ? $wps_data['refer_code'] : null;
				$data['is_banned_user'] = isset( $wps_data['is_banned_user'] ) ? $wps_data['is_banned_user'] : null;
			}
			break;

		case 'woo_points_rewards':
			$wcpr_data = function_exists( 'spar_migrate_wc_points_rewards_get_user_data' ) ? spar_migrate_wc_points_rewards_get_user_data( $user_id ) : false;
			if ( $wcpr_data ) {
				$data['balance']       = isset( $wcpr_data['available_points'] ) ? $wcpr_data['available_points'] : null;
				$data['total_earned']  = isset( $wcpr_data['total_earned_points'] ) ? $wcpr_data['total_earned_points'] : null;
				$data['birthday']      = isset( $wcpr_data['birthday'] ) ? $wcpr_data['birthday'] : null;
				$data['is_banned_user'] = isset( $wcpr_data['is_banned_user'] ) ? $wcpr_data['is_banned_user'] : null;
			}
			break;

		case 'wployalty':
			$wployalty_data = function_exists( 'spar_migrate_wployalty_get_user_data' ) ? spar_migrate_wployalty_get_user_data( $user_id ) : false;
			if ( $wployalty_data ) {
				$data['balance']       = isset( $wployalty_data['available_points'] ) ? $wployalty_data['available_points'] : null;
				$data['total_earned']  = isset( $wployalty_data['total_earned_points'] ) ? $wployalty_data['total_earned_points'] : null;
				$data['birthday']      = isset( $wployalty_data['birthday'] ) ? $wployalty_data['birthday'] : null;
				$data['referral_code'] = isset( $wployalty_data['refer_code'] ) ? $wployalty_data['refer_code'] : null;
				$data['is_banned_user'] = isset( $wployalty_data['is_banned_user'] ) ? $wployalty_data['is_banned_user'] : null;
			}
			break;

		case 'myrewards':
			$myrewards_data = function_exists( 'spar_migrate_myrewards_get_user_data' ) ? spar_migrate_myrewards_get_user_data( $user_id ) : false;
			if ( $myrewards_data ) {
				$data['balance']       = isset( $myrewards_data['available_points'] ) ? $myrewards_data['available_points'] : null;
				$data['total_earned']  = isset( $myrewards_data['total_earned_points'] ) ? $myrewards_data['total_earned_points'] : null;
				$data['birthday']      = isset( $myrewards_data['birthday'] ) ? $myrewards_data['birthday'] : null;
				$data['referral_code'] = isset( $myrewards_data['refer_code'] ) ? $myrewards_data['refer_code'] : null;
				$data['is_banned_user'] = isset( $myrewards_data['is_banned_user'] ) ? $myrewards_data['is_banned_user'] : null;
			}
			break;

		case 'yith_points_rewards':
			$yith_data = function_exists( 'spar_migrate_yith_points_rewards_get_user_data' ) ? spar_migrate_yith_points_rewards_get_user_data( $user_id ) : false;
			if ( $yith_data ) {
				$data['balance']      = isset( $yith_data['available_points'] ) ? $yith_data['available_points'] : null;
				$data['total_earned'] = isset( $yith_data['total_earned_points'] ) ? $yith_data['total_earned_points'] : null;
				$data['birthday']     = isset( $yith_data['birthday'] ) ? $yith_data['birthday'] : null;
			}
			break;

		default:
			$data['balance']      = null;
			$data['total_earned'] = null;
	}

	/**
	 * Filter the raw points data retrieved from an external plugin before usage.
	 *
	 * @param array $data   Raw points data array.
	 * @param int   $user_id User ID.
	 * @param string $plugin Plugin key.
	 */
	$data = apply_filters( 'spar_migration_external_data', $data, $user_id, $plugin );

	// Back-compat filter for balance only adjustments.
	$data['balance'] = apply_filters( 'spar_migration_external_points', $data['balance'], $user_id, $plugin );

	foreach ( array( 'balance', 'total_earned' ) as $key ) {
		$value = isset( $data[ $key ] ) ? $data[ $key ] : null;
		if ( '' === $value || null === $value ) {
			$data[ $key ] = null;
			continue;
		}
		$data[ $key ] = is_numeric( $value ) ? (float) $value : null;
	}

	$birthday_value = isset( $data['birthday'] ) ? $data['birthday'] : null;
	if ( '' === $birthday_value || null === $birthday_value ) {
		$data['birthday'] = null;
	}

	$referral_value = isset( $data['referral_code'] ) ? $data['referral_code'] : null;
	if ( '' === $referral_value || null === $referral_value ) {
		$data['referral_code'] = null;
	} else {
		$referral_sanitized = trim( sanitize_text_field( (string) $referral_value ) );
		$data['referral_code'] = '' !== $referral_sanitized ? $referral_sanitized : null;
	}

	$ban_value = isset( $data['is_banned_user'] ) ? $data['is_banned_user'] : null;
	if ( '' === $ban_value || null === $ban_value ) {
		$data['is_banned_user'] = null;
	} else {
		$data['is_banned_user'] = ( (int) $ban_value > 0 ) ? 1 : 0;
	}

	return $data;
}

/**
 * Return the batch size for migration requests.
 *
 * @return int
 */
function spar_migration_get_batch_size() {
	$size = (int) apply_filters( 'spar_migration_batch_size', 10 );

	return $size > 0 ? $size : 2;
}

add_action( 'wp_ajax_spar_migration_process_batch', 'spar_migration_process_batch' );
/**
 * Process a migration batch via AJAX.
 */
function spar_migration_process_batch() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) ), 403 );
	}

	check_ajax_referer( 'spar_migration_nonce', 'nonce' );

	$plugin = isset( $_POST['plugin'] ) ? sanitize_key( wp_unslash( $_POST['plugin'] ) ) : '';
	$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'override';
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

	// Get selected data fields to migrate.
	$migrate_data = array();
	if ( isset( $_POST['migrate_data'] ) && is_array( $_POST['migrate_data'] ) ) {
		$allowed_fields = array( 'balance', 'total_earned', 'birthday', 'referral_code', 'user_status' );
		foreach ( $_POST['migrate_data'] as $field ) {
			$field = sanitize_key( $field );
			if ( in_array( $field, $allowed_fields, true ) ) {
				$migrate_data[] = $field;
			}
		}
	}

	// Default to all fields if none selected.
	if ( empty( $migrate_data ) ) {
		$migrate_data = array( 'balance', 'total_earned', 'birthday', 'referral_code', 'user_status' );
	}

	// Server-side enforcement: MyRewards does not support birthday or referral migration.
	if ( 'myrewards' === $plugin ) {
		$migrate_data = array_values( array_diff( $migrate_data, array( 'birthday', 'referral_code' ) ) );
	}

	// Server-side enforcement: YITH WooCommerce Points and Rewards does not support referral codes.
	if ( 'yith_points_rewards' === $plugin ) {
		$migrate_data = array_values( array_diff( $migrate_data, array( 'referral_code' ) ) );
	}

	// Server-side enforcement: WooCommerce Points and Rewards does not support referral codes.
	if ( 'woo_points_rewards' === $plugin ) {
		$migrate_data = array_values( array_diff( $migrate_data, array( 'referral_code' ) ) );
	}

	if ( ! spar_migration_plugin_supports_user_status( $plugin ) ) {
		$migrate_data = array_values( array_diff( $migrate_data, array( 'user_status' ) ) );
	}

	$active_plugins = spar_migration_get_active_plugins();

	if ( ! $plugin || ! isset( $active_plugins[ $plugin ] ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Selected plugin is not available for migration.', 'simple-points-and-rewards' ) ), 400 );
	}

	if ( ! in_array( $mode, array( 'override', 'add' ), true ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Invalid migration mode.', 'simple-points-and-rewards' ) ), 400 );
	}

	$batch_size = spar_migration_get_batch_size();

	$args = array(
		'number'      => $batch_size,
		'offset'      => $offset,
		'fields'      => 'ids',
		'orderby'     => 'ID',
		'order'       => 'ASC',
		'count_total' => true,
	);

	$query = new WP_User_Query( $args );

	$user_ids = $query->get_results();
	$total    = (int) $query->get_total();

	if ( empty( $user_ids ) ) {
		wp_send_json_success( array(
			'entries'     => array(),
			'complete'    => true,
			'next_offset' => $offset,
			'processed'   => 0,
			'total'       => $total,
		) );
	}

	$plugin_label = isset( $active_plugins[ $plugin ]['label'] ) ? $active_plugins[ $plugin ]['label'] : $plugin;
	$entries      = array();
	$processed    = 0;

	foreach ( $user_ids as $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : sprintf( esc_html__( 'User #%d', 'simple-points-and-rewards' ), (int) $user_id );

		$external_data   = spar_migration_get_external_points( $user_id, $plugin );
		$source_points   = is_array( $external_data ) && array_key_exists( 'balance', $external_data ) ? $external_data['balance'] : null;
		$source_total    = is_array( $external_data ) && array_key_exists( 'total_earned', $external_data ) ? $external_data['total_earned'] : null;
		$source_birthday = is_array( $external_data ) && array_key_exists( 'birthday', $external_data ) ? $external_data['birthday'] : null;
		$source_referral = is_array( $external_data ) && array_key_exists( 'referral_code', $external_data ) ? $external_data['referral_code'] : null;
		$source_banned  = is_array( $external_data ) && array_key_exists( 'is_banned_user', $external_data ) ? $external_data['is_banned_user'] : null;

		// Default to 0 if points are not available from source.
		if ( null === $source_points ) {
			$source_points = 0;
		}

		// Filter data based on user selection.
		$skip_balance = ! in_array( 'balance', $migrate_data, true );
		if ( ! in_array( 'total_earned', $migrate_data, true ) ) {
			$source_total = null;
		}
		if ( ! in_array( 'birthday', $migrate_data, true ) ) {
			$source_birthday = null;
		}
		if ( ! in_array( 'referral_code', $migrate_data, true ) ) {
			$source_referral = null;
		}
		if ( ! in_array( 'user_status', $migrate_data, true ) ) {
			$source_banned = null;
		}

		$result = spar_migration_apply_points( $user_id, $source_points, $mode, $plugin, $plugin_label, $name, $source_total, $source_birthday, $source_referral, $skip_balance, $source_banned );

		if ( $result ) {
			$entries[] = $result;
		}

		$processed++;
	}

	$next_offset = $offset + count( $user_ids );
	$complete    = ( $next_offset >= $total );

	wp_send_json_success( array(
		'entries'     => $entries,
		'complete'    => $complete,
		'next_offset' => $next_offset,
		'processed'   => $processed,
		'total'       => $total,
	) );
}

/**
 * Update the lifetime total earned meta during migration and return a note for logging.
 *
 * @param int        $user_id             User ID.
 * @param string     $mode                Migration mode.
 * @param float|null $source_total        Source lifetime total (sanitised positive float or null).
 * @param int|null   $initial_total_earned Lifetime total before migration.
 * @return string Log note snippet (leading space) or empty string.
 */
function spar_migration_update_total_earned_meta( $user_id, $mode, $source_total, $initial_total_earned ) {
	if ( null === $source_total ) {
		return '';
	}

	$initial_total_earned = (int) max( 0, $initial_total_earned );
	$source_total         = max( 0, (float) $source_total );

	if ( 'add' === $mode ) {
		$final_total = (int) round( $initial_total_earned + $source_total );
	} else {
		$final_total = (int) round( $source_total );
	}

	if ( $final_total < 0 ) {
		$final_total = 0;
	}

	update_user_meta( $user_id, '_spar_total_earned_points', $final_total );

	return ' ' . sprintf(
		/* translators: %s: formatted lifetime total points. */
		esc_html__( 'Total earned is %s.', 'simple-points-and-rewards' ),
		esc_html( number_format_i18n( $final_total ) )
	);
}

/**
 * Attempt to update the stored birthday date for the user.
 *
 * @param int        $user_id       User ID.
 * @param mixed      $raw_birthday  Raw birthday value from the source plugin.
 * @return string Log note snippet (leading space) or empty string.
 */
function spar_migration_update_birthday_meta( $user_id, $raw_birthday ) {
	if ( null === $raw_birthday || '' === $raw_birthday ) {
		return '';
	}

	$normalized = spar_migration_normalize_birthday_value( $raw_birthday );

	if ( null === $normalized ) {
		return ' ' . esc_html__( 'Birthday skipped – invalid date provided.', 'simple-points-and-rewards' );
	}

	$current = (string) get_user_meta( $user_id, '_spar_birthday_date', true );
	if ( $current === $normalized ) {
		return '';
	}

	$month_day = substr( $normalized, 5, 5 );
	if ( strlen( $month_day ) !== 5 ) {
		$month_day = ''; // Safety guard; helper should guarantee a value but keep defensive.
	}

	update_user_meta( $user_id, '_spar_birthday_date', $normalized );
	if ( $month_day ) {
		update_user_meta( $user_id, '_spar_birthday_md', $month_day );
	}

	return ' ' . sprintf(
		/* translators: %s: formatted birthday date (YYYY-MM-DD). */
		esc_html__( 'Birthday set to %s.', 'simple-points-and-rewards' ),
		esc_html( $normalized )
	);
}

/**
 * Attempt to migrate and store the referral code for the user.
 *
 * @param int         $user_id        User ID.
 * @param string|null $source_referral Referral code provided by the source plugin.
 * @return string Log note snippet (leading space) or empty string.
 */
function spar_migration_update_referral_code_meta( $user_id, $source_referral ) {
	if ( null === $source_referral || '' === $source_referral ) {
		return '';
	}

	$normalized = trim( sanitize_text_field( (string) $source_referral ) );

	if ( '' === $normalized ) {
		return '';
	}

	if ( 0 === strncasecmp( $normalized, 'GIFT-', 5 ) ) {
		$normalized = ltrim( substr( $normalized, 5 ) );
	}

	/**
	 * Allow customization of the normalized referral code during migration.
	 *
	 * @param string $normalized      Current normalized referral code value.
	 * @param int    $user_id         User ID.
	 * @param mixed  $source_referral Raw referral value from the source plugin.
	 */
	$normalized = apply_filters( 'spar_migration_normalized_referral_code', $normalized, $user_id, $source_referral );
	$normalized = trim( sanitize_text_field( (string) $normalized ) );

	if ( '' === $normalized ) {
		return '';
	}

	$current_code = (string) get_user_meta( $user_id, 'spar_referral_code', true );
	if ( $current_code === $normalized ) {
		return '';
	}

	$existing_user_id = 0;

	if ( function_exists( 'spar_get_user_by_referral_code' ) ) {
		$existing_user_id = (int) spar_get_user_by_referral_code( $normalized );
	} else {
		$query_args = array(
			'number'     => 1,
			'fields'     => 'ID',
			'meta_key'   => 'spar_referral_code',
			'meta_value' => $normalized,
		);
		$query      = new WP_User_Query( $query_args );
		if ( ! empty( $query->results ) ) {
			$existing_user_id = (int) $query->results[0];
		}
	}

	if ( $existing_user_id && $existing_user_id !== (int) $user_id ) {
		return ' ' . sprintf(
			/* translators: %s: referral code value. */
			esc_html__( 'Referral code %s skipped (already assigned to another user).', 'simple-points-and-rewards' ),
			esc_html( $normalized )
		);
	}

	update_user_meta( $user_id, 'spar_referral_code', $normalized );

	// Update or create the GIFT coupon and mark it as this user's
	// referral coupon, mirroring spar_ajax_generate_referral_coupon.
	$coupon_code = 'GIFT-' . $normalized;
	$coupon_id   = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $coupon_code ) : 0;

	if ( $coupon_id ) {
		// Coupon exists, update the referrer ID meta.
		update_post_meta( $coupon_id, '_spar_referrer_id', $user_id );
		update_post_meta( $coupon_id, '_spar_referral_code', $normalized );
		update_post_meta( $coupon_id, '_spar_referral_coupon', 'yes' );
	} else {
		// Create a minimal referral coupon if it does not already exist.
		$coupon_args = array(
			'post_title'   => sanitize_text_field( $coupon_code ),
			'post_content' => '',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
			'post_type'    => 'shop_coupon',
		);

		$coupon_id = wp_insert_post( $coupon_args );

		if ( $coupon_id && ! is_wp_error( $coupon_id ) ) {
			// Retrieve referral options for coupon defaults.
			$options          = get_option( 'spar_options', array() );
			$referral_options = isset( $options['earn']['referral'] ) ? $options['earn']['referral'] : array();

			$discount_amount = isset( $referral_options['offer_value'] ) ? $referral_options['offer_value'] : 10;
			$discount_amount = max( 0, (float) $discount_amount );
			$discount_type   = ( isset( $referral_options['offer_type'] ) && 'discount' === $referral_options['offer_type'] ) ? 'percent' : 'fixed_cart';

			update_post_meta( $coupon_id, 'discount_type', sanitize_key( $discount_type ) );
			update_post_meta( $coupon_id, 'coupon_amount', $discount_amount );
			update_post_meta( $coupon_id, 'individual_use', 'yes' );
			update_post_meta( $coupon_id, 'usage_limit', '0' );
			update_post_meta( $coupon_id, 'usage_limit_per_user', '0' );
			update_post_meta( $coupon_id, 'exclude_sale_items', 'no' );
			update_post_meta( $coupon_id, '_spar_referral_coupon', 'yes' );
			update_post_meta( $coupon_id, '_spar_referral_code', $normalized );
			update_post_meta( $coupon_id, '_spar_referrer_id', $user_id );
		}
	}

	return ' ' . sprintf(
		/* translators: %s: referral code value. */
		esc_html__( 'Referral code set to %s.', 'simple-points-and-rewards' ),
		esc_html( $normalized )
	);
}

/**
 * Update the user's banned status meta during migration.
 *
 * @param int      $user_id       User ID.
 * @param int|null $source_banned Source banned flag (1 banned, 0 active, null unknown).
 * @return string Log note snippet (leading space) or empty string.
 */
function spar_migration_update_user_status( $user_id, $source_banned ) {
	if ( null === $source_banned ) {
		return '';
	}

	$desired_status = ( (int) $source_banned > 0 ) ? 'banned' : 'active';
	$current_status = (string) get_user_meta( $user_id, 'spar_user_status', true );

	if ( $current_status === $desired_status ) {
		return '';
	}

	update_user_meta( $user_id, 'spar_user_status', $desired_status );

	if ( 'banned' === $desired_status ) {
		return ' ' . esc_html__( 'User status set to banned.', 'simple-points-and-rewards' );
	}

	return ' ' . esc_html__( 'User status set to active.', 'simple-points-and-rewards' );
}

/**
 * Normalize a raw birthday value into a Y-m-d string.
 *
 * Accepts strings or arrays containing year/month/day information.
 * Returns null when the value cannot be safely converted.
 *
 * @param mixed $value Raw value from the source plugin.
 * @return string|null Normalized Y-m-d string or null.
 */
function spar_migration_normalize_birthday_value( $value ) {
	if ( null === $value || '' === $value ) {
		return null;
	}

	$year  = null;
	$month = null;
	$day   = null;

	if ( is_array( $value ) ) {
		$year  = isset( $value['year'] ) ? $value['year'] : ( isset( $value['y'] ) ? $value['y'] : null );
		$month = isset( $value['month'] ) ? $value['month'] : ( isset( $value['m'] ) ? $value['m'] : null );
		$day   = isset( $value['day'] ) ? $value['day'] : ( isset( $value['d'] ) ? $value['d'] : null );
	} elseif ( is_string( $value ) ) {
		$trimmed = trim( $value );
		if ( preg_match( '/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}$/', $trimmed ) ) {
			$parts = preg_split( '/[-\/.]/', $trimmed );
			$year  = $parts[0];
			$month = $parts[1];
			$day   = $parts[2];
		} else {
			$timestamp = strtotime( $trimmed );
			if ( false === $timestamp ) {
				return null;
			}
			$year  = gmdate( 'Y', $timestamp );
			$month = gmdate( 'm', $timestamp );
			$day   = gmdate( 'd', $timestamp );
		}
	} else {
		return null;
	}

	$year  = (int) $year;
	$month = (int) $month;
	$day   = (int) $day;

	if ( $year < 1900 || $year > (int) gmdate( 'Y' ) + 1 ) {
		return null;
	}

	if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 || ! checkdate( $month, $day, $year ) ) {
		return null;
	}

	return sprintf( '%04d-%02d-%02d', $year, $month, $day );
}

/**
 * Apply migrated points to the current system.
 *
 * @param int    $user_id       User ID.
 * @param float  $source_points Points from source plugin.
 * @param string $mode          Migration mode (override|add).
 * @param string $plugin_key    Plugin key.
 * @param string $plugin_label  Human readable plugin label.
 * @param string $user_name     User display name.
 * @param float|null  $source_total    Points earned lifetime figure from source plugin.
 * @param mixed       $source_birthday Birthday value provided by the source plugin.
 * @param string|null $source_referral Referral code provided by the source plugin.
 * @param bool        $skip_balance    Whether to skip migrating the points balance.
 * @param int|null    $source_banned   Whether the user is banned in the source plugin.
 * @return string
 */
function spar_migration_apply_points( $user_id, $source_points, $mode, $plugin_key, $plugin_label, $user_name, $source_total = null, $source_birthday = null, $source_referral = null, $skip_balance = false, $source_banned = null ) {
	$source_points = (float) $source_points;
	$source_points = apply_filters( 'spar_migration_normalized_points', $source_points, $user_id, $mode, $plugin_key );
	$source_points = max( 0, (float) $source_points );

	$initial_total_earned = null;
	if ( null !== $source_total && '' !== $source_total ) {
		$source_total = (float) $source_total;
		/**
		 * Filter the lifetime total earned value retrieved from the source plugin.
		 *
		 * @param float  $source_total Source lifetime total earned value.
		 * @param int    $user_id      User ID.
		 * @param string $mode         Migration mode.
		 * @param string $plugin_key   Source plugin key.
		 */
		$source_total = apply_filters( 'spar_migration_normalized_total_earned', $source_total, $user_id, $mode, $plugin_key );
		$source_total         = max( 0, (float) $source_total );
		$initial_total_earned = spar_get_user_total_points_earned( $user_id );
	} else {
		$source_total = null;
	}

	$current = spar_get_user_points( $user_id );
	$note    = sprintf(
		esc_html__( 'Points Migration', 'simple-points-and-rewards' ),
		$plugin_label,
		'override' === $mode ? esc_html__( 'override', 'simple-points-and-rewards' ) : esc_html__( 'add', 'simple-points-and-rewards' )
	);

	// If balance migration is skipped, only update metadata.
	if ( $skip_balance ) {
		$note_total    = spar_migration_update_total_earned_meta( $user_id, $mode, $source_total, $initial_total_earned );
		$note_birthday = spar_migration_update_birthday_meta( $user_id, $source_birthday );
		$note_referral = spar_migration_update_referral_code_meta( $user_id, $source_referral );
		$note_status   = spar_migration_update_user_status( $user_id, $source_banned );
		$extra_notes   = $note_total . $note_birthday . $note_referral . $note_status;

		if ( '' !== $extra_notes ) {
			return sprintf(
				esc_html__( '%1$s (#%2$d): balance not migrated.', 'simple-points-and-rewards' ),
				esc_html( $user_name ),
				(int) $user_id
			) . $extra_notes;
		}
		return sprintf(
			esc_html__( '%1$s (#%2$d): skipped (balance migration disabled).', 'simple-points-and-rewards' ),
			esc_html( $user_name ),
			(int) $user_id
		);
	}

	if ( 'add' === $mode ) {
		$points_to_add = (int) round( $source_points );

		if ( $points_to_add <= 0 ) {
			$note_total    = spar_migration_update_total_earned_meta( $user_id, $mode, $source_total, $initial_total_earned );
			$note_birthday = spar_migration_update_birthday_meta( $user_id, $source_birthday );
			$note_referral = spar_migration_update_referral_code_meta( $user_id, $source_referral );
			$note_status   = spar_migration_update_user_status( $user_id, $source_banned );
			$extra_notes   = $note_total . $note_birthday . $note_referral . $note_status;
			return sprintf(
				esc_html__( '%1$s (#%2$d): no points added (source returned 0).', 'simple-points-and-rewards' ),
				esc_html( $user_name ),
				(int) $user_id
			) . $extra_notes;
		}

		spar_update_user_points( $user_id, $points_to_add, 'add', $note, 'migration_add' );
		$new_balance = spar_get_user_points( $user_id );
		$note_total    = spar_migration_update_total_earned_meta( $user_id, $mode, $source_total, $initial_total_earned );
		$note_birthday = spar_migration_update_birthday_meta( $user_id, $source_birthday );
		$note_referral = spar_migration_update_referral_code_meta( $user_id, $source_referral );
		$note_status   = spar_migration_update_user_status( $user_id, $source_banned );
		$extra_notes   = $note_total . $note_birthday . $note_referral . $note_status;

		return sprintf(
			esc_html__( '%1$s (#%2$d): added %3$s points from %4$s. New balance: %5$s.', 'simple-points-and-rewards' ),
			esc_html( $user_name ),
			(int) $user_id,
			esc_html( number_format_i18n( $points_to_add ) ),
			esc_html( $plugin_label ),
			esc_html( number_format_i18n( $new_balance ) )
		) . $extra_notes;
	}

	$target = (int) round( $source_points );

	if ( $target < 0 ) {
		$target = 0;
	}

	// Apply metadata updates (total earned, birthday, referral code, status).
	$note_total    = spar_migration_update_total_earned_meta( $user_id, $mode, $source_total, $initial_total_earned );
	$note_birthday = spar_migration_update_birthday_meta( $user_id, $source_birthday );
	$note_referral = spar_migration_update_referral_code_meta( $user_id, $source_referral );
	$note_status   = spar_migration_update_user_status( $user_id, $source_banned );
	$extra_notes   = $note_total . $note_birthday . $note_referral . $note_status;

	if ( $target === $current ) {
		// Points unchanged, but other data may have been updated.
		if ( '' !== $extra_notes ) {
			return sprintf(
				esc_html__( '%1$s (#%2$d): Points unchanged (%3$s).', 'simple-points-and-rewards' ),
				esc_html( $user_name ),
				(int) $user_id,
				esc_html( number_format_i18n( $current ) )
			) . $extra_notes;
		}
		return sprintf(
			esc_html__( '%1$s (#%2$d): No change required (already %3$s points).', 'simple-points-and-rewards' ),
			esc_html( $user_name ),
			(int) $user_id,
			esc_html( number_format_i18n( $current ) )
		);
	}

	if ( $target > $current ) {
		$delta = $target - $current;
		spar_update_user_points( $user_id, $delta, 'add', $note, 'migration_override' );
	} else {
		$delta = $current - $target;
		spar_update_user_points( $user_id, $delta, 'remove', $note, 'migration_override' );
	}

	$new_balance = spar_get_user_points( $user_id );
	$delta        = $target - $current;
	$delta_output = ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta );

	return sprintf(
		esc_html__( '%1$s (#%2$d): Set balance to %3$s points (%5$s).', 'simple-points-and-rewards' ),
		esc_html( $user_name ),
		(int) $user_id,
		esc_html( number_format_i18n( $new_balance ) ),
		esc_html( $plugin_label ),
		esc_html( $delta_output )
	) . $extra_notes;
}
