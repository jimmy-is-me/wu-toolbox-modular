<?php
/**
 * Product Pages Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_product_pages() {
	$defaults = spar_settings_default();
	$options = get_option( 'spar_options', $defaults );
	$options = array_merge( $defaults, $options );
	$earn_options         = isset( $options['earn'] ) && is_array( $options['earn'] ) ? $options['earn'] : [];
	$order_earning_enabled = ! empty( $earn_options['order']['enabled'] ) || ! empty( $earn_options['order_fixed']['enabled'] );
	$show_on_product = ! empty( $options['show_on_product'] );
	$show_on_product_loop = ! empty( $options['show_on_product_loop'] );
	$product_message      = $options['product_points_message'] ?? '';
	$product_loop_message = $options['product_loop_points_message'] ?? '';
	$product_default_message = function_exists( 'spar_get_default_points_message' )
		? spar_get_default_points_message( 'single' )
		: esc_html__( 'Earn {points} {points_label_lower} by purchasing this product', 'simple-points-and-rewards' );
	$product_loop_default_message = function_exists( 'spar_get_default_points_message' )
		? spar_get_default_points_message( 'loop' )
		: esc_html__( 'Earn {points} reward points from this product', 'simple-points-and-rewards' );

	$placeholder_items = array(
		'{points}'             => esc_html__( 'Replaced with the numeric points amount.', 'simple-points-and-rewards' ),
		'{points_label}'       => esc_html__( 'Replaced with the points label for the displayed amount.', 'simple-points-and-rewards' ),
		'{points_label_lower}' => esc_html__( 'Replaced with the lowercase points label for the displayed amount.', 'simple-points-and-rewards' ),
	);

	// Add {points_value} placeholder when Points Discount on Checkout is enabled.
	if ( ! empty( $options['redeem_individual_enabled'] ) ) {
		$placeholder_items['{points_value}'] = esc_html__( 'Replaced with the monetary value of the earned points based on the "Points Discount on Checkout" settings.', 'simple-points-and-rewards' );
	}

	ob_start();
	?>
	<div class="spar-placeholder-help" role="note">
		<strong><?php esc_html_e( 'Available placeholders:', 'simple-points-and-rewards' ); ?></strong>
		<ul>
			<?php foreach ( $placeholder_items as $token => $description ) : ?>
				<li><code><?php echo esc_html( $token ); ?></code> &ndash; <?php echo esc_html( $description ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
	$placeholder_details_markup = ob_get_clean();
	?>
	<h3><?php esc_html_e( 'Product Pages', 'simple-points-and-rewards' ); ?></h3>
	<p><?php esc_html_e( 'Control where customers see earning messages on product and catalog pages.', 'simple-points-and-rewards' ); ?></p>
	<div id="spar-product-points-earning-notice" class="notice notice-info inline"<?php echo $order_earning_enabled ? ' style="display:none;"' : ''; ?>>
		<p><?php esc_html_e( 'These settings apply when an order-based earning method (Points for Spending or Points for Orders) is enabled in Ways to Earn.', 'simple-points-and-rewards' ); ?></p>
	</div>
	<div id="spar-product-points-display-section" class="spar-settings-section"<?php echo $order_earning_enabled ? '' : ' style="display:none;"'; ?>>
	<div class="spar-settings-section-header">
		<h4><?php esc_html_e( 'Points Display', 'simple-points-and-rewards' ); ?></h4>
		<p><?php esc_html_e( 'Control where customers see earning messages on product and catalog pages.', 'simple-points-and-rewards' ); ?></p>
	</div>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_on_product" <?php checked( $show_on_product ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show points on product pages', 'simple-points-and-rewards' ); ?></label></p>
	<div class="spar-toggle-extra-settings spar-product-toggle<?php echo $show_on_product ? '' : ' spar-hidden'; ?>" data-toggle-input="show_on_product">
		<p>
			<label for="product_points_location"><?php esc_html_e( 'Product points display location:', 'simple-points-and-rewards' ); ?></label>
			<select name="product_points_location" id="product_points_location">
				<?php
				$locations = function_exists( 'spar_get_product_points_location_options' ) ? spar_get_product_points_location_options() : array();
				$selected_location = $options['product_points_location'] ?? 'summary_after_price';
				foreach ( $locations as $location_key => $location_data ) {
					$label = isset( $location_data['label'] ) ? $location_data['label'] : $location_key;
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $location_key ),
						selected( $selected_location, $location_key, false ),
						esc_html( $label )
					);
				}
				?>
			</select>
		</p>
		<p>
			<label for="product_points_message"><?php esc_html_e( 'Product points message:', 'simple-points-and-rewards' ); ?></label>
			<input type="text" name="product_points_message" id="product_points_message" value="<?php echo esc_attr( $product_message ); ?>" placeholder="<?php echo esc_attr( $product_default_message ); ?>" class="regular-text" />
			<small class="description"><?php esc_html_e( 'Leave blank to use the default message.', 'simple-points-and-rewards' ); ?></small>
		</p>
		<?php echo wp_kses_post( $placeholder_details_markup ); ?>
		<?php $product_points_text_color = isset( $options['product_points_text_color'] ) ? sanitize_hex_color( $options['product_points_text_color'] ) : ''; ?>
		<p style="margin-top: 16px;">
			<label for="spar-product-points-text-color"><?php esc_html_e( 'Text Color:', 'simple-points-and-rewards' ); ?></label>
			<input id="spar-product-points-text-color" type="color" name="product_points_text_color" value="<?php echo esc_attr( $product_points_text_color ?: '#495057' ); ?>" />
			<small class="description"><?php esc_html_e( 'Controls the font color of the points message on product pages.', 'simple-points-and-rewards' ); ?></small>
		</p>
		<?php $product_points_bg_color = isset( $options['product_points_bg_color'] ) ? sanitize_hex_color( $options['product_points_bg_color'] ) : ''; ?>
		<p style="margin-top: 10px;">
			<label for="spar-product-points-bg-color"><?php esc_html_e( 'Background Color:', 'simple-points-and-rewards' ); ?></label>
			<input id="spar-product-points-bg-color" type="color" name="product_points_bg_color" value="<?php echo esc_attr( $product_points_bg_color ?: '#eaf1fa' ); ?>" />
			<small class="description"><?php esc_html_e( 'Controls the background color of the points message on product pages.', 'simple-points-and-rewards' ); ?></small>
		</p>
	</div>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_on_product_loop" <?php checked( $show_on_product_loop ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show points on category/shop product listings', 'simple-points-and-rewards' ); ?></label></p>
	<div class="spar-toggle-extra-settings spar-loop-toggle<?php echo $show_on_product_loop ? '' : ' spar-hidden'; ?>" data-toggle-input="show_on_product_loop">
		<p>
			<label for="product_loop_points_location"><?php esc_html_e( 'Catalog points display location:', 'simple-points-and-rewards' ); ?></label>
			<select name="product_loop_points_location" id="product_loop_points_location">
				<?php
				$loop_locations = function_exists( 'spar_get_product_loop_points_location_options' ) ? spar_get_product_loop_points_location_options() : array();
				$selected_loop_location = $options['product_loop_points_location'] ?? 'loop_after_title';
				foreach ( $loop_locations as $location_key => $location_data ) {
					$label = isset( $location_data['label'] ) ? $location_data['label'] : $location_key;
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $location_key ),
						selected( $selected_loop_location, $location_key, false ),
						esc_html( $label )
					);
				}
				?>
			</select>
		</p>
		<p>
			<label for="product_loop_points_message"><?php esc_html_e( 'Catalog points message:', 'simple-points-and-rewards' ); ?></label>
			<input type="text" name="product_loop_points_message" id="product_loop_points_message" value="<?php echo esc_attr( $product_loop_message ); ?>" placeholder="<?php echo esc_attr( $product_loop_default_message ); ?>" class="regular-text" />
			<small class="description"><?php esc_html_e( 'Leave blank to use the default message.', 'simple-points-and-rewards' ); ?></small>
		</p>
		<?php echo wp_kses_post( $placeholder_details_markup ); ?>
		<?php $product_loop_points_text_color = isset( $options['product_loop_points_text_color'] ) ? sanitize_hex_color( $options['product_loop_points_text_color'] ) : ''; ?>
		<p style="margin-top: 10px;">
			<label for="spar-product-loop-points-text-color"><?php esc_html_e( 'Text Color:', 'simple-points-and-rewards' ); ?></label>
			<input id="spar-product-loop-points-text-color" type="color" name="product_loop_points_text_color" value="<?php echo esc_attr( $product_loop_points_text_color ?: '#495057' ); ?>" />
			<small class="description"><?php esc_html_e( 'Controls the font color of the points message on category/shop listings.', 'simple-points-and-rewards' ); ?></small>
		</p>
		<?php $product_loop_points_bg_color = isset( $options['product_loop_points_bg_color'] ) ? sanitize_hex_color( $options['product_loop_points_bg_color'] ) : ''; ?>
		<p style="margin-top: 10px;">
			<label for="spar-product-loop-points-bg-color"><?php esc_html_e( 'Background Color:', 'simple-points-and-rewards' ); ?></label>
			<input id="spar-product-loop-points-bg-color" type="color" name="product_loop_points_bg_color" value="<?php echo esc_attr( $product_loop_points_bg_color ?: '#eaf1fa' ); ?>" />
			<small class="description"><?php esc_html_e( 'Controls the background color of the points message on category/shop listings.', 'simple-points-and-rewards' ); ?></small>
		</p>
	</div>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="link_to_rewards" <?php checked( ! empty( $options['link_to_rewards'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Link points text to rewards page', 'simple-points-and-rewards' ); ?></label></p>
	</div><!-- /#spar-product-points-display-section -->
	<?php
}
