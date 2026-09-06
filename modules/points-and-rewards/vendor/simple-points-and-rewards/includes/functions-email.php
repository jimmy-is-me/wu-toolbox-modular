<?php
/**
 * Email notification functions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Send HTML email using WooCommerce email template when available.
 * Falls back to wp_mail with HTML headers if WooCommerce is unavailable.
 *
 * @param string       $to           Recipient email.
 * @param string       $subject      Email subject (plain text).
 * @param string       $heading      Email heading shown inside the template.
 * @param string       $message_html Email body HTML (already sanitized/escaped by caller where appropriate).
 * @param array|string $headers      Optional headers.
 * @param array        $attachments  Optional attachments.
 * @return bool Success.
 */
function spar_send_wc_email( $to, $subject, $heading, $message_html, $headers = array(), $attachments = array() ) {
	// Normalize headers to array and ensure HTML content type when falling back
	if ( empty( $headers ) ) {
		$headers = array();
	} elseif ( is_string( $headers ) ) {
		$headers = array( $headers );
	}

	// Prefer WooCommerce mailer if available so we inherit the store's email styling, header and footer.
	if ( function_exists( 'WC' ) && method_exists( WC(), 'mailer' ) ) {
		$mailer = WC()->mailer();
		if ( $mailer ) {
			// Wrap the message with WooCommerce template + heading
			$wrapped = $mailer->wrap_message( wp_strip_all_tags( (string) $heading ), wp_kses_post( (string) $message_html ) );

			/**
			 * Filters the wrapped WooCommerce email content before sending.
			 *
			 * @param string $wrapped       The wrapped HTML content.
			 * @param string $to            Recipient email.
			 * @param string $subject       Subject.
			 * @param string $heading       Heading.
			 * @param string $message_html  Original message HTML.
			 */
			$wrapped = apply_filters( 'spar_email_wrapped_message', $wrapped, $to, $subject, $heading, $message_html );
			$wrapped = wp_kses_post( (string) $wrapped );

			// Let WooCommerce control headers (from name/address, content-type, etc) unless custom headers passed.
			try {
				return (bool) $mailer->send( $to, wp_strip_all_tags( (string) $subject ), $wrapped, $headers, (array) $attachments );
			} catch ( Exception $e ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// If something unexpected happens, fall back to wp_mail below.
			}
		}
	}

	// Fallback: use core wp_mail with HTML content type
	$headers[] = 'Content-Type: text/html; charset=UTF-8';
	return (bool) wp_mail( $to, wp_strip_all_tags( (string) $subject ), (string) $message_html, $headers, (array) $attachments );
}

/**
 * Get the default subject used for level-up emails.
 *
 * @return string
 */
function spar_get_default_level_email_subject() {
	return esc_html__( 'Congratulations! You reached {level_name}', 'simple-points-and-rewards' );
}

/**
 * Get the default HTML body used for level-up emails.
 *
 * @return string
 */
function spar_get_default_level_email_body() {
	$body = __('<p>Hi {user_name},</p>

	<p>Great news! You just reached <strong>{level_name}</strong>.</p>

	<p>Enjoy your new perks and keep collecting {points_label} to reach the next tier.</p>

	<p><a href="{rewards_url}">View your rewards dashboard</a></p>

	<p>Thanks,<br>{site_name}</p>', 'simple-points-and-rewards' );

	return wp_kses_post( $body );
}

// Send email when user earns points
// Accepts 4 args from the hook: user_id, points, action (note), action_id.
// A 5th optional $force is supported when calling directly.
add_action( 'spar_points_added', 'spar_send_points_earned_email', 10, 4 );
function spar_send_points_earned_email( $user_id, $points, $action, $action_id = '', $force = false ) {

	// Suppress earned emails for migration-origin point changes.
	// Developers can filter this behaviour if needed.
	$skip_for_action = apply_filters( 'spar_email_skip_points_earned_for_action', array( 'migration_add', 'migration_override', 'guest_migration' ) );
	if ( ! is_array( $skip_for_action ) ) {
		$skip_for_action = array();
	}
	if ( in_array( (string) $action_id, $skip_for_action, true ) && ! $force ) {
		return;
	}

	// Check if earned points email is enabled
	// Load all options with defaults, email settings are top-level keys (not grouped)
	$defaults      = function_exists( 'spar_settings_default' ) ? (array) spar_settings_default() : [];
	$options_all   = (array) get_option( 'spar_options', $defaults );
	$options_all   = array_merge( $defaults, $options_all );

	$enable_earned = ! empty( $options_all['enable_earned'] );
	
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	
	// Points label from settings (top-level)
	$points_label = isset( $options_all['points_label'] ) && $options_all['points_label'] !== ''
		? (string) $options_all['points_label']
		: esc_html__( 'Points', 'simple-points-and-rewards' );
	
	// Subject/body from settings (top-level)
	$subject_template = isset( $options_all['subject_earned']) && $options_all['subject_earned'] !== ''
		? (string) $options_all['subject_earned']
		: esc_html__( 'You earned points!', 'simple-points-and-rewards' );
	$subject_template = wp_strip_all_tags( $subject_template );
	$body_template    = isset( $options_all['body_earned']) && $options_all['body_earned'] !== ''
		? (string) $options_all['body_earned']
		: esc_html__( 'Congratulations! You have earned points.', 'simple-points-and-rewards' );
	$body_template    = wp_kses_post( $body_template );
	
	// Replace placeholders in subject and body
    // Build safe URLs (guard against contexts where WooCommerce helpers may not be loaded, e.g. some cron runs)
    $rewards_url_safe = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/rewards/' );

    $replacements_body = [
		'{user_name}'    => esc_html( $user->display_name ),
		'{user_email}'   => esc_html( $user->user_email ),
		'{points}'       => esc_html( spar_format_points_value( (int) $points ) ),
		'{points_label}' => esc_html( $points_label ),
		'{action}'       => esc_html( $action ),
		'{total_points}' => esc_html( spar_format_points_value( (int) spar_get_user_points( $user_id ) ) ),
		'{site_name}'    => esc_html( get_bloginfo( 'name' ) ),
		'{site_url}'     => esc_url( home_url() ),
		'{rewards_url}'  => esc_url( $rewards_url_safe ),
	];
	$replacements_subject = [
		'{user_name}'    => wp_strip_all_tags( (string) $user->display_name ),
		'{user_email}'   => wp_strip_all_tags( (string) $user->user_email ),
		'{points}'       => wp_strip_all_tags( spar_format_points_value( (int) $points ) ),
		'{points_label}' => wp_strip_all_tags( (string) $points_label ),
		'{action}'       => wp_strip_all_tags( (string) $action ),
		'{total_points}' => wp_strip_all_tags( spar_format_points_value( (int) spar_get_user_points( $user_id ) ) ),
		'{site_name}'    => wp_strip_all_tags( get_bloginfo( 'name' ) ),
		'{site_url}'     => esc_url( home_url() ),
		'{rewards_url}'  => esc_url( $rewards_url_safe ),
	];
    
	// Pick per-type templates if defined; fallback to global
	$type_key = sanitize_key( (string) $action_id );
	$valid_types = [ 'signup', 'order', 'first_order', 'nth_order', 'referral', 'review', 'birthday', 'daily_login' ];
	$use_type = in_array( $type_key, $valid_types, true ) ? $type_key : '';
	$type_enabled = false;

	if ( $use_type ) {
		$enabled_key = 'enable_earned_' . $use_type;
		$subject_key = 'subject_earned_' . $use_type;
		$body_key    = 'body_earned_' . $use_type;

		// If a type-specific toggle exists, respect it; otherwise consider it disabled
		$type_enabled = isset( $options_all[ $enabled_key ] ) ? (bool) $options_all[ $enabled_key ] : false;
		// Gate: allow if per-type is enabled; previews can force
		if ( ! $type_enabled && ! $force ) {
			return;
		}

		$subject_template_type = isset( $options_all[ $subject_key ] ) && $options_all[ $subject_key ] !== '' ? (string) $options_all[ $subject_key ] : $subject_template;
		$body_template_type    = isset( $options_all[ $body_key ] ) && $options_all[ $body_key ] !== '' ? (string) $options_all[ $body_key ] : $body_template;

		$subject = str_replace( array_keys( $replacements_subject ), array_values( $replacements_subject ), wp_strip_all_tags( $subject_template_type ) );
		$body    = str_replace( array_keys( $replacements_body ), array_values( $replacements_body ), wp_kses_post( $body_template_type ) );
	} else {
		// No specific type: rely on global toggle
		if ( ! $enable_earned && ! $force ) {
			return;
		}
		$subject = str_replace( array_keys( $replacements_subject ), array_values( $replacements_subject ), $subject_template );
		$body    = str_replace( array_keys( $replacements_body ), array_values( $replacements_body ), $body_template );
	}
	
	// Allow developers to adjust the earned email subject/body before sending
	$subject = apply_filters( 'spar_email_points_earned_subject', $subject, [ 'user_id' => (int) $user_id, 'action_id' => (string) $action_id, 'points' => (int) $points ] );
	$body    = apply_filters( 'spar_email_points_earned_body', $body, [ 'user_id' => (int) $user_id, 'action_id' => (string) $action_id, 'points' => (int) $points ] );
	$subject = wp_strip_all_tags( (string) $subject );
	$body    = wp_kses_post( (string) $body );

	// Send using WooCommerce email template (falls back gracefully)
	$sent = spar_send_wc_email( (string) $user->user_email, (string) $subject, (string) $subject, wpautop( $body ) );

	// Lightweight debug: record last earned email attempt (helps diagnose cron/local issues)
	$debug = [
		'time'         => current_time( 'mysql' ),
		'user_id'      => (int) $user_id,
		'email'        => (string) $user->user_email,
		'enabled'      => (bool) ( $force ? true : ( $use_type ? ( $type_enabled || $enable_earned ) : $enable_earned ) ),
		'global_enabled' => (bool) $enable_earned,
		'type_enabled'   => (bool) $type_enabled,
		'action_id'    => (string) $action_id,
		'type_used'    => (string) ( $use_type ?: 'global' ),
		'subject'      => (string) $subject,
		'mail_sent'    => (bool) $sent,
	];
	update_option( 'spar_last_earned_email_debug', $debug, false );
    
	// Announce sending result for listeners
	do_action( 'spar_email_sent', 'points_earned', (bool) $sent, (string) $user->user_email, (string) $subject, (int) $user_id, (string) $action_id );
}

// Send email when user claims a voucher
add_action( 'spar_voucher_claimed', 'spar_send_voucher_claimed_email', 10, 4 );
function spar_send_voucher_claimed_email( $user_id, $voucher_code, $voucher_type, $voucher_value ) {
    
	// Check if claimed voucher email is enabled
	// Load all options with defaults, email settings are top-level keys (not grouped)
	$defaults      = function_exists( 'spar_settings_default' ) ? (array) spar_settings_default() : [];
	$options_all   = (array) get_option( 'spar_options', $defaults );
	$options_all   = array_merge( $defaults, $options_all );

	if ( empty( $options_all['enable_claimed'] ) ) {
		return;
	}
	
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	
	$points_label = isset( $options_all['points_label'] ) && $options_all['points_label'] !== ''
		? (string) $options_all['points_label']
		: esc_html__( 'Points', 'simple-points-and-rewards' );
	
	$subject_template = isset( $options_all['subject_claimed']) && $options_all['subject_claimed'] !== ''
		? (string) $options_all['subject_claimed']
		: esc_html__( 'Your voucher has been claimed!', 'simple-points-and-rewards' );
	$subject_template = wp_strip_all_tags( $subject_template );
	$body_template    = isset( $options_all['body_claimed']) && $options_all['body_claimed'] !== ''
		? (string) $options_all['body_claimed']
		: esc_html__( 'Your voucher has been successfully claimed.', 'simple-points-and-rewards' );
	$body_template    = wp_kses_post( $body_template );
	
    // Get voucher type description
	$type_description = '';
	if ( $voucher_type === 'voucher' ) {
		/* translators: %s: Voucher value formatted price */
		$formatted_value = function_exists( 'wc_price' ) ? wc_price( $voucher_value ) : ( is_numeric( $voucher_value ) ? spar_format_currency_amount( $voucher_value ) : (string) $voucher_value );
		$type_description = sprintf( esc_html__( 'Discount Voucher worth %s', 'simple-points-and-rewards' ), $formatted_value );
	} elseif ( $voucher_type === 'product' ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $voucher_value ) : null;
		/* translators: %s: Product name */
			$type_description = $product ? sprintf( esc_html__( '%s', 'simple-points-and-rewards' ), $product->get_name() ) : esc_html__( 'Free Product Voucher', 'simple-points-and-rewards' );
	}
	
    // Replace placeholders in subject and body with safe fallbacks for URLs
    $cart_url_safe     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
    $checkout_url_safe = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
    $rewards_url       = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/rewards/' );

	$apply_coupon_url = add_query_arg( array(
		'apply_coupon' => $voucher_code,
		'spar_nonce'   => wp_create_nonce( 'spar_apply_coupon' ),
	), $cart_url_safe );

	$replacements_body = [
		'{user_name}'      => esc_html( $user->display_name ),
		'{user_email}'     => esc_html( $user->user_email ),
		'{voucher_code}'   => esc_html( $voucher_code ),
		'{voucher_type}'   => wp_kses_post( $type_description ),
		'{voucher_value}'  => wp_kses_post( is_numeric( $voucher_value ) ? ( function_exists( 'wc_price' ) ? wc_price( $voucher_value ) : spar_format_currency_amount( $voucher_value ) ) : (string) $voucher_value ),
		'{points_label}'   => esc_html( $points_label ),
		'{total_points}'   => esc_html( spar_format_points_value( (int) spar_get_user_points( $user_id ) ) ),
		'{site_name}'      => esc_html( get_bloginfo( 'name' ) ),
		'{site_url}'       => esc_url( home_url() ),
		'{cart_url}'       => esc_url( $cart_url_safe ),
		'{checkout_url}'   => esc_url( $checkout_url_safe ),
		'{apply_coupon_url}' => esc_url( $apply_coupon_url ),
		'{rewards_url}'    => esc_url( $rewards_url ),
	];
	$replacements_subject = [
		'{user_name}'      => wp_strip_all_tags( (string) $user->display_name ),
		'{user_email}'     => wp_strip_all_tags( (string) $user->user_email ),
		'{voucher_code}'   => wp_strip_all_tags( (string) $voucher_code ),
		'{voucher_type}'   => wp_strip_all_tags( $type_description ),
		'{voucher_value}'  => wp_strip_all_tags( is_numeric( $voucher_value ) ? ( function_exists( 'wc_price' ) ? wc_price( $voucher_value ) : spar_format_currency_amount( $voucher_value ) ) : (string) $voucher_value ),
		'{points_label}'   => wp_strip_all_tags( (string) $points_label ),
		'{total_points}'   => wp_strip_all_tags( spar_format_points_value( (int) spar_get_user_points( $user_id ) ) ),
		'{site_name}'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
		'{site_url}'       => esc_url( home_url() ),
		'{cart_url}'       => esc_url( $cart_url_safe ),
		'{checkout_url}'   => esc_url( $checkout_url_safe ),
		'{apply_coupon_url}' => esc_url( $apply_coupon_url ),
		'{rewards_url}'    => esc_url( $rewards_url ),
	];

	$subject = str_replace( array_keys( $replacements_subject ), array_values( $replacements_subject ), $subject_template );
	$body    = str_replace( array_keys( $replacements_body ), array_values( $replacements_body ), $body_template );

	// Let developers adjust voucher claimed subject/body before send
	$subject = apply_filters( 'spar_email_voucher_claimed_subject', $subject, [ 'user_id' => (int) $user_id, 'voucher_code' => (string) $voucher_code, 'voucher_type' => (string) $voucher_type, 'voucher_value' => $voucher_value ] );
	$body    = apply_filters( 'spar_email_voucher_claimed_body', $body, [ 'user_id' => (int) $user_id, 'voucher_code' => (string) $voucher_code, 'voucher_type' => (string) $voucher_type, 'voucher_value' => $voucher_value ] );
	$subject = wp_strip_all_tags( (string) $subject );
	$body    = wp_kses_post( (string) $body );
	
	// Send using WooCommerce email template (falls back gracefully)
	$sent = spar_send_wc_email( (string) $user->user_email, (string) $subject, (string) $subject, wpautop( $body ) );

	// Lightweight debug: record last claimed email attempt
	$debug = [
		'time'         => current_time( 'mysql' ),
		'user_id'      => (int) $user_id,
		'email'        => (string) $user->user_email,
		'enabled'      => (bool) ( ! empty( $options_all['enable_claimed'] ) ),
		'subject'      => (string) $subject,
		'mail_sent'    => (bool) $sent,
	];
	update_option( 'spar_last_claimed_email_debug', $debug, false );
	do_action( 'spar_email_sent', 'voucher_claimed', (bool) $sent, (string) $user->user_email, (string) $subject, (int) $user_id, (string) $voucher_code );
}

	add_action( 'spar_user_level_changed', 'spar_maybe_send_level_up_email', 10, 4 );
	/**
	 * Send a custom email when a customer reaches a configured level.
	 *
	 * @param int   $user_id        User ID.
	 * @param array $new_level      New level data from spar_get_all_levels().
	 * @param array $previous_level Previous level data (or null).
	 * @param int   $total_earned   Lifetime earned points after the change.
	 */
	function spar_maybe_send_level_up_email( $user_id, $new_level, $previous_level, $total_earned ) {
		if ( empty( $new_level ) || ! is_array( $new_level ) ) {
			return;
		}

		if ( ! empty( $new_level['is_default'] ) ) {
			// Do not send notifications for the starter level.
			return;
		}

		if ( empty( $new_level['level_up_email_enabled'] ) ) {
			return;
		}

		$new_required  = isset( $new_level['required_points'] ) ? (int) $new_level['required_points'] : 0;
		$prev_required = isset( $previous_level['required_points'] ) ? (int) $previous_level['required_points'] : -1;

		if ( $prev_required >= $new_required ) {
			// Only send when the customer moves to a higher level.
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$points_label = function_exists( 'spar_get_single_option' ) ? spar_get_single_option( 'points_label' ) : '';
		if ( empty( $points_label ) ) {
			$points_label = esc_html__( 'Reward Points', 'simple-points-and-rewards' );
		}

		$default_subject = spar_get_default_level_email_subject();
		$default_body    = spar_get_default_level_email_body();

		$subject_template = isset( $new_level['level_up_email_subject'] ) && '' !== trim( (string) $new_level['level_up_email_subject'] )
			? (string) $new_level['level_up_email_subject']
			: $default_subject;

		$body_template = isset( $new_level['level_up_email_body'] ) && '' !== trim( (string) $new_level['level_up_email_body'] )
			? (string) $new_level['level_up_email_body']
			: $default_body;

		$rewards_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/rewards/' );
		$current_points = spar_get_user_points( $user_id );

		$replacements = array(
			'{user_name}'           => wp_strip_all_tags( (string) $user->display_name ),
			'{user_email}'          => wp_strip_all_tags( (string) $user->user_email ),
			'{level_name}'          => isset( $new_level['name'] ) ? wp_strip_all_tags( (string) $new_level['name'] ) : '',
			'{previous_level_name}' => isset( $previous_level['name'] ) ? wp_strip_all_tags( (string) $previous_level['name'] ) : '',
			'{points_label}'        => wp_strip_all_tags( (string) $points_label ),
			'{total_points}'        => wp_strip_all_tags( spar_format_points_value( (int) $current_points ) ),
			'{site_name}'           => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'{site_url}'            => esc_url( home_url() ),
			'{rewards_url}'         => esc_url( $rewards_url ),
		);

		$subject = strtr( $subject_template, $replacements );
		$body    = strtr( $body_template, $replacements );

		$context = array(
			'user_id'        => (int) $user_id,
			'new_level'      => $new_level,
			'previous_level' => $previous_level,
			'total_earned'   => (int) $total_earned,
		);

		$subject = apply_filters( 'spar_email_level_up_subject', $subject, $context );
		$subject = wp_strip_all_tags( (string) $subject );

		$body = apply_filters( 'spar_email_level_up_body', $body, $context );
		$body = wp_kses_post( (string) $body );

		$sent = spar_send_wc_email( (string) $user->user_email, (string) $subject, (string) $subject, wpautop( $body ) );

		$level_id = isset( $new_level['id'] ) ? (string) $new_level['id'] : '';
		do_action( 'spar_email_sent', 'level_up', (bool) $sent, (string) $user->user_email, (string) $subject, (int) $user_id, $level_id );
	}

// Capture wp_mail() failures to help diagnose environments where mail is blocked
add_action( 'wp_mail_failed', function( $wp_error ) {
	if ( is_wp_error( $wp_error ) ) {
		$err = [
			'time'    => current_time( 'mysql' ),
			'code'    => $wp_error->get_error_code(),
			'message' => $wp_error->get_error_message(),
			'data'    => $wp_error->get_error_data(),
		];
		update_option( 'spar_last_mail_error', $err, false );
	}
}, 10, 1 );

// Add email preview functionality for admin
add_action( 'wp_ajax_spar_preview_email', 'spar_preview_email_ajax' );
function spar_preview_email_ajax() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Unauthorized' );
	}
	// Require nonce for admin-side actions
	check_ajax_referer( 'spar_preview_email', 'nonce', true );

	$email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
	$user_id = get_current_user_id();
	
	if ( $email_type === 'earned' ) {
		// Preview earned points email (global)
		spar_send_points_earned_email( $user_id, 100, esc_html__( 'Preview Test Action', 'simple-points-and-rewards' ), 'preview', true );
		wp_send_json_success( esc_html__( 'Preview email sent to your email address', 'simple-points-and-rewards' ) );
	} elseif ( strpos( $email_type, 'earned_' ) === 0 ) {
		// Preview per-type earned emails, e.g., earned_signup, earned_order, earned_first_order, etc.
		$type = sanitize_key( str_replace( 'earned_', '', $email_type ) );
		$action_names = [
			'signup'      => esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
			'order'       => esc_html__( 'Points Earned for Order', 'simple-points-and-rewards' ),
			'first_order' => esc_html__( 'First Order Bonus', 'simple-points-and-rewards' ),
			'nth_order'   => esc_html__( 'Bonus after X Orders', 'simple-points-and-rewards' ),
			'referral'    => esc_html__( 'Referral Bonus', 'simple-points-and-rewards' ),
			'review'      => esc_html__( 'Write a Review', 'simple-points-and-rewards' ),
			'birthday'    => esc_html__( 'Birthday Bonus', 'simple-points-and-rewards' ),
		];
		$action_text = isset( $action_names[ $type ] ) ? $action_names[ $type ] : esc_html__( 'Preview Test Action', 'simple-points-and-rewards' );
		spar_send_points_earned_email( $user_id, 100, $action_text, $type, true );
		wp_send_json_success( esc_html__( 'Preview email sent to your email address', 'simple-points-and-rewards' ) );
	} elseif ( $email_type === 'claimed' ) {
		// Preview claimed voucher email
		spar_send_voucher_claimed_email( $user_id, 'PREVIEW123', 'voucher', 10.00 );
		wp_send_json_success( esc_html__( 'Preview email sent to your email address', 'simple-points-and-rewards' ) );
	} elseif ( 'points_expiry_notice' === $email_type ) {
		if ( ! function_exists( 'spar_points_expiry_send_email' ) ) {
			wp_send_json_error( esc_html__( 'Points expiry email preview is unavailable.', 'simple-points-and-rewards' ) );
		}
		$now        = current_time( 'timestamp' );
		$expiry_ts  = $now + ( 3 * DAY_IN_SECONDS );
		$last_active = $now - ( 27 * HOUR_IN_SECONDS );
		spar_points_expiry_send_email( $user_id, 250, $expiry_ts, 3, $last_active, true );
		wp_send_json_success( esc_html__( 'Preview email sent to your email address', 'simple-points-and-rewards' ) );
	}
	
	wp_send_json_error( esc_html__( 'Invalid email type', 'simple-points-and-rewards' ) );
}
