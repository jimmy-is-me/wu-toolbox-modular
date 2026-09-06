<?php

/**
 * PRO Modules tab for settings page
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_pro_modules() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    // Ensure referral sub-options exist for reading current state
    if ( !isset( $options['earn']['referral'] ) ) {
        $options['earn']['referral'] = $defaults['earn']['referral'];
    }
    $ref = array_merge( $defaults['earn']['referral'], $options['earn']['referral'] );
    // Ensure extra earn methods exist for reading current state
    $earn_defaults = ( isset( $defaults['earn'] ) && is_array( $defaults['earn'] ) ? $defaults['earn'] : array() );
    $earn_current = ( isset( $options['earn'] ) && is_array( $options['earn'] ) ? $options['earn'] : array() );
    $earn = array_merge( $earn_defaults, $earn_current );
    // Normalize each expected key to avoid undefined indexes
    foreach ( array(
        'first_order',
        'nth_order',
        'review',
        'birthday'
    ) as $k ) {
        if ( !isset( $earn[$k] ) ) {
            $earn[$k] = ( isset( $earn_defaults[$k] ) ? $earn_defaults[$k] : array() );
        } else {
            $earn[$k] = array_merge( ( isset( $earn_defaults[$k] ) ? $earn_defaults[$k] : array() ), $earn[$k] );
        }
    }
    ?>
	<h3><?php 
    esc_html_e( 'PRO Features', 'simple-points-and-rewards' );
    ?></h3>
	<p><?php 
    esc_html_e( 'Review the premium modules available in Simple Points & Rewards and open their settings from one place.', 'simple-points-and-rewards' );
    ?></p>

    <div class="spar-pro-modules-list" style="display:flex; flex-wrap:wrap; gap:20px;">
        <?php 
    $modules = array(
        array(
            'slug'         => 'conditional_rules',
            'title'        => esc_html__( 'Conditional Rules', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Create advanced rules to control when points are earned or redeemed based on cart contents, products, categories, user roles, purchase history, and more.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'ajax'         => false,
            'settings_tab' => 'conditional-rules',
        ),
        array(
            'slug'         => 'rewards_widget',
            'title'        => esc_html__( 'Floating Rewards Widget', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Show a floating rewards widget across your store that highlights a customer\'s points balance, ways to earn, and available rewards.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'rewards_widget_enabled',
            'checked'      => !empty( $options['rewards_widget_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'rewards-widget',
        ),
        array(
            'slug'         => 'gift_widget',
            'title'        => esc_html__( 'Floating Gift Widget', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Display a floating gift widget for referral invites and gift coupons to encourage sharing and improve conversions on landing pages.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'referral_gift_widget_enabled',
            'checked'      => !empty( $ref['gift_widget_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'gift-widget',
        ),
        array(
            'slug'         => 'referral_coupons',
            'title'        => esc_html__( 'Referral Coupons', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Improve the referral system with configurable referral coupons, discounts that drive word-of-mouth growth.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'referral_offer_enabled',
            'checked'      => !empty( $ref['offer_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'referral-offers',
        ),
        array(
            'slug'         => 'unlimited_rewards',
            'title'        => esc_html__( 'Unlimited Rewards', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Remove the free plan limit of 3 rewards and create as many reward options as your program needs.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'settings_tab' => 'rewards',
        ),
        array(
            'slug'         => 'levels_badges',
            'title'        => esc_html__( 'Unlimited Levels & Badges', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Introduce gamification with tiers and badges. Unlock perks, motivate repeat purchases, and celebrate milestones.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'levels_enabled',
            'checked'      => !empty( $options['levels_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'levels',
        ),
        // Extra ways to earn (PRO)
        array(
            'slug'         => 'first_order_bonus',
            'title'        => esc_html__( 'First Order Bonus', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Award a one-time bonus when a customer completes their first order.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'first_order_enabled',
            'checked'      => !empty( $earn['first_order']['enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'earn',
        ),
        array(
            'slug'         => 'nth_order_bonus',
            'title'        => esc_html__( 'Nth Order Bonus', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Give a bonus when customers hit an order milestone (e.g. 5th order).', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'nth_order_enabled',
            'checked'      => !empty( $earn['nth_order']['enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'earn',
        ),
        array(
            'slug'         => 'write_a_review',
            'title'        => esc_html__( 'Write a Review', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Award points when customers write product reviews (on approval).', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'review_enabled',
            'checked'      => !empty( $earn['review']['enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'earn',
        ),
        array(
            'slug'         => 'birthday_rewards',
            'title'        => esc_html__( 'Birthday Rewards', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Celebrate customers with birthday points. Configure on the Earn tab.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'birthday_enabled',
            'checked'      => !empty( $earn['birthday']['enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'earn',
        ),
        array(
            'slug'         => 'product_offers',
            'title'        => esc_html__( 'Product Offers & Bonuses', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Create special product offers that reward customers with bonus points for purchasing specific products or bundles.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'buy_products_enabled',
            'checked'      => !empty( $options['buy_products_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'earn',
        ),
        array(
            'slug'         => 'guest_customers',
            'title'        => esc_html__( 'Guest Customer Points', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Track points for guest checkout orders and automatically migrate them when the customer registers or logs in.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'guest_tracking_enabled',
            'checked'      => !empty( $options['guest_tracking_enabled'] ),
            'ajax'         => false,
            'settings_tab' => 'guest-customers',
        ),
        array(
            'slug'  => 'multi_currency',
            'title' => esc_html__( 'Multi-Currency Support', 'simple-points-and-rewards' ),
            'desc'  => esc_html__( 'Configure different points earning rates for multiple currencies, ensuring fairness in global stores.', 'simple-points-and-rewards' ),
            'type'  => 'info',
        ),
        array(
            'slug'  => 'automatic_points_expiry',
            'title' => esc_html__( 'Automatic Points Expiry', 'simple-points-and-rewards' ),
            'desc'  => esc_html__( 'Automatically expire points after a set period to encourage timely redemptions and repeat orders.', 'simple-points-and-rewards' ),
            'type'  => 'info',
        ),
        array(
            'slug'         => 'reward_expiry',
            'title'        => esc_html__( 'Reward Expiry', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Set expiry dates on rewards to create urgency and run limited-time promotions that drive timely redemptions.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'settings_tab' => 'rewards',
        ),
        array(
            'slug'         => 'voucher_expiry',
            'title'        => esc_html__( 'Voucher Expiry (Coupons)', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Automatically add expiration to generated voucher coupons so old codes don\'t linger and confuse customers.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'settings_tab' => 'expiry',
        ),
        array(
            'slug'         => 'auto_delete_used',
            'title'        => esc_html__( 'Auto-delete Used Vouchers', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Keep your store tidy by removing voucher coupons right after they\'re redeemed, no more manual cleanup.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'auto_delete_used',
            'checked'      => !empty( $options['auto_delete_used'] ),
            'ajax'         => false,
            'settings_tab' => 'expiry',
        ),
        array(
            'slug'         => 'auto_delete_expired',
            'title'        => esc_html__( 'Auto-delete Expired Vouchers', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Run daily cleanups to remove expired voucher coupons and reduce database clutter automatically.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'auto_delete_expired',
            'checked'      => !empty( $options['auto_delete_expired'] ),
            'ajax'         => false,
            'settings_tab' => 'expiry',
        ),
        array(
            'slug'         => 'auto_delete_expired_rewards',
            'title'        => esc_html__( 'Auto-delete Expired Rewards', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Automatically purge rewards that have passed their expiry date to keep lists accurate and up-to-date.', 'simple-points-and-rewards' ),
            'type'         => 'toggle',
            'input_name'   => 'auto_delete_expired_rewards',
            'checked'      => !empty( $options['auto_delete_expired_rewards'] ),
            'ajax'         => false,
            'settings_tab' => 'expiry',
        ),
        array(
            'slug'         => 'future_features',
            'title'        => esc_html__( 'All Future Features', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Gain unlimited access to all upcoming features and enhancements.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'settings_tab' => '',
        ),
        array(
            'slug'         => 'priority_support',
            'title'        => esc_html__( 'Priority Support', 'simple-points-and-rewards' ),
            'desc'         => esc_html__( 'Get faster responses and hands-on help from our support team with an active PRO license.', 'simple-points-and-rewards' ),
            'type'         => 'info',
            'settings_tab' => '',
        ),
    );
    foreach ( $modules as $module ) {
        ?>
            <div class="spar-pro-module-box" style="flex:1 1 22%; min-width:220px; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:18px; margin-bottom:18px; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 8px; font-size:1.1em; font-weight:600; color:#0073aa;">
                    <?php 
        echo esc_html( $module['title'] );
        ?>
                    <span class="spar-premium-settings-badge-small" style="font-size:.8em; background:#f7c52f; color:#222; padding:2px 7px; border-radius:4px; margin-left:6px; vertical-align:middle;">PRO</span>
                </h4>
                <p style="margin-top:0; margin-bottom:14px; color:#444; font-size:.97em;">
                    <?php 
        echo esc_html( $module['desc'] );
        ?>
                </p>
                <?php 
        ?>
                    <a class="button button-primary spar-upgrade-btn"
                    href="https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing"
                    target="_blank" rel="noopener noreferrer"
                    style="margin-top:auto; display:inline-block; padding: 0 !important;">
                        <?php 
        esc_html_e( 'Upgrade to PRO', 'simple-points-and-rewards' );
        ?>
                    </a>
                <?php 
        ?>
            </div>
        <?php 
    }
    ?>
    </div>
    <?php 
}
