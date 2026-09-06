<?php

/**
 * Main settings page with tabs
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Include all settings tab files
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/settings-utilities.php';
// Settings JSON import/export (panel + admin-post handlers)
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/settings-import-export.php';
// Include levels functions before tabs that need them (avoid duplicate includes)
if ( !function_exists( 'spar_get_badge_icons' ) ) {
    $spar_levels_functions_path = SPAR_PLUGIN_PATH . 'includes/functions-levels.php';
    if ( file_exists( $spar_levels_functions_path ) ) {
        require_once $spar_levels_functions_path;
    }
}
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/earn-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/points-label-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/product-pages-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/terms-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/expiry-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/checkout-box-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/account-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/email-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/shortcodes-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/subscriptions-tab.php';
// Sales pages for PRO tabs shown in the free version (always included — no __premium_only suffix).
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/pro-tab-sales-pages.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/levels-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/rewards-tab.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/pro-modules-tab.php';
function spar_settings_page() {
    // The Subscriptions tab is only offered when WooCommerce Subscriptions is active.
    $wcs_active = class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_order_contains_renewal' );
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    // Merge with defaults to ensure all fields have values
    $options = array_merge( $defaults, $options );
    // Ensure nested earn options are properly merged
    if ( isset( $options['earn'] ) && isset( $defaults['earn'] ) ) {
        foreach ( $defaults['earn'] as $earn_key => $earn_defaults ) {
            if ( isset( $options['earn'][$earn_key] ) ) {
                $options['earn'][$earn_key] = array_merge( $earn_defaults, $options['earn'][$earn_key] );
            } else {
                $options['earn'][$earn_key] = $earn_defaults;
            }
        }
    } else {
        $options['earn'] = $defaults['earn'];
    }
    // Apply default settings if options are empty
    if ( empty( get_option( 'spar_options', [] ) ) ) {
        if ( function_exists( 'spar_save_settings_option_value' ) ) {
            $db_cleaned_paths = array();
            if ( function_exists( 'spar_prepare_settings_options_for_database' ) ) {
                $options = spar_prepare_settings_options_for_database( $options, $db_cleaned_paths );
            }
            spar_save_settings_option_value( $options );
        } else {
            update_option( 'spar_options', $options, false );
        }
    }
    $request_method = ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' );
    $content_length = ( isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0 );
    $settings_post_data = array();
    if ( 'POST' === $request_method ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw settings payload is nonce-checked below and sanitized field-by-field during save.
        $settings_post_data = ( function_exists( 'spar_get_settings_post_data' ) ? spar_get_settings_post_data( $_POST ) : $_POST );
    }
    if ( 'POST' === $request_method && $content_length > 0 && empty( $settings_post_data ) ) {
        echo '<div class="error"><p>' . esc_html__( 'Error: Settings payload was empty. The request may exceed the server post_max_size limit or be blocked by a security rule.', 'simple-points-and-rewards' ) . '</p></div>';
    }
    if ( isset( $settings_post_data['spar_save_settings'] ) && current_user_can( 'manage_woocommerce' ) ) {
        $settings_nonce = ( isset( $settings_post_data['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $settings_post_data['_wpnonce'] ) ) : '' );
        if ( !wp_verify_nonce( $settings_nonce, 'spar_save_settings_action' ) ) {
            echo '<div class="error"><p>' . esc_html__( 'Error: Security check failed. Please refresh the page and try again.', 'simple-points-and-rewards' ) . '</p></div>';
        } else {
            $save_result = spar_process_settings_save( $settings_post_data );
            if ( is_wp_error( $save_result ) ) {
                echo '<div class="error"><p>' . esc_html( $save_result->get_error_message() ) . '</p></div>';
            } elseif ( $save_result ) {
                echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'simple-points-and-rewards' ) . '</p></div>';
            }
        }
    }
    ?>
	
	<?php 
    spar_render_admin_header( esc_html__( 'Settings', 'simple-points-and-rewards' ) );
    ?>

	<div class="wrap spar-settings-wrap">
		<?php 
    if ( function_exists( 'spar_options_table_supports_emoji' ) && !spar_options_table_supports_emoji() ) {
        ?>
			<div class="notice notice-warning inline spar-settings-emoji-notice">
				<p>
					<strong><?php 
        esc_html_e( 'Emoji support is limited by this database.', 'simple-points-and-rewards' );
        ?></strong>
					<?php 
        esc_html_e( 'The WordPress options table cannot store 4-byte Unicode characters, so emoji icons or emoji-only custom text in these settings will be converted to plain text when saved. To preserve emojis, ask your host to convert the options table option_value column to utf8mb4.', 'simple-points-and-rewards' );
        ?>
				</p>
			</div>
		<?php 
    }
    ?>

		<?php 
    // Determine if we should show the setup guide.
    // Logic: show when no settings have ever been saved OR the first saved timestamp is within last 24 hours,
    // unless the current user has permanently dismissed it.
    $show_setup_guide = false;
    $current_user_id = get_current_user_id();
    if ( !get_user_meta( $current_user_id, 'spar_hide_setup_guide', true ) ) {
        $first_saved_timestamp = (int) get_option( 'spar_user_settings_first_saved', 0 );
        if ( empty( get_option( 'spar_options', [] ) ) ) {
            // No settings yet at all.
            $show_setup_guide = true;
        } else {
            if ( !empty( $first_saved_timestamp ) ) {
                // Show if first saved less than or equal to 24h ago (backwards compatible)
                if ( current_time( 'timestamp' ) - $first_saved_timestamp <= DAY_IN_SECONDS ) {
                    $show_setup_guide = true;
                }
            } else {
                // Legacy install: settings exist but timestamp not recorded yet -> show guide to help onboarding.
                $show_setup_guide = true;
            }
        }
    }
    ?>

		<?php 
    if ( $show_setup_guide ) {
        ?>
		<div class="spar-setup-guide" id="spar-setup-guide" aria-labelledby="spar-setup-guide-title">
			<div class="spar-setup-guide-header">
				<h2 id="spar-setup-guide-title" class="spar-setup-guide-title"><?php 
        esc_html_e( 'Quick Setup Guide', 'simple-points-and-rewards' );
        ?></h2>
				<button type="button" id="spar-hide-setup-guide" class="button button-secondary" aria-label="<?php 
        esc_attr_e( 'Hide setup guide permanently', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Hide', 'simple-points-and-rewards' );
        ?></button>
			</div>
			<p class="spar-setup-guide-intro"><?php 
        esc_html_e( 'Follow these steps to configure your rewards program.', 'simple-points-and-rewards' );
        ?></p>
			<div class="spar-setup-guide-row" role="list">
				<div role="listitem" class="spar-setup-guide-step">
					<span class="spar-step-number" aria-hidden="true">1</span>
					<h3><?php 
        esc_html_e( 'Configure Rewards', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Add the rewards that customers can claim using their reward points.', 'simple-points-and-rewards' );
        ?></p>
					<div class="spar-setup-guide-actions">
						<button type="button" class="button button-secondary spar-setup-btn spar-switch-tab" data-target-tab="rewards" aria-label="<?php 
        echo esc_attr__( 'Open Rewards tab', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Open Rewards', 'simple-points-and-rewards' );
        ?></button>
					</div>
				</div>
				<div role="listitem" class="spar-setup-guide-step">
					<span class="spar-step-number" aria-hidden="true">2</span>
					<h3><?php 
        esc_html_e( 'Set Earning Methods', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Choose how customers earn points (orders, signup, referrals, etc).', 'simple-points-and-rewards' );
        ?></p>
					<div class="spar-setup-guide-actions">
						<button type="button" class="button button-secondary spar-setup-btn spar-switch-tab" data-target-tab="earn" aria-label="<?php 
        echo esc_attr__( 'Open Ways to Earn tab', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Open Ways to Earn', 'simple-points-and-rewards' );
        ?></button>
					</div>
				</div>
				<div role="listitem" class="spar-setup-guide-step">
					<span class="spar-step-number" aria-hidden="true">3</span>
					<h3><?php 
        esc_html_e( 'Add Levels & Badges', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Encourage engagement with tiered badges and point multipliers.', 'simple-points-and-rewards' );
        ?></p>
					<div class="spar-setup-guide-actions">
						<button type="button" class="button button-secondary spar-setup-btn spar-switch-tab" data-target-tab="levels" aria-label="<?php 
        echo esc_attr__( 'Open Levels & Badges tab', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Open Levels', 'simple-points-and-rewards' );
        ?></button>
					</div>
				</div>
				<div role="listitem" class="spar-setup-guide-step">
					<span class="spar-step-number" aria-hidden="true">4</span>
					<h3><?php 
        esc_html_e( 'Configure Dashboard', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Setup and configure the customer rewards dashboard for customers to view points and claim rewards.', 'simple-points-and-rewards' );
        ?></p>
					<div class="spar-setup-guide-actions">
						<button type="button" class="button button-secondary spar-setup-btn spar-switch-tab" data-target-tab="account" aria-label="<?php 
        echo esc_attr__( 'Open Rewards Dashboard tab', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Open Dashboard Settings', 'simple-points-and-rewards' );
        ?></button>
					</div>
				</div>
				<div role="listitem" class="spar-setup-guide-step">
					<span class="spar-step-number" aria-hidden="true">5</span>
					<h3><?php 
        esc_html_e( 'View Analytics', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Track customer points performance, activity logs, and referral metrics.', 'simple-points-and-rewards' );
        ?></p>
					<div class="spar-setup-guide-actions">
						<a class="button button-secondary spar-setup-btn" href="<?php 
        echo esc_url( admin_url( 'admin.php?page=spar-reports' ) );
        ?>" target="_blank" rel="noopener" aria-label="<?php 
        echo esc_attr__( 'Open Reports & Analytics page in a new tab', 'simple-points-and-rewards' );
        ?>"><?php 
        esc_html_e( 'Open Analytics', 'simple-points-and-rewards' );
        ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php 
    }
    ?>

		<div class="spar-settings-container">
			<div class="spar-settings-main <?php 
    echo ( spar_fs()->can_use_premium_code__premium_only() ? 'pro' : '' );
    ?>">
				<div class="spar-settings-two-col">
					<div class="spar-tabs-sidebar">
						<nav class="nav-tab-wrapper spar-vertical-tabs" aria-label="<?php 
    echo esc_attr__( 'Settings tabs', 'simple-points-and-rewards' );
    ?>">
							<a href="#" class="nav-tab nav-tab-active" data-tab="rewards"><?php 
    esc_html_e( 'Rewards', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="earn"><?php 
    esc_html_e( 'Ways to Earn', 'simple-points-and-rewards' );
    ?></a>
							<?php 
    ?>
							<a href="#" class="nav-tab" data-tab="levels"><?php 
    esc_html_e( 'Levels & Badges', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="account"><?php 
    esc_html_e( 'Rewards Dashboard', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="checkout-box"><?php 
    esc_html_e( 'Checkout Box', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="points-label"><?php 
    esc_html_e( 'Points Label', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="product-pages"><?php 
    esc_html_e( 'Product Pages', 'simple-points-and-rewards' );
    ?></a>
							<a href="#" class="nav-tab" data-tab="terms"><?php 
    esc_html_e( 'Terms and Conditions', 'simple-points-and-rewards' );
    ?></a>
							<?php 
    if ( $wcs_active ) {
        ?>
							<a href="#" class="nav-tab" data-tab="subscriptions"><?php 
        esc_html_e( 'Subscriptions', 'simple-points-and-rewards' );
        ?></a>
							<?php 
    }
    ?>
						<a href="#" class="nav-tab" data-tab="email"><?php 
    esc_html_e( 'Email Notifications', 'simple-points-and-rewards' );
    ?></a>
							<?php 
    ?>
							<a href="#" class="nav-tab spar-pro-tab" data-tab="conditional-rules"><?php 
    esc_html_e( 'Conditional Rules', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
							<a href="#" class="nav-tab spar-pro-tab" data-tab="points-delay"><?php 
    esc_html_e( 'Points Delay', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
							<a href="#" class="nav-tab spar-pro-tab" data-tab="expiry"><?php 
    esc_html_e( 'Points Expiry', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
							<?php 
    ?>
							<?php 
    ?>
						<a href="#" class="nav-tab spar-pro-tab" data-tab="guest-customers"><?php 
    esc_html_e( 'Guest Points', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
						<a href="#" class="nav-tab spar-pro-tab" data-tab="referral-offers"><?php 
    esc_html_e( 'Referral Coupons', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
						<a href="#" class="nav-tab spar-pro-tab" data-tab="gift-widget"><?php 
    esc_html_e( 'Gift Widget', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
						<a href="#" class="nav-tab spar-pro-tab" data-tab="rewards-widget"><?php 
    esc_html_e( 'Rewards Widget', 'simple-points-and-rewards' );
    ?> <span class="spar-pro-badge-nav" aria-label="<?php 
    esc_attr_e( 'PRO feature', 'simple-points-and-rewards' );
    ?>">PRO</span></a>
							<?php 
    ?>
							<a href="#" class="nav-tab" data-tab="shortcodes"><?php 
    esc_html_e( 'Shortcodes', 'simple-points-and-rewards' );
    ?></a>
						<a href="#" class="nav-tab" data-tab="pro-modules"><?php 
    esc_html_e( 'PRO Features', 'simple-points-and-rewards' );
    ?></a>
						</nav>
					</div>
					<div class="spar-tabs-content">
						<form method="post" id="spar-settings-form" novalidate>
							<?php 
    wp_nonce_field( 'spar_save_settings_action' );
    ?>
							<input type="hidden" name="spar_save_settings" value="1" />
							<div id="spar-settings-tabs">
								<div class="spar-settings-tab" data-tab="rewards">
									<?php 
    spar_settings_tab_rewards();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="earn">
									<?php 
    spar_settings_tab_earn();
    ?>
								</div>
							<?php 
    ?>
								<div class="spar-settings-tab spar-hidden" data-tab="conditional-rules">
									<?php 
    spar_pro_sales_page_conditional_rules();
    ?>
								</div>
							<?php 
    ?>
								<div class="spar-settings-tab spar-hidden" data-tab="levels">
									<?php 
    spar_settings_tab_levels();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="checkout-box">
									<?php 
    if ( function_exists( 'spar_settings_tab_checkout_box' ) ) {
        spar_settings_tab_checkout_box();
    }
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="points-label">
									<?php 
    spar_settings_tab_points_label();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="product-pages">
									<?php 
    spar_settings_tab_product_pages();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="terms">
									<?php 
    spar_settings_tab_terms();
    ?>
								</div>
							<?php 
    ?>
						<div class="spar-settings-tab spar-hidden" data-tab="points-delay">
							<?php 
    spar_pro_sales_page_points_delay();
    ?>
						</div>
							<div class="spar-settings-tab spar-hidden" data-tab="expiry">
								<?php 
    spar_pro_sales_page_expiry();
    ?>
							</div>
							<?php 
    ?>
								<div class="spar-settings-tab spar-hidden" data-tab="account">
									<?php 
    spar_settings_tab_account();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="email">
									<?php 
    spar_settings_tab_email();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="shortcodes">
									<?php 
    if ( function_exists( 'spar_settings_tab_shortcodes' ) ) {
        spar_settings_tab_shortcodes();
    }
    ?>
								</div>
								<?php 
    ?>
								<div class="spar-settings-tab spar-hidden" data-tab="referral-offers">
									<?php 
    spar_pro_sales_page_referral_coupons();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="gift-widget">
									<?php 
    spar_pro_sales_page_gift_widget();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="rewards-widget">
									<?php 
    spar_pro_sales_page_rewards_widget();
    ?>
								</div>
								<div class="spar-settings-tab spar-hidden" data-tab="guest-customers">
									<?php 
    spar_pro_sales_page_guest_tracking();
    ?>
								</div>
								<?php 
    ?>
								<?php 
    if ( $wcs_active ) {
        ?>
								<div class="spar-settings-tab spar-hidden" data-tab="subscriptions">
									<?php 
        if ( function_exists( 'spar_settings_tab_subscriptions' ) ) {
            spar_settings_tab_subscriptions();
        }
        ?>
								</div>
								<?php 
    }
    ?>
								<div class="spar-settings-tab spar-hidden" data-tab="pro-modules">
									<?php 
    if ( function_exists( 'spar_settings_tab_pro_modules' ) ) {
        spar_settings_tab_pro_modules();
    }
    ?>
								</div>
							</div>
							<br/>
							<p class="spar-save-actions">
								<input type="hidden" name="spar_save_settings" value="1" />
								<input type="hidden" name="spar_form_end" value="1" />
								<input type="submit" name="spar_save_settings" class="button button-primary button-hero spar-save-btn"
								value="<?php 
    esc_attr_e( 'Save All Settings', 'simple-points-and-rewards' );
    ?>" />
							</p>
							
						</form>
					</div>
				</div>

				<?php 
    if ( function_exists( 'spar_settings_render_import_export_panel' ) ) {
        spar_settings_render_import_export_panel();
    }
    ?>

				<br/><br/><br/><br/>

				<div class="spar-settings-footer" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
							
							<p class="spar-settings-attribution" style="margin-top: 0; margin-bottom: 0;">
								<?php 
    echo wp_kses_post( sprintf( __( 'Developed by <a href="%1$s" target="_blank" rel="noopener">RelyWP</a>', 'simple-points-and-rewards' ), 'https://relywp.com/plugins/simple-points-and-rewards/' ) );
    ?>
							</p>
							<br/>
								<div class="spar-ad-boxes">
									<!-- Get support button -->
									<div class="spar-ad-box">
										<h4><?php 
    esc_html_e( 'Need Help or Have a Suggestion?', 'simple-points-and-rewards' );
    ?></h4>
										<p><?php 
    esc_html_e( 'Visit our support forum for assistance with any questions or issues you are having, or to submit a feature request for the plugin.', 'simple-points-and-rewards' );
    ?></p>
										<a href="https://wordpress.org/support/plugin/simple-points-and-rewards/"
											target="_blank" class="button button-secondary button-hero">
											<?php 
    esc_html_e( 'Get Support', 'simple-points-and-rewards' );
    ?>
										</a>
									</div>
									<!-- Documentation button -->
									<div class="spar-ad-box">
										<h4><?php 
    esc_html_e( 'Read the Documentation', 'simple-points-and-rewards' );
    ?></h4>
										<p><?php 
    esc_html_e( 'Explore our documentation to get the most out of the plugin. Learn how to set up and configure all features.', 'simple-points-and-rewards' );
    ?></p>
										<a href="https://relywp.com/docs/simple-points-rewards-woocommerce/"
											target="_blank" class="button button-secondary button-hero">
											<?php 
    esc_html_e( 'View Documentation', 'simple-points-and-rewards' );
    ?>
										</a>
									</div>
									<?php 
    ?>
									<!-- Ad for upgrading to PRO -->
									<div class="spar-ad-box">
										<h4><?php 
    esc_html_e( 'Unlock More Features with PRO', 'simple-points-and-rewards' );
    ?></h4>
										<p><?php 
    esc_html_e( 'Upgrade to the PRO version to access powerful features like referral coupons, gift widget, rewards widget, conditional earning rules, points expiry, and much more!', 'simple-points-and-rewards' );
    ?></p>
										<a href="https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing" target="_blank" class="button button-secondary button-hero">
											<?php 
    esc_html_e( 'Get 25% Off + Free Trial', 'simple-points-and-rewards' );
    ?>
										</a>
									</div>
									<?php 
    ?>
								</div>
							</div>

			</div>

			<?php 
    if ( spar_fs()->is_free_plan() ) {
        ?>
			<div class="spar-settings-sidebar">
				<div class="spar-pro-sidebar">
					<h3>🚀 <?php 
        esc_html_e( 'Upgrade to PRO', 'simple-points-and-rewards' );
        ?></h3>
					<p><?php 
        esc_html_e( 'Unlock powerful premium features to take your rewards program to the next level!', 'simple-points-and-rewards' );
        ?></p>
					
					<a href="https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing"
					class="button button-primary button-hero spar-upgrade-btn">
						<?php 
        esc_html_e( 'Start Free 7 Day Trial', 'simple-points-and-rewards' );
        ?>
					</a>

					<div class="spar-pro-features">
						<h4><?php 
        esc_html_e( 'Premium Features:', 'simple-points-and-rewards' );
        ?></h4>
						<ul>
							<li>✅ <?php 
        esc_html_e( 'Add Unlimited Rewards', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Add Unlimited Levels', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Floating Rewards Widget', 'simple-points-and-rewards' );
        ?></li>
                            <li>✅ <?php 
        esc_html_e( 'Referral Gift Coupons', 'simple-points-and-rewards' );
        ?></li>
                            <li>✅ <?php 
        esc_html_e( 'Floating Gift Widget', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Conditional Earning Rules', 'simple-points-and-rewards' );
        ?></li>
							<li><strong><?php 
        esc_html_e( 'Extra ways to earn', 'simple-points-and-rewards' );
        ?>:</strong></li>
							<li>✅ <?php 
        esc_html_e( 'First Order Bonus', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Nth Order Bonus', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Write a Review', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Birthday Rewards', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Social Sharing', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Daily Login Bonus', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Login Streak Bonus', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Prize Wheel Spins', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Product Offers & Bonuses', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Custom Ways with Code', 'simple-points-and-rewards' );
        ?></li>
							<li><strong><?php 
        esc_html_e( 'Other Features', 'simple-points-and-rewards' );
        ?>:</strong></li>
							<li>✅ <?php 
        esc_html_e( 'Guest Customer Points', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Multi-Currency Support', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Automatic Points Expiry', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Automatic Voucher Expiry', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Auto-Delete Used Vouchers', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Auto-Delete Expired Vouchers', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Woo Subscriptions Support', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'Priority Support', 'simple-points-and-rewards' );
        ?></li>
							<li>✅ <?php 
        esc_html_e( 'All Features & Updates', 'simple-points-and-rewards' );
        ?></li>
						</ul>
					</div>

					<div class="spar-pro-pricing">
						<div class="spar-price">
							<span class="spar-currency">$</span>
							<span class="spar-amount">99</span>
							<span class="spar-period">/ year</span>
						</div>
						<p class="spar-price-note"><?php 
        esc_html_e( 'Lifetime license also available!', 'simple-points-and-rewards' );
        ?></p>
					</div>

					<?php 
        $blackfridaystart = strtotime( '2026-11-12 00:00:00' );
        $blackfridayend = strtotime( '2026-12-02 23:59:59' );
        $now = current_time( 'timestamp' );
        if ( $now >= $blackfridaystart && $now <= $blackfridayend ) {
            ?>
					<!-- Black Friday Discount -->
					<div class="spar-discount spar-text-center spar-mt--10">
						<p class="spar-price-note" style="font-size: 17px;"><?php 
            esc_html_e( 'Black Friday Deal! Get 30% off with code: BF2026', 'simple-points-and-rewards' );
            ?></p>
					<?php 
        } else {
            ?>
					<!-- 25% Discount -->
					<div class="spar-discount spar-text-center spar-mt--10">
						<p class="spar-price-note" style="font-size: 15px;"><?php 
            esc_html_e( 'Get 25% off with code: DASH25', 'simple-points-and-rewards' );
            ?></p>
					</div>
					<?php 
        }
        ?>

					<br/>

					<a href="https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing"
					class="button button-primary button-hero spar-upgrade-btn">
						<?php 
        esc_html_e( 'Start Free 7 Day Trial', 'simple-points-and-rewards' );
        ?>
					</a>
					
					<div class="spar-guarantee">
						<p><span class="dashicons dashicons-shield"></span> <?php 
        esc_html_e( 'Free Trial + 14-day money-back guarantee!', 'simple-points-and-rewards' );
        ?></p>
					</div>
				</div>
			</div>
			<?php 
    }
    ?>
		</div>
	</div>
	
	<?php 
}

/**
 * Load levels functions on frontend when needed
 */
function spar_load_levels_functions() {
    if ( !function_exists( 'spar_get_badge_icons' ) ) {
        $levels_functions_path = SPAR_PLUGIN_PATH . 'includes/functions-levels.php';
        if ( file_exists( $levels_functions_path ) ) {
            require_once $levels_functions_path;
        }
    }
}

add_action( 'wp_loaded', 'spar_load_levels_functions' );