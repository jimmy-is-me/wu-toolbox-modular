<?php

/**
 * Settings utilities and common functions
 *
 * Central place for settings schema, defaults, and helpers used by the admin UI.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/settings-sanitize.php';
/**
 * Render a PRO upgrade notice banner for a locked settings tab.
 *
 * @param string $feature_name Human-readable feature name.
 */
function spar_render_pro_tab_upgrade_notice(  $feature_name  ) {
    $upgrade_url = 'https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing';
    ?>
	<div class="spar-pro-tab-notice">
		<span class="dashicons dashicons-lock" aria-hidden="true"></span>
		<div class="spar-pro-tab-notice-content">
			<strong>
				<?php 
    printf( 
        /* translators: %s: feature name */
        esc_html__( '%s is a PRO feature', 'simple-points-and-rewards' ),
        esc_html( $feature_name )
     );
    ?>
			</strong>
			<p><?php 
    esc_html_e( 'Upgrade to PRO to enable and configure this feature.', 'simple-points-and-rewards' );
    ?></p>
		</div>
		<a href="<?php 
    echo esc_url( $upgrade_url );
    ?>" target="_blank" rel="noopener" class="button button-primary">
			<?php 
    esc_html_e( 'Upgrade to PRO', 'simple-points-and-rewards' );
    ?>
		</a>
	</div>
	<?php 
}

function spar_get_option_fields() {
    // Define all settings and their defaults/types in one array.
    // Keeping this centralized makes rendering, sanitizing, and migrations simpler.
    $avalara_tax_active = ( function_exists( 'spar_is_avalara_tax_plugin_active' ) ? spar_is_avalara_tax_plugin_active() : false );
    $database_supports_emoji = !function_exists( 'spar_options_table_supports_emoji' ) || spar_options_table_supports_emoji();
    $body_earned_default = ( $database_supports_emoji ? '<p>Hi {user_name},</p>

	<p>🎉 <strong>Congratulations!</strong> You\'ve just earned <strong>{points} {points_label}</strong> for: <em>{action}</em></p>

	<p><strong>Your account summary:</strong><br>
	💰 Total {points_label}: <strong>{total_points}</strong></p>

	<p>Ready to turn your points into rewards? <a href="{rewards_url}">Visit your rewards dashboard</a> to see what\'s available!</p>

	<p>Keep earning and enjoying the benefits!</p>

	<p>Best regards,<br>
	The {site_name} Team</p>' : '<p>Hi {user_name},</p>

	<p><strong>Congratulations!</strong> You\'ve just earned <strong>{points} {points_label}</strong> for: <em>{action}</em></p>

	<p><strong>Your account summary:</strong><br>
	Total {points_label}: <strong>{total_points}</strong></p>

	<p>Ready to turn your points into rewards? <a href="{rewards_url}">Visit your rewards dashboard</a> to see what\'s available!</p>

	<p>Keep earning and enjoying the benefits!</p>

	<p>Best regards,<br>
	The {site_name} Team</p>' );
    $body_claimed_default = ( $database_supports_emoji ? '<p>Hi {user_name},</p>

	<p>🎊 <strong>Fantastic!</strong> Your reward has been successfully claimed!</p>

	<p><strong>Reward Details:</strong><br>
	🎁 Reward: <strong>{voucher_type}</strong><br>
	🎫 Voucher Code: <strong>{voucher_code}</strong><br>
	💳 Points Used: <strong>{points} {points_label}</strong></p>

	<p><strong>How to use your voucher:</strong><br>
	Simply enter the code <strong>{voucher_code}</strong> at checkout, or <a href="{apply_coupon_url}">click here to apply it automatically</a>.</p>

	<p><strong>Your current balance:</strong><br>
	💰 Remaining {points_label}: <strong>{total_points}</strong></p>

	<p>Want to earn more points? <a href="{rewards_url}">Check out all the ways to earn</a>!</p>

	<p>Happy shopping!</p>

	<p>Best regards,<br>
	The {site_name} Team</p>' : '<p>Hi {user_name},</p>

	<p><strong>Fantastic!</strong> Your reward has been successfully claimed!</p>

	<p><strong>Reward Details:</strong><br>
	Reward: <strong>{voucher_type}</strong><br>
	Voucher Code: <strong>{voucher_code}</strong><br>
	Points Used: <strong>{points} {points_label}</strong></p>

	<p><strong>How to use your voucher:</strong><br>
	Simply enter the code <strong>{voucher_code}</strong> at checkout, or <a href="{apply_coupon_url}">click here to apply it automatically</a>.</p>

	<p><strong>Your current balance:</strong><br>
	Remaining {points_label}: <strong>{total_points}</strong></p>

	<p>Want to earn more points? <a href="{rewards_url}">Check out all the ways to earn</a>!</p>

	<p>Happy shopping!</p>

	<p>Best regards,<br>
	The {site_name} Team</p>' );
    $body_earned_birthday_default = ( $database_supports_emoji ? '<p>Hi {user_name},</p><p>🎂 Happy Birthday! We just added <strong>{points} {points_label}</strong> to your account.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">Treat yourself with rewards</a></p><p>- {site_name}</p>' : '<p>Hi {user_name},</p><p>Happy Birthday! We just added <strong>{points} {points_label}</strong> to your account.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">Treat yourself with rewards</a></p><p>- {site_name}</p>' );
    $default_font_awesome_icon = 'fa-solid fa-star';
    $starter_badge_icon_default = $default_font_awesome_icon;
    $starter_badge_type_default = 'preset';
    $starter_badge_text_default = '';
    $rewards_widget_icon_default = $default_font_awesome_icon;
    $rewards_widget_text_default = '';
    $fields = [
        'earn'                                         => [
            'signup'         => [
                'enabled'             => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'points'              => [
                    'type'    => 'number',
                    'default' => 100,
                ],
                'name'                => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
                ],
                'show_guest_message'  => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'hide_after_rewarded' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
            ],
            'daily_login'    => [
                'enabled'        => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'points'         => [
                    'type'    => 'number',
                    'default' => 10,
                ],
                'name'           => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Daily Login Bonus', 'simple-points-and-rewards' ),
                ],
                'streak_enabled' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'streak_days'    => [
                    'type'    => 'number',
                    'default' => 7,
                ],
                'streak_points'  => [
                    'type'    => 'number',
                    'default' => 50,
                ],
                'streak_type'    => [
                    'type'    => 'select',
                    'default' => 'recurring',
                ],
            ],
            'spin_wheel'     => [
                'enabled'                => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'name'                   => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'description'            => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'button_text'            => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'spin_button_text'       => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'popup_style'            => [
                    'type'    => 'select',
                    'default' => 'light',
                ],
                'cooldown'               => [
                    'type'    => 'select',
                    'default' => 'daily',
                ],
                'cooldown_hours'         => [
                    'type'    => 'number',
                    'default' => 24,
                ],
                'show_probabilities'     => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'spin_sound'             => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'celebration_sound'      => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'show_confetti'          => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'prizes'                 => [
                    'type'    => 'array',
                    'default' => [],
                ],
                'order_spins_enabled'    => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'order_spins_amount'     => [
                    'type'    => 'float',
                    'default' => 0,
                ],
                'level_up_spins_enabled' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'level_up_spins_amount'  => [
                    'type'    => 'number',
                    'default' => 1,
                ],
                'buy_spins_enabled'      => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'buy_spins_points_cost'  => [
                    'type'    => 'number',
                    'default' => 100,
                ],
                'buy_spins_heading'      => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'buy_spins_description'  => [
                    'type'    => 'text',
                    'default' => '',
                ],
                'buy_spins_button_text'  => [
                    'type'    => 'text',
                    'default' => '',
                ],
            ],
            'birthday'       => [
                'enabled' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'points'  => [
                    'type'    => 'number',
                    'default' => 100,
                ],
                'name'    => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Birthday Bonus', 'simple-points-and-rewards' ),
                ],
            ],
            'review'         => [
                'enabled'                  => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'points'                   => [
                    'type'    => 'number',
                    'default' => 100,
                ],
                'limit'                    => [
                    'type'    => 'number',
                    'default' => 1,
                ],
                'limit_per_product'        => [
                    'type'    => 'number',
                    'default' => 1,
                ],
                'require_purchase'         => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'show_unreviewed_products' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'name'                     => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Write a Review', 'simple-points-and-rewards' ),
                ],
            ],
            'order'          => [
                'enabled'                      => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'points_per'                   => [
                    'type'    => 'float',
                    'default' => 5,
                ],
                'points_per_points'            => [
                    'type'    => 'float',
                    'default' => 5,
                ],
                'points_per_amount'            => [
                    'type'    => 'float',
                    'default' => 1,
                ],
                'calculation_total_mode'       => [
                    'type'    => 'select',
                    'default' => 'subtotal_after_discount',
                ],
                'calculation_include_shipping' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'calculation_include_taxes'    => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'min_spend'                    => [
                    'type'    => 'float',
                    'default' => 0,
                ],
                'max_points'                   => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'max_percent'                  => [
                    'type'    => 'float',
                    'default' => 0,
                ],
                'exclude_sale_items'           => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'name'                         => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Place an Order', 'simple-points-and-rewards' ),
                ],
                'award_timing'                 => [
                    'type'    => 'select',
                    'default' => 'thankyou',
                ],
                'completed_status'             => [
                    'type'    => 'select',
                    'default' => 'completed',
                ],
                'deduct_on_refund'             => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'skip_if_redeemed'             => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'rounding_mode'                => [
                    'type'    => 'select',
                    'default' => 'floor',
                ],
            ],
            'order_fixed'    => [
                'enabled'                      => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'name'                         => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Points for Orders', 'simple-points-and-rewards' ),
                ],
                'calculation_total_mode'       => [
                    'type'    => 'select',
                    'default' => 'subtotal_after_discount',
                ],
                'calculation_include_shipping' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'calculation_include_taxes'    => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'deduct_on_refund'             => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'tiers'                        => [
                    'type'    => 'array',
                    'default' => [],
                ],
            ],
            'referral'       => [
                'enabled'                   => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'earning_type'              => [
                    'type'    => 'select',
                    'default' => 'fixed',
                ],
                'percentage'                => [
                    'type'    => 'float',
                    'default' => 10,
                ],
                'name'                      => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Referral Bonus', 'simple-points-and-rewards' ),
                ],
                'points_per'                => [
                    'type'    => 'float',
                    'default' => 5,
                ],
                'fixed_points'              => [
                    'type'    => 'number',
                    'default' => 100,
                ],
                'award_timing'              => [
                    'type'    => 'select',
                    'default' => 'completed',
                ],
                'deduct_on_refund'          => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'show_clicks_log'           => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'social_enabled'            => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'social_email_enabled'      => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'social_facebook_enabled'   => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'social_twitter_enabled'    => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'social_whatsapp_enabled'   => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'social_telegram_enabled'   => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'social_discord_enabled'    => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'social_tiktok_enabled'     => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'social_share_text'         => [
                    'type'    => 'text',
                    'default' => 'Check out this great store!',
                ],
                'offer_enabled'             => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'offer_type'                => [
                    'type'    => 'select',
                    'default' => 'discount',
                ],
                'offer_value'               => [
                    'type'    => 'float',
                    'default' => 10,
                ],
                'offer_text'                => [
                    'type'    => 'text',
                    'default' => esc_html__( 'Get {value} off your order!', 'simple-points-and-rewards' ),
                ],
                'offer_show_countdown'      => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'offer_expiry_hours'        => [
                    'type'    => 'number',
                    'default' => 24,
                ],
                'gift_widget_enabled'       => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'gift_cart_enabled'         => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'gift_checkout_enabled'     => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'gift_auto_apply'           => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'gift_widget_position'      => [
                    'type'    => 'select',
                    'default' => 'bottom-right',
                ],
                'gift_widget_color'         => [
                    'type'    => 'text',
                    'default' => '#667eea',
                ],
                'gift_widget_message'       => [
                    'type'    => 'text',
                    'default' => esc_html__( 'A customer has sent you a gift coupon!', 'simple-points-and-rewards' ),
                ],
                'gift_widget_personalize'   => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'gift_first_order_only'     => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'gift_attribution_model'    => [
                    'type'    => 'select',
                    'default' => 'first_click',
                ],
                'block_self_referral'       => [
                    'type'    => 'checkbox',
                    'default' => true,
                ],
                'block_same_ip'             => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'new_customer_only'         => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'referrer_daily_cap'        => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'template_coupon_id'        => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'gift_usage_limit_per_user' => [
                    'type'    => 'select',
                    'default' => 'email',
                ],
                'coupon_tracking_mode'      => [
                    'type'    => 'select',
                    'default' => 'always_track',
                ],
            ],
            'social_sharing' => [
                'referral_social_points_enabled' => [
                    'type'    => 'checkbox',
                    'default' => false,
                ],
                'social_points'                  => [
                    'type'    => 'number',
                    'default' => 10,
                ],
                'social_limit_mode'              => [
                    'type'    => 'select',
                    'default' => 'per_social',
                ],
            ],
        ],
        'earn_conditional_rules'                       => [
            'type'    => 'array',
            'default' => [],
        ],
        'earn_ways_order'                              => [
            'type'    => 'array',
            'default' => [
                'signup',
                'order',
                'order_fixed',
                'referral',
                'first_order',
                'nth_order',
                'review',
                'birthday',
                'daily_login',
                'spin_wheel'
            ],
        ],
        'rewards_label'                                => [
            'type'    => 'text',
            'default' => esc_html__( 'Rewards', 'simple-points-and-rewards' ),
        ],
        'points_label'                                 => [
            'type'    => 'text',
            'default' => esc_html__( 'Reward Points', 'simple-points-and-rewards' ),
        ],
        'points_label_singular'                        => [
            'type'    => 'text',
            'default' => '',
        ],
        'points_prefix'                                => [
            'type'    => 'text',
            'default' => '',
        ],
        'points_icon'                                  => [
            'type'    => 'text',
            'default' => '',
        ],
        'points_icon_url'                              => [
            'type'    => 'text',
            'default' => '',
        ],
        'points_icon_display'                          => [
            'type'    => 'select',
            'default' => 'prominent',
        ],
        'rewards_vouchers_enabled'                     => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'show_on_product'                              => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'product_points_location'                      => [
            'type'    => 'select',
            'default' => 'summary_after_price',
        ],
        'product_points_message'                       => [
            'type'    => 'text',
            'default' => '',
        ],
        'product_points_text_color'                    => [
            'type'    => 'color',
            'default' => '',
        ],
        'product_points_bg_color'                      => [
            'type'    => 'color',
            'default' => '',
        ],
        'show_on_product_loop'                         => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'product_loop_points_location'                 => [
            'type'    => 'select',
            'default' => 'loop_after_title',
        ],
        'product_loop_points_message'                  => [
            'type'    => 'text',
            'default' => '',
        ],
        'product_loop_points_text_color'               => [
            'type'    => 'color',
            'default' => '',
        ],
        'product_loop_points_bg_color'                 => [
            'type'    => 'color',
            'default' => '',
        ],
        'show_on_cart'                                 => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'show_on_checkout'                             => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'show_on_thankyou'                             => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'show_for_guests'                              => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'checkout_box_show_points_summary'             => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'rewards_box_theme'                            => [
            'type'    => 'select',
            'default' => 'default',
        ],
        'checkout_box_theme_style'                     => [
            'type'    => 'select',
            'default' => 'light',
        ],
        'checkout_box_redeem_button_text'              => [
            'type'    => 'text',
            'default' => '',
        ],
        'checkout_box_primary_color'                   => [
            'type'    => 'color',
            'default' => '#667eea',
        ],
        'checkout_box_secondary_color'                 => [
            'type'    => 'color',
            'default' => '#28a745',
        ],
        'checkout_box_enable_confetti'                 => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'link_to_rewards'                              => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'show_rewards_in_my_account'                   => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'rewards_page_id'                              => [
            'type'    => 'number',
            'default' => 0,
        ],
        'dashboard_header_text'                        => [
            'type'    => 'text',
            'default' => '',
        ],
        'dashboard_subheader_text'                     => [
            'type'    => 'text',
            'default' => '',
        ],
        'dashboard_tabs_order'                         => [
            'type'    => 'array',
            'default' => [
                'earn',
                'claim',
                'vouchers',
                'levels',
                'history',
                'settings'
            ],
        ],
        'dashboard_tab_labels'                         => [
            'type'    => 'array',
            'default' => [
                'earn'     => esc_html__( 'Earn Points', 'simple-points-and-rewards' ),
                'claim'    => esc_html__( 'Claim Rewards', 'simple-points-and-rewards' ),
                'vouchers' => esc_html__( 'Your Vouchers', 'simple-points-and-rewards' ),
                'levels'   => esc_html__( 'Levels', 'simple-points-and-rewards' ),
                'history'  => esc_html__( 'History', 'simple-points-and-rewards' ),
                'settings' => esc_html__( 'Settings', 'simple-points-and-rewards' ),
            ],
        ],
        'earn_points_text_enabled'                     => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'earn_points_text'                             => [
            'type'    => 'textarea',
            'default' => '',
        ],
        'redeem_text'                                  => [
            'type'    => 'textarea',
            'default' => '',
        ],
        'reward_name'                                  => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'reward_points'                                => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'reward_value'                                 => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'remaining_text'                               => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'redemption_bar'                               => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'account_enable_confetti'                      => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'dashboard_points_discount_enabled'            => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'redeem_individual_enabled'                    => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'redeem_individual_display'                    => [
            'type'    => 'select',
            'default' => 'both',
        ],
        'redeem_individual_page'                       => [
            'type'    => 'select',
            'default' => 'both',
        ],
        'redeem_individual_message'                    => [
            'type'    => 'text',
            'default' => '',
        ],
        'redeem_button_text'                           => [
            'type'    => 'text',
            'default' => '',
        ],
        'redeem_text_color'                            => [
            'type'    => 'color',
            'default' => '',
        ],
        'redeem_points_per_points'                     => [
            'type'    => 'float',
            'default' => 100,
        ],
        'redeem_points_per_amount'                     => [
            'type'    => 'float',
            'default' => 1,
        ],
        'redeem_discount_includes_tax'                 => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'redeem_discount_value_include_tax'            => [
            'type'    => 'checkbox',
            'default' => 'incl' === get_option( 'woocommerce_tax_display_cart', 'excl' ),
        ],
        'redeem_discount_fee_taxable'                  => [
            'type'    => 'checkbox',
            'default' => $avalara_tax_active,
        ],
        'redeem_discount_fee_tax_class_mode'           => [
            'type'    => 'select',
            'default' => 'default',
        ],
        'redeem_discount_fee_tax_class'                => [
            'type'    => 'select',
            'default' => '',
        ],
        'redeem_points_min'                            => [
            'type'    => 'number',
            'default' => 0,
        ],
        'redeem_points_max'                            => [
            'type'    => 'number',
            'default' => 0,
        ],
        'redeem_max_cart_percentage'                   => [
            'type'    => 'number',
            'default' => 0,
        ],
        'redeem_min_cart_total'                        => [
            'type'    => 'float',
            'default' => 0,
        ],
        'rewards_theme_color_1'                        => [
            'type'    => 'color',
            'default' => '#667eea',
        ],
        'rewards_theme_color_2'                        => [
            'type'    => 'color',
            'default' => '#764ba2',
        ],
        'rewards_theme_accent_color'                   => [
            'type'    => 'color',
            'default' => '#2ca58d',
        ],
        'dashboard_dark_mode_toggle'                   => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'dashboard_dark_mode_default'                  => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'dashboard_dark_mode_header'                   => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'dashboard_dark_mode_hide_toggle_when_default' => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'enable_earned'                                => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'enable_claimed'                               => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned'                               => [
            'type'    => 'text',
            'default' => esc_html__( 'Great news! You\'ve earned {points} {points_label}!', 'simple-points-and-rewards' ),
        ],
        'subject_claimed'                              => [
            'type'    => 'text',
            'default' => esc_html__( 'Your reward is ready! Voucher code: {voucher_code}', 'simple-points-and-rewards' ),
        ],
        'body_earned'                                  => [
            'type'    => 'editor',
            'default' => $body_earned_default,
        ],
        'body_claimed'                                 => [
            'type'    => 'editor',
            'default' => $body_claimed_default,
        ],
        'enable_earned_signup'                         => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_signup'                        => [
            'type'    => 'text',
            'default' => esc_html__( 'Welcome! You earned {points} {points_label} for signing up', 'simple-points-and-rewards' ),
        ],
        'body_earned_signup'                           => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>You just earned <strong>{points} {points_label}</strong> for: <em>{action}</em>.</p><p>Your current balance is <strong>{total_points}</strong>.</p><p><a href="{rewards_url}">See your rewards</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_order'                          => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_order'                         => [
            'type'    => 'text',
            'default' => esc_html__( 'You earned {points} {points_label} for your order', 'simple-points-and-rewards' ),
        ],
        'body_earned_order'                            => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>Thanks for your purchase! You earned <strong>{points} {points_label}</strong>.</p><p>Total balance: <strong>{total_points}</strong>.</p><p><a href="{rewards_url}">Redeem your points</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_first_order'                    => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_first_order'                   => [
            'type'    => 'text',
            'default' => esc_html__( 'First order bonus: {points} {points_label} earned!', 'simple-points-and-rewards' ),
        ],
        'body_earned_first_order'                      => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>Awesome! You earned a first order bonus of <strong>{points} {points_label}</strong>.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">Start redeeming</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_nth_order'                      => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_nth_order'                     => [
            'type'    => 'text',
            'default' => esc_html__( 'Milestone bonus: {points} {points_label} earned!', 'simple-points-and-rewards' ),
        ],
        'body_earned_nth_order'                        => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>You hit a milestone and earned <strong>{points} {points_label}</strong> for: <em>{action}</em>.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">See rewards</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_referral'                       => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_referral'                      => [
            'type'    => 'text',
            'default' => esc_html__( 'You earned {points} {points_label} for a referral', 'simple-points-and-rewards' ),
        ],
        'body_earned_referral'                         => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>Your referral just paid off! You earned <strong>{points} {points_label}</strong>.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">Keep earning and redeeming</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_review'                         => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_review'                        => [
            'type'    => 'text',
            'default' => esc_html__( 'Thanks for the review! {points} {points_label} earned', 'simple-points-and-rewards' ),
        ],
        'body_earned_review'                           => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>Thanks for sharing your feedback. You earned <strong>{points} {points_label}</strong> for: <em>{action}</em>.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">Explore rewards</a></p><p>- {site_name}</p>',
        ],
        'enable_earned_birthday'                       => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'subject_earned_birthday'                      => [
            'type'    => 'text',
            'default' => esc_html__( 'Happy Birthday! You earned {points} {points_label}', 'simple-points-and-rewards' ),
        ],
        'body_earned_birthday'                         => [
            'type'    => 'editor',
            'default' => $body_earned_birthday_default,
        ],
        'enable_earned_daily_login'                    => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'subject_earned_daily_login'                   => [
            'type'    => 'text',
            'default' => esc_html__( 'Daily Login Bonus: You earned {points} {points_label}', 'simple-points-and-rewards' ),
        ],
        'body_earned_daily_login'                      => [
            'type'    => 'editor',
            'default' => '<p>Hi {user_name},</p><p>Thanks for logging in today! You earned <strong>{points} {points_label}</strong>.</p><p>Balance: <strong>{total_points}</strong></p><p><a href="{rewards_url}">See rewards</a></p><p>- {site_name}</p>',
        ],
        'levels_enabled'                               => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'levels_starter_name'                          => [
            'type'    => 'text',
            'default' => esc_html__( 'Starter', 'simple-points-and-rewards' ),
        ],
        'levels_starter_badge_icon'                    => [
            'type'    => 'text',
            'default' => $starter_badge_icon_default,
        ],
        'levels_starter_badge_type'                    => [
            'type'    => 'text',
            'default' => $starter_badge_type_default,
        ],
        'levels_starter_badge_text'                    => [
            'type'    => 'text',
            'default' => $starter_badge_text_default,
        ],
        'levels_starter_badge_url'                     => [
            'type'    => 'text',
            'default' => '',
        ],
        'levels_starter_badge_color'                   => [
            'type'    => 'text',
            'default' => '',
        ],
        'levels_starter_badge_color_mode'              => [
            'type'    => 'text',
            'default' => 'default',
        ],
        'levels_starter_custom_benefits'               => [
            'type'    => 'textarea',
            'default' => '',
        ],
        'levels'                                       => [
            'type'    => 'array',
            'default' => [],
        ],
        'levels_points_type'                           => [
            'type'    => 'text',
            'default' => 'total_earned',
        ],
        'rewards'                                      => [
            'type'    => 'array',
            'default' => [],
        ],
        'terms_enabled'                                => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'terms_link_text'                              => [
            'type'    => 'text',
            'default' => '',
        ],
        'terms_show_dashboard'                         => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'terms_show_widget'                            => [
            'type'    => 'checkbox',
            'default' => true,
        ],
        'terms_content'                                => [
            'type'    => 'editor',
            'default' => '',
        ],
    ];
    // Always define PRO earn types in schema so options exist in free, but they remain disabled
    $fields['earn']['first_order'] = [
        'enabled'      => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'points'       => [
            'type'    => 'number',
            'default' => 200,
        ],
        'name'         => [
            'type'    => 'text',
            'default' => esc_html__( 'First Order Bonus', 'simple-points-and-rewards' ),
        ],
        'award_timing' => [
            'type'    => 'select',
            'default' => 'thankyou',
        ],
    ];
    $fields['earn']['nth_order'] = [
        'enabled'     => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'points'      => [
            'type'    => 'number',
            'default' => 500,
        ],
        'recurring'   => [
            'type'    => 'checkbox',
            'default' => false,
        ],
        'order_count' => [
            'type'    => 'number',
            'default' => 5,
        ],
        'name'        => [
            'type'    => 'text',
            'default' => esc_html__( 'Bonus after X Orders', 'simple-points-and-rewards' ),
        ],
    ];
    // WooCommerce Subscriptions integration (tab only shown when WCS is active).
    // The renewal rate mode/percentage/fixed fields are defined for both tiers,
    // but the earning-rate control is PRO-only — free always resolves to 'same'.
    $fields['subscriptions_renewal_enabled'] = [
        'type'    => 'checkbox',
        'default' => true,
    ];
    $fields['subscriptions_renewal_rate_mode'] = [
        'type'    => 'select',
        'default' => 'same',
    ];
    $fields['subscriptions_renewal_percentage'] = [
        'type'    => 'float',
        'default' => 100,
    ];
    $fields['subscriptions_renewal_fixed_points'] = [
        'type'    => 'number',
        'default' => 0,
    ];
    $fields['subscriptions_renewal_award_timing'] = [
        'type'    => 'select',
        'default' => 'follow_order',
    ];
    $fields['subscriptions_signup_fee_include'] = [
        'type'    => 'checkbox',
        'default' => true,
    ];
    $fields['subscriptions_exclude_products'] = [
        'type'    => 'checkbox',
        'default' => false,
    ];
    $fields['subscriptions_count_order_bonuses'] = [
        'type'    => 'checkbox',
        'default' => true,
    ];
    return $fields;
}

function spar_settings_default() {
    $fields = spar_get_option_fields();
    $defaults = [];
    foreach ( $fields as $key => $field_data ) {
        if ( $key === 'earn' ) {
            // Handle nested earn fields.
            foreach ( $field_data as $earn_key => $earn_data ) {
                foreach ( $earn_data as $subkey => $subfield ) {
                    $defaults['earn'][$earn_key][$subkey] = $subfield['default'];
                }
            }
        } else {
            // Handle direct fields.
            $defaults[$key] = $field_data['default'];
        }
    }
    return $defaults;
}

/**
 * Get all settings with defaults applied
 *
 * @param string $option The option key to retrieve
 * 
 * @return mixed The option value or default
 */
function spar_get_options(  $option  ) {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    // Merge with defaults to ensure all keys exist (prevents undefined index notices).
    $options = array_merge( $defaults, $options );
    // Safely return option or default when present
    return $options[$option] ?? $defaults[$option] ?? null;
}

/**
 * Get a grouped option with defaults applied
 *
 * @param string $group The option group (e.g. 'earn')
 * @param string $option The option key within the group (e.g. 'signup')
 * 
 * @return mixed The option value or default
 */
function spar_get_option(  $group, $option  ) {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    // Merge with defaults to ensure all keys exist.
    $options = array_merge( $defaults, $options );
    if ( $group === 'earn' ) {
        // Ensure earn options are properly merged.
        $earn_current = ( isset( $options['earn'][$option] ) && is_array( $options['earn'][$option] ) ? $options['earn'][$option] : [] );
        $earn_default = ( isset( $defaults['earn'][$option] ) && is_array( $defaults['earn'][$option] ) ? $defaults['earn'][$option] : null );
        if ( is_array( $earn_default ) ) {
            $earn_current = array_merge( $earn_default, $earn_current );
        }
        // Return merged earn settings if available, else fall back to default or null.
        return ( !empty( $earn_current ) ? $earn_current : $earn_default ?? null );
    }
    // For backward compatibility, check if the option exists at the top level.
    return $options[$option] ?? $defaults[$option] ?? null;
}

/**
 * Get a single option value by key
 *
 * @param string $option_key The option key to retrieve
 * @return mixed The option value or default
 */
function spar_get_single_option(  $option_key  ) {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    // Merge with defaults to ensure all keys exist
    $options = wp_parse_args( $options, $defaults );
    return ( isset( $options[$option_key] ) ? $options[$option_key] : (( isset( $defaults[$option_key] ) ? $defaults[$option_key] : null )) );
}

/**
 * Check whether reward vouchers are enabled globally.
 */
function spar_rewards_vouchers_enabled() {
    $value = spar_get_single_option( 'rewards_vouchers_enabled' );
    $enabled = ( null === $value ? true : (bool) $value );
    return (bool) apply_filters( 'spar_rewards_vouchers_enabled', $enabled );
}

/**
 * Update a single option value
 *
 * @param string $option_key The option key to update
 * @param mixed $value The new value
 * @return bool True on success, false on failure
 */
function spar_update_single_option(  $option_key, $value  ) {
    $options = get_option( 'spar_options', [] );
    $fields = spar_get_option_fields();
    // Get field type for sanitization.
    $field_type = ( isset( $fields[$option_key]['type'] ) ? $fields[$option_key]['type'] : 'text' );
    $default = ( isset( $fields[$option_key]['default'] ) ? $fields[$option_key]['default'] : '' );
    // Sanitize the value according to its declared type.
    if ( 'rewards_widget_icon' === $option_key ) {
        $options[$option_key] = sanitize_text_field( wp_unslash( $value ) );
    } elseif ( 'rewards_widget_icon_custom_url' === $option_key ) {
        $options[$option_key] = esc_url_raw( wp_unslash( $value ) );
    } elseif ( 'rewards_widget_icon_custom_text' === $option_key ) {
        $options[$option_key] = sanitize_text_field( wp_unslash( $value ) );
    } else {
        $options[$option_key] = spar_sanitize_field_value(
            $value,
            $field_type,
            $default,
            $option_key
        );
    }
    $db_cleaned_paths = array();
    if ( function_exists( 'spar_prepare_settings_options_for_database' ) ) {
        $options = spar_prepare_settings_options_for_database( $options, $db_cleaned_paths );
    }
    if ( function_exists( 'spar_save_settings_option_value' ) ) {
        return spar_save_settings_option_value( $options );
    }
    return update_option( 'spar_options', $options, false );
}

// Removed per request: AJAX handler for updating single options (now relying on form submit only)
/**
 * Enqueue admin scripts for settings page
 */
function spar_enqueue_admin_settings_scripts(  $hook  ) {
    // Only load on our settings page: slug is 'spar-settings'.
    // Be tolerant across WP hook formats and direct page loads.
    $is_settings_page = false !== strpos( (string) $hook, 'spar-settings' );
    if ( !$is_settings_page ) {
        $page = ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
        $is_settings_page = 'spar-settings' === $page;
    }
    if ( !$is_settings_page ) {
        return;
    }
    $asset_version = ( defined( 'SPAR_VERSION' ) ? SPAR_VERSION : '1.1' );
    // Check if the settings.js file exists, if not create inline script
    // Correct path to settings script
    $settings_js_path = plugin_dir_path( __FILE__ ) . '../../../assets/js/admin-settings.js';
    $handle = 'spar-admin-settings-fallback';
    if ( file_exists( $settings_js_path ) ) {
        $handle = 'spar-admin-settings';
        wp_enqueue_script(
            $handle,
            plugin_dir_url( __FILE__ ) . '../../../assets/js/admin-settings.js',
            array('jquery'),
            $asset_version,
            true
        );
    }
    // Always add localized script data for AJAX and UI strings on our script handle.
    wp_localize_script( $handle, 'sparAjax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'spar_settings_nonce' ),
        'strings' => array(
            'saving' => esc_html__( 'Saving...', 'simple-points-and-rewards' ),
            'saved'  => esc_html__( 'Saved!', 'simple-points-and-rewards' ),
            'error'  => esc_html__( 'Error saving option', 'simple-points-and-rewards' ),
        ),
    ) );
    // Media library for image uploads in settings.
    wp_enqueue_media();
    // If the external settings script is missing, load a small fallback file instead of inline JS.
    if ( !file_exists( $settings_js_path ) ) {
        wp_enqueue_script(
            'spar-admin-settings-fallback',
            plugin_dir_url( __FILE__ ) . '../../../assets/js/settings-fallback.js',
            array('jquery'),
            $asset_version,
            true
        );
    }
}

add_action( 'admin_enqueue_scripts', 'spar_enqueue_admin_settings_scripts' );
if ( !function_exists( 'spar_get_product_points_location_options' ) ) {
    /**
     * Retrieve the available display locations for single product points output.
     *
     * @return array<string, array{label:string, hook:string, priority:int}>
     */
    function spar_get_product_points_location_options() {
        $locations = [
            'before_summary'             => [
                'label'    => esc_html__( 'Before product summary', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_before_single_product_summary',
                'priority' => 15,
            ],
            'summary_before_price'       => [
                'label'    => esc_html__( 'Product summary - before price', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_single_product_summary',
                'priority' => 9,
            ],
            'summary_after_price'        => [
                'label'    => esc_html__( 'Product summary - after price (default)', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_single_product_summary',
                'priority' => 25,
            ],
            'summary_before_add_to_cart' => [
                'label'    => esc_html__( 'Product summary - before add to cart button', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_single_product_summary',
                'priority' => 29,
            ],
            'summary_after_add_to_cart'  => [
                'label'    => esc_html__( 'Product summary - after add to cart button', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_single_product_summary',
                'priority' => 35,
            ],
            'after_summary'              => [
                'label'    => esc_html__( 'After product summary', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_after_single_product_summary',
                'priority' => 5,
            ],
            'after_product'              => [
                'label'    => esc_html__( 'After entire product', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_after_single_product',
                'priority' => 5,
            ],
        ];
        /**
         * Allow developers to filter the available product points locations.
         *
         * @param array $locations List of locations keyed by identifier.
         */
        return apply_filters( 'spar_product_points_location_options', $locations );
    }

}
/**
 * Generate a unique reward ID
 */
function spar_generate_reward_id() {
    return 'reward_' . wp_generate_password( 8, false, false );
}

/**
 * Example rewards pre-populated on a fresh install so new users have a starting point.
 *
 * @return array
 */
function spar_get_default_example_rewards() {
    return [[
        'id'             => spar_generate_reward_id(),
        'name'           => esc_html__( '$5 Voucher', 'simple-points-and-rewards' ),
        'points'         => 500,
        'type'           => 'voucher',
        'status'         => 'active',
        'voucher_amount' => 5,
        'discount_type'  => 'fixed_cart',
        'free_shipping'  => false,
        'product_id'     => 0,
    ], [
        'id'             => spar_generate_reward_id(),
        'name'           => esc_html__( '$10 Voucher', 'simple-points-and-rewards' ),
        'points'         => 900,
        'type'           => 'voucher',
        'status'         => 'active',
        'voucher_amount' => 10,
        'discount_type'  => 'fixed_cart',
        'free_shipping'  => false,
        'product_id'     => 0,
    ], [
        'id'             => spar_generate_reward_id(),
        'name'           => esc_html__( 'Free Product', 'simple-points-and-rewards' ),
        'points'         => 1500,
        'type'           => 'product',
        'status'         => 'active',
        'voucher_amount' => 0,
        'discount_type'  => 'fixed_cart',
        'free_shipping'  => false,
        'product_id'     => 0,
    ]];
}

/**
 * Example level pre-populated on a fresh install so new users have a starting point.
 *
 * @return array
 */
function spar_get_default_example_levels() {
    $badge_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( [
        'id' => 'level_1',
    ] ) : 'fa-solid fa-trophy' );
    return [[
        'name'              => esc_html__( 'Bronze Member', 'simple-points-and-rewards' ),
        'badge_icon'        => $badge_icon,
        'badge_type'        => 'preset',
        'required_points'   => 2500,
        'points_multiplier' => 1.1,
    ]];
}

/**
 * Seed example rewards and levels into the settings on a brand new install only.
 *
 * Runs on activation. It writes the examples once so they persist; it never
 * re-adds content on subsequent loads, so deletions made by the user stick.
 */
function spar_maybe_seed_example_content() {
    $options = get_option( 'spar_options' );
    // Only seed a genuinely fresh install (settings never created yet).
    if ( false !== $options ) {
        return;
    }
    $defaults = spar_settings_default();
    $defaults['rewards'] = spar_get_default_example_rewards();
    $defaults['levels'] = spar_get_default_example_levels();
    add_option(
        'spar_options',
        $defaults,
        '',
        false
    );
}

/**
 * Get all active rewards from settings
 */
function spar_get_rewards() {
    if ( !spar_rewards_vouchers_enabled() ) {
        return [];
    }
    $options = get_option( 'spar_options', [] );
    $rewards = $options['rewards'] ?? [];
    // Filter only active rewards and check expiry.
    $filtered = array_filter( $rewards, function ( $reward ) {
        if ( ($reward['status'] ?? 'active') !== 'active' ) {
            return false;
        }
        // Filter out incomplete rewards (drafts)
        if ( empty( $reward['name'] ) || (int) ($reward['points'] ?? 0) <= 0 ) {
            return false;
        }
        return true;
    } );
    return $filtered;
}

/**
 * Get a specific reward by ID
 */
function spar_get_reward_by_id(  $reward_id  ) {
    $options = get_option( 'spar_options', [] );
    $rewards = $options['rewards'] ?? [];
    foreach ( $rewards as $reward ) {
        if ( $reward['id'] === $reward_id ) {
            return $reward;
        }
    }
    return null;
}

/**
 * Check if user can redeem a reward
 */
function spar_can_user_redeem_reward(  $user_id, $reward  ) {
    if ( !$reward || empty( $reward['name'] ) || (int) ($reward['points'] ?? 0) <= 0 ) {
        return false;
    }
    $user_points = spar_get_user_points( $user_id );
    return $user_points >= $reward['points'];
}

if ( !function_exists( 'spar_get_product_loop_points_location_options' ) ) {
    /**
     * Retrieve available display locations for catalog/loop product points output.
     *
     * @return array<string, array{label:string, hook:string, priority:int}>
     */
    function spar_get_product_loop_points_location_options() {
        $locations = [
            'loop_before_item'  => [
                'label'    => esc_html__( 'Catalog item - before product', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_before_shop_loop_item',
                'priority' => 12,
            ],
            'loop_before_title' => [
                'label'    => esc_html__( 'Catalog item - before title', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_before_shop_loop_item_title',
                'priority' => 8,
            ],
            'loop_after_title'  => [
                'label'    => esc_html__( 'Catalog item - after title (default)', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_after_shop_loop_item_title',
                'priority' => 12,
            ],
            'loop_after_item'   => [
                'label'    => esc_html__( 'Catalog item - after product', 'simple-points-and-rewards' ),
                'hook'     => 'woocommerce_after_shop_loop_item',
                'priority' => 15,
            ],
        ];
        /**
         * Allow developers to filter the available product loop points locations.
         *
         * @param array $locations List of locations keyed by identifier.
         */
        return apply_filters( 'spar_product_loop_points_location_options', $locations );
    }

}