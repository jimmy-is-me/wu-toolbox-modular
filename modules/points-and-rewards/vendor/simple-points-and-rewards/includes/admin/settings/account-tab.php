<?php
/**
 * Rewards Dashboard Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_account() {
	$defaults = spar_settings_default();
	$options = get_option( 'spar_options', $defaults );
	$options = array_merge( $defaults, $options );
	?>
	<h3><?php esc_html_e( 'Rewards Dashboard Settings', 'simple-points-and-rewards' ); ?></h3>

	<p><?php esc_html_e( 'Show the Rewards experience in My Account and/or on a custom page via shortcode.', 'simple-points-and-rewards' ); ?></p>

	<div class="spar-settings-section spar-settings-section--master">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Display on My Account Page', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Choose whether the rewards area appears inside WooCommerce My Account.', 'simple-points-and-rewards' ); ?></p>
	</div>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_rewards_in_my_account" <?php checked( ! empty( $options['show_rewards_in_my_account'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show Rewards tab on the My Account page', 'simple-points-and-rewards' ); ?></label></p>
	<?php
		// Build My Account > Rewards link for quick access
		$my_account_link = '';
		if ( function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$my_account_link = wc_get_endpoint_url( 'rewards', '', wc_get_page_permalink( 'myaccount' ) );
		}
		if ( ! empty( $my_account_link ) ) :
	?>
		<p class="spar-mt--10">
			<a href="<?php echo esc_url( $my_account_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open My Account → Rewards', 'simple-points-and-rewards' ); ?></a>
		</p>
	<?php endif; ?>
	</div>

	<div class="spar-shortcode-page-settings spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Display on Custom Page', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Connect a standalone page that contains the rewards dashboard shortcode.', 'simple-points-and-rewards' ); ?></p>
		</div>
		<p>
			<?php esc_html_e( 'Create any page and add the shortcode', 'simple-points-and-rewards' ); ?>
			<code class="spar-code-badge">[spar_points_rewards]</code>
		</p>
		<?php
			$current_page_id = isset( $options['rewards_page_id'] ) ? absint( $options['rewards_page_id'] ) : 0;
			$current_link    = $current_page_id ? get_permalink( $current_page_id ) : '';

			// Build list of pages that actually contain the shortcode
			$valid_pages   = array();
			$pages         = get_pages( array( 'sort_column' => 'post_title', 'post_status' => array( 'publish', 'draft' ) ) );
			if ( ! empty( $pages ) ) {
				foreach ( $pages as $page ) {
					if (
						function_exists( 'has_shortcode' ) &&
						( has_shortcode( $page->post_content, 'spar_points_rewards' ) )
					) {
						$valid_pages[] = $page;
					}
				}
			}

			// Determine if the currently saved page is still valid (contains the shortcode)
			$current_is_valid = false;
			if ( $current_page_id ) {
				$content = get_post_field( 'post_content', $current_page_id );
				$current_is_valid = ! empty( $content ) && function_exists( 'has_shortcode' ) && ( has_shortcode( $content, 'spar_points_rewards' ) );
			}
		?>
		<p>
			<label for="spar-rewards-page-select" class="spar-display-block spar-mb-6 spar-fw-600"><?php esc_html_e( 'Select Rewards Dashboard Page', 'simple-points-and-rewards' ); ?></label>
			<select id="spar-rewards-page-select" name="rewards_page_id" class="spar-minw-280" data-nonce="<?php echo esc_attr( wp_create_nonce( 'spar_retrieve_page_link' ) ); ?>">
				<option value="0" <?php selected( ! $current_is_valid ); ?>><?php esc_html_e( '— Select a page —', 'simple-points-and-rewards' ); ?></option>
				<?php if ( ! empty( $valid_pages ) ) : ?>
					<?php foreach ( $valid_pages as $page ) : ?>
						<?php printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $page->ID, selected( $current_is_valid && (int) $current_page_id === (int) $page->ID, true, false ), esc_html( $page->post_title ) ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<a href="#" id="spar-refresh-rewards-pages" class="button-link" aria-label="<?php echo esc_attr__( 'Refresh pages with shortcode', 'simple-points-and-rewards' ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'spar_list_rewards_pages' ) ); ?>" style="margin-left:8px;">&#10227; <?php esc_html_e( 'Refresh', 'simple-points-and-rewards' ); ?></a>
		</p>
		<p>
					<button type="button" class="button<?php echo $current_is_valid ? ' spar-hidden' : ''; ?>" id="spar-generate-rewards-page" data-nonce="<?php echo esc_attr( wp_create_nonce( 'spar_generate_rewards_page' ) ); ?>"><?php esc_html_e( 'Create Page with Shortcode', 'simple-points-and-rewards' ); ?></button>
		</p>
		
		<p id="spar-rewards-page-link" class="spar-mt--12">
			<?php if ( $current_is_valid && $current_link ) : ?>
				<a href="<?php echo esc_url( $current_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View selected page', 'simple-points-and-rewards' ); ?></a>
			<?php else : ?>
				<em><?php esc_html_e( 'No page selected.', 'simple-points-and-rewards' ); ?></em>
			<?php endif; ?>
		</p>
	</div>
	
	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Earn Points Section', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Customise the intro message shown above the earning methods.', 'simple-points-and-rewards' ); ?></p>
	</div>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="earn_points_text_enabled" <?php checked( isset( $options['earn_points_text_enabled'] ) ? (bool) $options['earn_points_text_enabled'] : true ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show intro message on Earn Points tab', 'simple-points-and-rewards' ); ?></label></p>
	<div class="<?php echo ( isset( $options['earn_points_text_enabled'] ) ? (bool) $options['earn_points_text_enabled'] : true ) ? '' : ' spar-hidden'; ?>" data-toggle-input="earn_points_text_enabled">
	<label>
		<?php esc_html_e( 'Earn Points Text:', 'simple-points-and-rewards' ); ?>
		<input type="text" name="earn_points_text" value="<?php echo esc_attr( $options['earn_points_text'] ); ?>" placeholder="<?php esc_attr_e( 'You can earn points by completing the following actions.', 'simple-points-and-rewards' ); ?>" />
	</label>
	</div>
	</div>

	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Redemption Section', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Control the text and display elements shown when customers claim rewards.', 'simple-points-and-rewards' ); ?></p>
	</div>

	<label>
		<?php esc_html_e( 'Redemption Text:', 'simple-points-and-rewards' ); ?>
		<input type="text" name="redeem_text" value="<?php echo esc_attr( $options['redeem_text'] ); ?>" placeholder="<?php esc_attr_e( 'Convert your earned points into exciting rewards.', 'simple-points-and-rewards' ); ?>" />
	</label>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="reward_name" <?php checked( ! empty( $options['reward_name'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show reward name', 'simple-points-and-rewards' ); ?></label></p>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="reward_points" <?php checked( ! empty( $options['reward_points'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show reward points', 'simple-points-and-rewards' ); ?></label></p>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="reward_value" <?php checked( ! empty( $options['reward_value'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show reward value', 'simple-points-and-rewards' ); ?></label></p>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="remaining_text" <?php checked( ! empty( $options['remaining_text'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show remaining points text', 'simple-points-and-rewards' ); ?></label></p>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="redemption_bar" <?php checked( ! empty( $options['redemption_bar'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show redemption progress bar', 'simple-points-and-rewards' ); ?></label></p>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="account_enable_confetti" <?php checked( ! empty( $options['account_enable_confetti'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Enable confetti animation on redemption', 'simple-points-and-rewards' ); ?></label></p>
	</div>
	
	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Points Discount Section', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Choose whether the instant points discount area appears on the rewards dashboard.', 'simple-points-and-rewards' ); ?></p>
	</div>
	<?php
		$pd_section_enabled = isset( $options['dashboard_points_discount_enabled'] ) ? (bool) $options['dashboard_points_discount_enabled'] : true;
		$custom_pd_message  = isset( $options['redeem_individual_message'] ) ? sanitize_text_field( $options['redeem_individual_message'] ) : '';
		$default_pd_message = esc_html__( 'Use your points to get an instant discount on your next order.', 'simple-points-and-rewards' );
	?>
	<p>
		<div class="spar-toggle-switch">
			<input type="checkbox" id="spar-dashboard-points-discount-enabled" name="dashboard_points_discount_enabled" value="1" <?php checked( $pd_section_enabled ); ?> />
			<span class="spar-toggle-slider"></span>
		</div>
		<span><?php esc_html_e( 'Show Points Discount section on rewards dashboard', 'simple-points-and-rewards' ); ?></span>
	</p>
	<div class="spar-mb-10" style="margin-top:4px;">
		<label class="spar-inline-block" style="font-weight:600;" for="spar-redeem-individual-message">
			<?php esc_html_e( 'Intro Message (optional):', 'simple-points-and-rewards' ); ?>
		</label>
		<input type="text" id="spar-redeem-individual-message" name="redeem_individual_message" value="<?php echo esc_attr( $custom_pd_message ); ?>" placeholder="<?php echo esc_attr( $default_pd_message ); ?>" class="spar-full-width" style="max-width:520px;" />
		<small style="margin: 0;">
			<?php esc_html_e( 'Leave blank to use the default message shown on the rewards dashboard and widget.', 'simple-points-and-rewards' ); ?>
		</small>
	</div>
	</div>

	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Dashboard Header & Sub-header', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Set the main heading and supporting text shown at the top of the dashboard.', 'simple-points-and-rewards' ); ?></p>
	</div>

	<div class="spar-mb-10">
		<label class="spar-inline-block" style="font-weight:600;" for="spar_dashboard_header_text">
			<?php esc_html_e( 'Dashboard Header Text', 'simple-points-and-rewards' ); ?>
		</label>
		<input type="text" id="spar_dashboard_header_text" name="dashboard_header_text" value="<?php echo esc_attr( isset($options['dashboard_header_text']) ? $options['dashboard_header_text'] : '' ); ?>" class="spar-full-width" style="max-width:520px;" />
		<small style="margin: 0; display:block;">
			<?php esc_html_e( 'The main heading on the rewards dashboard. Use {points_label} to insert the points name.', 'simple-points-and-rewards' ); ?>
		</small>
	</div>

	<div class="spar-mb-10">
		<label class="spar-inline-block" style="font-weight:600;" for="spar_dashboard_subheader_text">
			<?php esc_html_e( 'Dashboard Sub-header Text', 'simple-points-and-rewards' ); ?>
		</label>
		<input type="text" id="spar_dashboard_subheader_text" name="dashboard_subheader_text" value="<?php echo esc_attr( isset($options['dashboard_subheader_text']) ? $options['dashboard_subheader_text'] : '' ); ?>" class="spar-full-width" style="max-width:520px;" />
		<small style="margin: 0; display:block;">
			<?php esc_html_e( 'The sub-heading text below the main dashboard title.', 'simple-points-and-rewards' ); ?>
		</small>
	</div>
	</div>

	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Dark Mode', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Allow customers to switch the dashboard between light and dark themes.', 'simple-points-and-rewards' ); ?></p>
	</div>
	
	<p>
		<label>
			<div class="spar-toggle-switch"><input type="checkbox" name="dashboard_dark_mode_toggle" <?php checked( ! empty( $options['dashboard_dark_mode_toggle'] ) ); ?> /><span class="spar-toggle-slider"></span></div>
			<?php esc_html_e( 'Show dark mode toggle on dashboard', 'simple-points-and-rewards' ); ?>
		</label>
	</p>

	<div class="spar-settings-indent spar-hidden" data-toggle-input="dashboard_dark_mode_toggle">
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="dashboard_dark_mode_default" <?php checked( ! empty( $options['dashboard_dark_mode_default'] ) ); ?> /><span class="spar-toggle-slider"></span></div>
				<?php esc_html_e( 'Default dashboard to dark mode', 'simple-points-and-rewards' ); ?>
			</label>
		</p>
		<div class="spar-settings-indent spar-hidden" data-toggle-input="dashboard_dark_mode_default">
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="dashboard_dark_mode_hide_toggle_when_default" <?php checked( ! empty( $options['dashboard_dark_mode_hide_toggle_when_default'] ) ); ?> /><span class="spar-toggle-slider"></span></div>
					<?php esc_html_e( 'Hide toggle when dark mode is the default', 'simple-points-and-rewards' ); ?>
				</label>
			</p>
		</div>
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="dashboard_dark_mode_header" <?php checked( ! empty( $options['dashboard_dark_mode_header'] ) ); ?> /><span class="spar-toggle-slider"></span></div>
				<?php esc_html_e( 'Apply dark mode to header box', 'simple-points-and-rewards' ); ?>
			</label>
		</p>
	</div>
	</div>

	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Theme Colors', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Choose the colours used by the rewards dashboard theme.', 'simple-points-and-rewards' ); ?></p>
	</div>
	<p style="margin: -5px 0 20px 0; color:#555;">
		<?php esc_html_e( 'These control the gradient colors on the Rewards dashboard such as the header, icons, borders, and buttons.', 'simple-points-and-rewards' ); ?>
	</p>
	<div>
		<label style="display:flex; align-items:center; gap:8px;">
			<span style="min-width:120px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Primary Theme Color', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="color" name="rewards_theme_color_1" value="<?php echo esc_attr( $options['rewards_theme_color_1'] ?? '#667eea' ); ?>" />
		</label>
		<br/>
		<label style="display:flex; align-items:center; gap:8px;">
			<span style="min-width:120px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Secondary Theme Color', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="color" name="rewards_theme_color_2" value="<?php echo esc_attr( $options['rewards_theme_color_2'] ?? '#764ba2' ); ?>" />
		</label>
		<br/>
		<label style="display:flex; align-items:center; gap:8px;">
			<span style="min-width:120px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Accent Color', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="color" name="rewards_theme_accent_color" value="<?php echo esc_attr( $options['rewards_theme_accent_color'] ?? '#2ca58d' ); ?>" />
		</label>
	</div>
	</div>

	<div class="spar-settings-section">
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Dashboard Tabs', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Choose which tabs are shown and set their order.', 'simple-points-and-rewards' ); ?></p>
	</div>
	
	<p><?php esc_html_e( 'Choose which tabs are shown on the customer rewards dashboard and set their order. Drag to reorder; uncheck to hide. Rename a tab by editing its label field below.', 'simple-points-and-rewards' ); ?></p>

	<?php
		$available_tabs = array(
			'earn'     => esc_html__( 'Earn Points', 'simple-points-and-rewards' ),
			'claim'    => esc_html__( 'Claim Rewards', 'simple-points-and-rewards' ),
			'levels'   => esc_html__( 'Levels', 'simple-points-and-rewards' ),
			'history'  => esc_html__( 'History', 'simple-points-and-rewards' ),
			'vouchers' => esc_html__( 'Your Vouchers', 'simple-points-and-rewards' ),
		);

		$saved_order = isset( $options['dashboard_tabs_order'] ) && is_array( $options['dashboard_tabs_order'] )
			? array_values( array_unique( array_filter( $options['dashboard_tabs_order'], 'strlen' ) ) )
			: array( 'earn', 'claim', 'vouchers', 'levels', 'history' );

		// Ensure we only keep known keys, and append any new ones not yet saved
		$saved_order = array_values( array_intersect( $saved_order, array_keys( $available_tabs ) ) );

		$enabled_map = array_fill_keys( $saved_order, true );

		foreach ( $available_tabs as $key => $label ) {
			if ( ! in_array( $key, $saved_order, true ) ) {
				$saved_order[] = $key;
			}
		}
		$saved_labels = isset( $options['dashboard_tab_labels'] ) && is_array( $options['dashboard_tab_labels'] ) ? $options['dashboard_tab_labels'] : array();
	?>

	<ul id="spar-dashboard-tabs-sortable" class="spar-sortable-list">
		<?php foreach ( $saved_order as $key ) :
			$default_label = isset( $available_tabs[ $key ] ) ? $available_tabs[ $key ] : $key;
			$current_label = isset( $saved_labels[ $key ] ) && '' !== $saved_labels[ $key ] ? $saved_labels[ $key ] : $default_label; ?>
			<li class="spar-sortable-item" data-key="<?php echo esc_attr( $key ); ?>">
				<label class="spar-tab-toggle">
					<input type="checkbox" name="dashboard_tabs_order[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $enabled_map[ $key ] ) ); ?> />
					<span class="screen-reader-text"><?php esc_html_e( 'Show tab', 'simple-points-and-rewards' ); ?></span>
				</label>
				<div class="spar-tab-fields">
					<div class="spar-tab-toggle-text"><small><?php echo esc_html( $default_label ); ?></small></div>
					<input type="text" class="regular-text spar-tab-label-input" name="dashboard_tab_labels[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $current_label ); ?>" placeholder="<?php echo esc_attr( $default_label ); ?>" />
				</div>
				<span class="dashicons dashicons-move" aria-hidden="true"></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="description">
		<?php esc_html_e( 'Tabs left checked will appear for customers. The small text above each field shows the original tab name.', 'simple-points-and-rewards' ); ?>
	</p>

	<style>
		.spar-sortable-list { list-style: none; margin: 0; padding: 0; max-width: 640px; }
		.spar-sortable-item { display: flex; align-items: center; gap: 10px; margin-left: 0px !important; padding: 8px 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; margin-bottom: 6px; }
		.spar-sortable-item .dashicons-move { cursor: move; color: #646970; margin-left: auto; }
		.spar-tab-fields { display: flex; flex-direction: column; gap: 4px; flex: 1 1 auto; }
		.spar-tab-toggle { display: inline-flex; align-items: center; margin: 0; }
		.spar-tab-toggle input { margin: 0; }
		.spar-tab-toggle-text { color: #646970; font-size: 12px; line-height: 1.1; margin: 0; }
		.spar-tab-label-input { max-width: 360px; }
		.spar-sortable-item.is-disabled { opacity: .8; }
		.spar-sortable-item.is-disabled .spar-tab-label-input { opacity: .7; }
	</style>
	</div>

	<?php
}
