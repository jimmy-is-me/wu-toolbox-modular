<?php

/**
 * Email Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_email() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $earn_options = ( isset( $options['earn'] ) && is_array( $options['earn'] ) ? $options['earn'] : [] );
    $rewards_label = $options['rewards_label'] ?? esc_html__( 'Rewards', 'simple-points-and-rewards' );
    ?>
	<h3><?php 
    esc_html_e( 'Email Notifications', 'simple-points-and-rewards' );
    ?></h3>
	<p><?php 
    esc_html_e( 'Configure email notifications sent to users when they earn points or claim vouchers.', 'simple-points-and-rewards' );
    ?></p>
	
	<div class="spar-accordion">
		<div class="spar-accordion-header">
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="enable_earned" <?php 
    checked( !empty( $options['enable_earned'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<?php 
    esc_html_e( 'Earned Points Email', 'simple-points-and-rewards' );
    ?>
			</label>
		</div>
		<div class="spar-accordion-body">
			<p style="margin: 0;"><strong><?php 
    esc_html_e( 'Subject:', 'simple-points-and-rewards' );
    ?></strong></p>
			<p><input type="text" name="subject_earned" value="<?php 
    echo esc_attr( $options['subject_earned'] );
    ?>" placeholder="<?php 
    esc_attr_e( 'Email subject', 'simple-points-and-rewards' );
    ?>" class="spar-full-width" /></p>

			<p style="margin-bottom: -20px;"><strong><?php 
    esc_html_e( 'Email Body:', 'simple-points-and-rewards' );
    ?></strong></p>
			<?php 
    wp_editor( $options['body_earned'], 'body_earned', [
        'textarea_rows' => 8,
        'media_buttons' => false,
    ] );
    ?>
			
			<p><button type="button" class="button" onclick="sparPreviewEmail('earned')"><?php 
    esc_html_e( 'Send Preview Email', 'simple-points-and-rewards' );
    ?></button></p>
		</div>
	</div>

	<h4 style="margin-top:24px;"><?php 
    esc_html_e( 'Per-Type Earned Emails', 'simple-points-and-rewards' );
    ?></h4>

	<?php 
    $earned_types = [
        'signup'      => esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
        'order'       => esc_html__( 'Order Points', 'simple-points-and-rewards' ),
        'first_order' => esc_html__( 'First Order Bonus (PRO)', 'simple-points-and-rewards' ),
        'nth_order'   => esc_html__( 'Nth Order Bonus (PRO)', 'simple-points-and-rewards' ),
        'referral'    => esc_html__( 'Referral Bonus', 'simple-points-and-rewards' ),
        'review'      => esc_html__( 'Write a Review (PRO)', 'simple-points-and-rewards' ),
        'birthday'    => esc_html__( 'Birthday Bonus (PRO)', 'simple-points-and-rewards' ),
        'daily_login' => esc_html__( 'Daily Login Bonus (PRO)', 'simple-points-and-rewards' ),
    ];
    foreach ( $earned_types as $type_key => $label ) {
        $way_enabled = !empty( $earn_options[$type_key]['enabled'] );
        $en_key = 'enable_earned_' . $type_key;
        $su_key = 'subject_earned_' . $type_key;
        $bo_key = 'body_earned_' . $type_key;
        ?>
		<div class="spar-accordion spar-email-per-type" id="spar-email-earned-<?php 
        echo esc_attr( $type_key );
        ?>" data-earned-type="<?php 
        echo esc_attr( $type_key );
        ?>" <?php 
        echo ( $way_enabled ? '' : 'style="display:none;"' );
        ?>>
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="<?php 
        echo esc_attr( $en_key );
        ?>" <?php 
        checked( !empty( $options[$en_key] ) );
        ?> />
						<span class="spar-toggle-slider"></span>
					</div>
					<?php 
        echo esc_html( $label );
        ?>
				</label>
			</div>
			<div class="spar-accordion-body">
				<p style="margin: 0;"><strong><?php 
        esc_html_e( 'Subject:', 'simple-points-and-rewards' );
        ?></strong></p>
				<p><input type="text" name="<?php 
        echo esc_attr( $su_key );
        ?>" value="<?php 
        echo esc_attr( $options[$su_key] );
        ?>" placeholder="<?php 
        esc_attr_e( 'Email subject', 'simple-points-and-rewards' );
        ?>" class="spar-full-width" /></p>

				<p style="margin-bottom: -20px;"><strong><?php 
        esc_html_e( 'Email Body:', 'simple-points-and-rewards' );
        ?></strong></p>
				<?php 
        wp_editor( $options[$bo_key], $bo_key, [
            'textarea_rows' => 8,
            'media_buttons' => false,
        ] );
        ?>

				<p><button type="button" class="button" onclick="sparPreviewEmail('<?php 
        echo esc_attr( 'earned_' . $type_key );
        ?>')"><?php 
        esc_html_e( 'Send Preview Email', 'simple-points-and-rewards' );
        ?></button></p>
			</div>
		</div>
		<?php 
    }
    ?>
	<div class="spar-accordion">
		<div class="spar-accordion-header">
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="enable_claimed" <?php 
    checked( !empty( $options['enable_claimed'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<?php 
    esc_html_e( 'Claimed Voucher Email', 'simple-points-and-rewards' );
    ?>
			</label>
		</div>
		<div class="spar-accordion-body">
			<p style="margin: 0;"><strong><?php 
    esc_html_e( 'Subject:', 'simple-points-and-rewards' );
    ?></strong></p>
			<p><input type="text" name="subject_claimed" value="<?php 
    echo esc_attr( $options['subject_claimed'] );
    ?>" placeholder="<?php 
    esc_attr_e( 'Email subject', 'simple-points-and-rewards' );
    ?>" class="spar-full-width" /></p>

			<p style="margin-bottom: -20px;"><strong><?php 
    esc_html_e( 'Email Body:', 'simple-points-and-rewards' );
    ?></strong></p>
			<?php 
    wp_editor( $options['body_claimed'], 'body_claimed', [
        'textarea_rows' => 8,
        'media_buttons' => false,
    ] );
    ?>
			
			<p><button type="button" class="button" onclick="sparPreviewEmail('claimed')"><?php 
    esc_html_e( 'Send Preview Email', 'simple-points-and-rewards' );
    ?></button></p>
		</div>
	</div>

	<?php 
    ?>
	
	<div class="spar-info-box">
		<h4><?php 
    esc_html_e( 'Available Placeholders', 'simple-points-and-rewards' );
    ?></h4>
		<p><?php 
    esc_html_e( 'You can use these placeholders in your email subject and body:', 'simple-points-and-rewards' );
    ?></p>
		<ul class="spar-columns-2">
			<li><code>{user_name}</code> - <?php 
    esc_html_e( 'User display name', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{user_email}</code> - <?php 
    esc_html_e( 'User email address', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{points}</code> - <?php 
    esc_html_e( 'Points earned/spent', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{points_label}</code> - <?php 
    esc_html_e( 'Points label from settings', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{total_points}</code> - <?php 
    esc_html_e( 'User total points', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{action}</code> - <?php 
    esc_html_e( 'Action description', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{voucher_code}</code> - <?php 
    esc_html_e( 'Voucher code (claimed emails)', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{voucher_type}</code> - <?php 
    esc_html_e( 'Voucher description (claimed emails)', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{site_name}</code> - <?php 
    esc_html_e( 'Website name', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{site_url}</code> - <?php 
    esc_html_e( 'Website URL', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{rewards_url}</code> - <?php 
    /* translators: %s: Rewards label */
    printf( esc_html__( '%s page URL', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    ?></li>
			<li><code>{level_name}</code> - <?php 
    esc_html_e( 'Current level name (level up emails)', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{previous_level_name}</code> - <?php 
    esc_html_e( 'Previous level name (level up emails)', 'simple-points-and-rewards' );
    ?></li>
			<li><code>{apply_coupon_url}</code> - <?php 
    esc_html_e( 'Auto-apply coupon URL (claimed emails)', 'simple-points-and-rewards' );
    ?></li>
			<?php 
    ?>
		</ul>
	</div>
	<?php 
}
