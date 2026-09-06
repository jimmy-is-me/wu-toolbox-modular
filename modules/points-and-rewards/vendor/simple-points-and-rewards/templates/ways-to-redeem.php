<?php

/**
 * Ways to redeem points - frontend display
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'spar_render_ways_to_redeem_template' ) ) {
    /**
     * Render the ways to redeem template with locally scoped variables.
     */
    function spar_render_ways_to_redeem_template() {
        // Get rewards from settings instead of posts
        $rewards = spar_get_rewards();
        $user_points = spar_get_user_points();
        $account_options = spar_get_options( 'account' );
        $points_label = spar_get_option( 'general', 'points_label' );
        ?>

<h3 class="spar-redeem-title"><?php 
        /* translators: %s: points label lowercase */
        printf( esc_html__( 'Ways to redeem %s', 'simple-points-and-rewards' ), esc_html( strtolower( $points_label ) ) );
        ?></h3>
<p class="spar-redeem-intro"><?php 
        if ( !empty( $account_options['redeem_text'] ) ) {
            echo esc_html( $account_options['redeem_text'] );
        } else {
            /* translators: %s: points label lowercase */
            printf( esc_html__( 'Convert your earned %s into exciting rewards.', 'simple-points-and-rewards' ), esc_html( strtolower( $points_label ) ) );
        }
        ?></p>

<div class="spar-redeem-grid">
	<?php 
        if ( !empty( $rewards ) ) {
            ?>
		<?php 
            $count = 0;
            foreach ( $rewards as $reward ) {
                $count++;
                if ( $count > 3 ) {
                    break;
                }
                $type = ( isset( $reward['type'] ) ? sanitize_key( $reward['type'] ) : 'voucher' );
                $points_required = (int) ($reward['points'] ?? 0);
                $progress = ( $points_required > 0 ? min( 100, $user_points / $points_required * 100 ) : 100 );
                $points_needed = max( 0, $points_required - $user_points );
                $can_redeem = $user_points >= $points_required;
                $product = null;
                $bundle_products = array();
                if ( 'product' === $type ) {
                    $product_id = absint( $reward['product_id'] ?? 0 );
                    if ( $product_id && function_exists( 'wc_get_product' ) ) {
                        $product = wc_get_product( $product_id );
                    }
                } elseif ( 'product_bundle' === $type && function_exists( 'wc_get_product' ) ) {
                }
                $is_expired = false;
                $show_countdown = false;
                if ( $is_expired ) {
                    continue;
                }
                $url = add_query_arg( [
                    'spar_redeem_id' => $reward['id'],
                    'spar_nonce'     => wp_create_nonce( 'spar_redeem_reward' ),
                ], home_url() );
                ?>
			<div class="spar-box <?php 
                if ( $can_redeem ) {
                    echo 'spar-can-redeem';
                }
                if ( $show_countdown ) {
                    echo ' spar-reward-expiring';
                }
                ?>">
				<?php 
                $reward_icon_markup = '';
                $reward_icon_wrap_style = 'font-size:35px; height:50px; margin-bottom:15px; margin-top:-5px;';
                $reward_badge_color = ( isset( $reward['badge_color'] ) ? sanitize_hex_color( $reward['badge_color'] ) : '' );
                $reward_badge_color = ( is_string( $reward_badge_color ) ? $reward_badge_color : '' );
                $reward_badge_color_mode = sanitize_key( $reward['badge_color_mode'] ?? 'default' );
                $reward_badge_color_mode = ( in_array( $reward_badge_color_mode, array('default', 'custom'), true ) ? $reward_badge_color_mode : 'default' );
                $reward_icon_args = array(
                    'icon_class'      => 'spar-reward-icon',
                    'image_class'     => 'spar-reward-icon-img',
                    'icon_color'      => $reward_badge_color,
                    'icon_color_mode' => $reward_badge_color_mode,
                );
                $reward_badge_data = spar_normalize_badge_fields(
                    $reward['badge_icon'] ?? '',
                    $reward['badge_url'] ?? '',
                    $reward['badge_type'] ?? '',
                    $reward['badge_text'] ?? ''
                );
                $reward_badge_markup = spar_get_level_badge_markup( array_merge( $reward_badge_data, array(
                    'name'             => $reward['name'] ?? '',
                    'badge_color'      => $reward_badge_color,
                    'badge_color_mode' => $reward_badge_color_mode,
                ) ), $reward_icon_args );
                $use_product_image = !empty( $reward['use_product_image'] );
                if ( $use_product_image && 'product' === $type && $product ) {
                    $reward_icon_markup = $product->get_image( 'thumbnail', array(
                        'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
                    ) );
                    $reward_icon_wrap_style = 'margin-bottom:10px; max-height:50px; display:block;';
                } elseif ( $use_product_image && 'product_bundle' === $type && !empty( $bundle_products ) ) {
                    $reward_icon_markup = spar_render_bundle_image_carousel( $bundle_products );
                    $reward_icon_wrap_style = 'margin-bottom:10px;';
                } elseif ( !empty( $reward_badge_markup ) ) {
                    $reward_icon_markup = $reward_badge_markup;
                } elseif ( 'product' === $type && $product ) {
                    $reward_icon_markup = $product->get_image( 'thumbnail', array(
                        'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
                    ) );
                    $reward_icon_wrap_style = 'margin-bottom:10px; max-height:50px; display:block;';
                } elseif ( 'product_bundle' === $type && !empty( $bundle_products ) ) {
                    $reward_icon_markup = spar_render_bundle_image_carousel( $bundle_products );
                    $reward_icon_wrap_style = 'margin-bottom:10px;';
                }
                if ( '' === $reward_icon_markup ) {
                    $reward_fallback_icon = ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( $type ) : '🎟️' );
                    $reward_icon_markup = spar_get_level_badge_markup( array(
                        'badge_icon'       => $reward_fallback_icon,
                        'badge_type'       => 'preset',
                        'badge_color'      => $reward_badge_color,
                        'badge_color_mode' => $reward_badge_color_mode,
                        'name'             => $reward['name'] ?? '',
                    ), $reward_icon_args );
                }
                if ( '' !== $reward_icon_markup ) {
                    echo '<div style="' . esc_attr( $reward_icon_wrap_style ) . '">' . wp_kses_post( $reward_icon_markup ) . '</div>';
                }
                ?>
				
				<!-- Always show reward name -->
				<h4><?php 
                echo esc_html( $reward['name'] ?? esc_html__( 'Untitled Reward', 'simple-points-and-rewards' ) );
                ?></h4>
				
				<?php 
                ?>
				
				<!-- Always show reward points cost -->
				<p class="spar-redeem-cost"><?php 
                echo spar_format_points_display( $points_required, 'prominent' );
                ?> <?php 
                echo esc_html( $points_label );
                ?></p>

				<!-- Always show reward value/description -->
				<?php 
                if ( $type === 'voucher' ) {
                    $value = $reward['voucher_amount'] ?? 0;
                    $discount_type = $reward['discount_type'] ?? 'fixed_cart';
                    if ( $discount_type === 'percent' ) {
                        printf( '<p>%s: %s%%</p>', esc_html__( 'Voucher Value', 'simple-points-and-rewards' ), esc_html( $value ) );
                    } else {
                        // Display voucher value using store currency formatting when available
                        $value_display = ( function_exists( 'wc_price' ) ? wc_price( (float) $value ) : esc_html( spar_format_currency_amount( $value ) ) );
                        printf( '<p>%s: %s</p>', esc_html__( 'Voucher Value', 'simple-points-and-rewards' ), wp_kses_post( $value_display ) );
                    }
                } elseif ( $type === 'product' && isset( $product ) && $product ) {
                    echo '<p><a href="' . esc_url( get_permalink( $product->get_id() ) ) . '" target="_blank" style="text-decoration: underline; color: inherit;">' . esc_html( $product->get_name() ) . '</a></p>';
                } elseif ( $type === 'product_bundle' && !empty( $bundle_products ) ) {
                    echo '<p class="spar-bundle-product-list">';
                    $bundle_links = array();
                    foreach ( $bundle_products as $bundle_product ) {
                        $bundle_links[] = '<a href="' . esc_url( get_permalink( $bundle_product->get_id() ) ) . '" target="_blank" style="text-decoration: underline; color: inherit;">' . esc_html( $bundle_product->get_name() ) . '</a>';
                    }
                    echo wp_kses_post( implode( ' + ', $bundle_links ) );
                    echo '</p>';
                } elseif ( $type === 'custom' ) {
                    if ( !empty( $reward['custom_description'] ) ) {
                        echo '<p>' . esc_html( $reward['custom_description'] ) . '</p>';
                    } else {
                        esc_html_e( 'Custom reward – granted after claim.', 'simple-points-and-rewards' );
                    }
                }
                ?>

				<?php 
                // Optional total value of the product(s) for product / bundle rewards.
                $reward_value_display = ( function_exists( 'spar_get_reward_value_display' ) ? spar_get_reward_value_display( $reward ) : '' );
                if ( '' !== $reward_value_display ) {
                    ?>
					<p class="spar-reward-total-value"><?php 
                    printf( '%s: %s', esc_html__( 'Total Value', 'simple-points-and-rewards' ), wp_kses_post( $reward_value_display ) );
                    ?></p>
					<?php 
                }
                ?>

				<!-- Show remaining text if enabled in account options -->
				<?php 
                if ( !empty( $account_options['remaining_text'] ) ) {
                    ?>
					<p style="font-size: 10px; margin-top: 10px; margin-bottom: 0; display: inline-block; color: #111;">
						<?php 
                    if ( $can_redeem ) {
                        /* translators: 1: user points, 2: points label lowercase */
                        printf( esc_html__( 'You have %1$s %2$s. You can redeem this reward now.', 'simple-points-and-rewards' ), spar_format_points_display( (int) $user_points, 'inline' ), esc_html( strtolower( $points_label ) ) );
                    } else {
                        /* translators: 1: points needed, 2: points label lowercase */
                        printf( esc_html__( 'You need %1$s more %2$s to redeem this reward.', 'simple-points-and-rewards' ), spar_format_points_display( (int) $points_needed, 'inline' ), esc_html( strtolower( $points_label ) ) );
                    }
                    ?>
					</p>
				<?php 
                } else {
                    ?>
					<!-- Always show basic status even if remaining_text is disabled -->
					<p style="font-size: 12px; margin-top: 10px; margin-bottom: 0; color: #666;">
						<?php 
                    if ( $can_redeem ) {
                        esc_html_e( 'Ready to redeem!', 'simple-points-and-rewards' );
                    } else {
                        /* translators: 1: points needed, 2: points label lowercase */
                        printf( esc_html__( 'Need %1$s more %2$s', 'simple-points-and-rewards' ), spar_format_points_display( (int) $points_needed, 'inline' ), esc_html( strtolower( $points_label ) ) );
                    }
                    ?>
					</p>
				<?php 
                }
                ?>

				<!-- Show redemption progress bar if enabled -->
				<?php 
                if ( !empty( $account_options['redemption_bar'] ) ) {
                    ?>
					<div class="spar-progress-bar"><span style="width: <?php 
                    echo esc_attr( $progress );
                    ?>%"></span></div>
				<?php 
                }
                ?>

				<p class="spar-redemption-button" <?php 
                if ( !$can_redeem ) {
                    echo 'style="pointer-events: none; opacity: 0.5;"';
                }
                ?>>
					<a class="button <?php 
                echo ( $can_redeem ? '' : 'disabled' );
                ?>" href="<?php 
                echo esc_url( $url );
                ?>" <?php 
                disabled( !$can_redeem );
                ?>>
						<?php 
                esc_html_e( 'Claim Now', 'simple-points-and-rewards' );
                ?>
					</a>
				</p>
			</div>
		<?php 
            }
            ?>
	<?php 
        } else {
            ?>
		<div class="spar-box" style="text-align: center; padding: 40px; color: #666;">
			<div style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;">🎁</div>
			<h4><?php 
            esc_html_e( 'No Rewards Available', 'simple-points-and-rewards' );
            ?></h4>
			<p><?php 
            esc_html_e( 'Check back later for exciting rewards to redeem with your points!', 'simple-points-and-rewards' );
            ?></p>
		</div>
	<?php 
        }
        ?>

	<?php 
        // Buy Spins with Points — rendered as standalone section below the grid.
        ?>
</div>

<script>
(function(){
	function initBundleCarousels(){
		var carousels = document.querySelectorAll('[data-spar-bundle-carousel]');
		Array.prototype.forEach.call(carousels, function(carousel){
			if (carousel.dataset.sparCarouselInit) { return; }
			carousel.dataset.sparCarouselInit = '1';
			var slides = carousel.querySelectorAll('.spar-bundle-carousel-slide');
			var dots   = carousel.querySelectorAll('.spar-bundle-carousel-dot');
			if (slides.length < 2) { return; }
			var current = 0;
			function show(index){
				current = (index + slides.length) % slides.length;
				for (var i = 0; i < slides.length; i++){
					slides[i].classList.toggle('is-active', i === current);
					if (dots[i]) { dots[i].classList.toggle('is-active', i === current); }
				}
			}
			Array.prototype.forEach.call(dots, function(dot){
				dot.addEventListener('click', function(){
					var idx = parseInt(dot.getAttribute('data-slide'), 10) || 0;
					show(idx);
					restart();
				});
			});
			var timer = null;
			function restart(){
				if (timer) { clearInterval(timer); }
				timer = setInterval(function(){ show(current + 1); }, 2500);
			}
			restart();
		});
	}
	if (document.readyState === 'loading'){
		document.addEventListener('DOMContentLoaded', initBundleCarousels);
	} else {
		initBundleCarousels();
	}
})();
</script>

<div style="clear: both; margin-bottom: 0px;"></div>
<?php 
        // Buy Spins with Points — standalone section (like Points Discounts).
        if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && function_exists( 'spar_get_spin_wheel_settings' ) ) {
            $_bs = spar_get_spin_wheel_settings();
            if ( !empty( $_bs['enabled'] ) && !empty( $_bs['buy_spins_enabled'] ) ) {
                $_bs_cost = max( 1, (int) ($_bs['buy_spins_points_cost'] ?? 100) );
                $_bs_can_afford = $user_points >= $_bs_cost;
                $_bs_points_need = max( 0, $_bs_cost - $user_points );
                // Customisable text with sensible defaults.
                $_bs_heading = ( !empty( $_bs['buy_spins_heading'] ) ? $_bs['buy_spins_heading'] : esc_html__( 'Claim Prize Wheel Spins', 'simple-points-and-rewards' ) );
                $_bs_description = ( !empty( $_bs['buy_spins_description'] ) ? $_bs['buy_spins_description'] : esc_html__( 'Exchange your points for spins on the prize wheel!', 'simple-points-and-rewards' ) );
                $_bs_btn_text = ( !empty( $_bs['buy_spins_button_text'] ) ? $_bs['buy_spins_button_text'] : esc_html__( 'Claim Spins', 'simple-points-and-rewards' ) );
                ?>
		<h3 id="spar-buy-spins-anchor" class="spar-redeem-title spar-buy-spins-heading"><?php 
                echo esc_html( $_bs_heading );
                ?></h3>
		<p class="spar-redeem-intro"><?php 
                echo esc_html( $_bs_description );
                ?></p>
		<p class="spar-redeem-rate">
			<?php 
                /* translators: 1: points cost, 2: points label */
                printf( esc_html__( 'Cost: %1$s %2$s per spin', 'simple-points-and-rewards' ), spar_format_points_display( (int) $_bs_cost, 'inline' ), esc_html( $points_label ) );
                ?>
		</p>

		<?php 
                if ( $_bs_can_afford ) {
                    ?>
			<div class="spar-buy-spins-section">
				<div class="spar-buy-spins-controls">
					<label class="spar-buy-spins-label">
						<?php 
                    esc_html_e( 'Quantity:', 'simple-points-and-rewards' );
                    ?>
					</label>
					<input type="number" class="spar-buy-spins-qty" value="1" min="1" step="1" />
					<button type="button" class="button spar-buy-spins-btn" data-cost="<?php 
                    echo esc_attr( $_bs_cost );
                    ?>" data-nonce="<?php 
                    echo esc_attr( wp_create_nonce( 'spar_buy_spin_nonce' ) );
                    ?>">
						<?php 
                    echo esc_html( $_bs_btn_text );
                    ?>
					</button>
					<span class="spar-buy-spins-total">
						<?php 
                    /* translators: 1: total cost, 2: points label */
                    printf( esc_html__( 'Total: %1$s %2$s', 'simple-points-and-rewards' ), spar_format_points_display( (int) $_bs_cost, 'inline' ), esc_html( $points_label ) );
                    ?>
					</span>
				</div>
				<p class="spar-buy-spins-msg"></p>
			</div>
		<?php 
                } else {
                    ?>
			<p style="color: #666;">
				<?php 
                    /* translators: 1: points needed, 2: points label lowercase */
                    printf( esc_html__( 'You need %1$s more %2$s to buy a spin.', 'simple-points-and-rewards' ), spar_format_points_display( (int) $_bs_points_need, 'inline' ), esc_html( strtolower( $points_label ) ) );
                    ?>
			</p>
		<?php 
                }
                ?>
		<?php 
            }
        }
        ?>

<?php 
        if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && function_exists( 'spar_get_spin_wheel_settings' ) ) {
            $_bs2 = spar_get_spin_wheel_settings();
            if ( !empty( $_bs2['enabled'] ) && !empty( $_bs2['buy_spins_enabled'] ) ) {
                $_bs2_btn_text = ( !empty( $_bs2['buy_spins_button_text'] ) ? $_bs2['buy_spins_button_text'] : esc_html__( 'Claim Spins', 'simple-points-and-rewards' ) );
                ?>
<script>
(function($){
	'use strict';
	var $section = $('.spar-buy-spins-section');
	if (!$section.length) return;

	var $qty   = $section.find('.spar-buy-spins-qty');
	var $btn   = $section.find('.spar-buy-spins-btn');
	var $total = $section.find('.spar-buy-spins-total');
	var $msg   = $section.find('.spar-buy-spins-msg');
	var cost   = parseInt($btn.data('cost'), 10) || 0;
	var label  = <?php 
                echo wp_json_encode( esc_html( $points_label ) );
                ?>;
	var prefix = <?php 
                echo wp_json_encode( spar_get_points_prefix() );
                ?>;
	var btnText = <?php 
                echo wp_json_encode( esc_html( $_bs2_btn_text ) );
                ?>;

	$qty.on('input change', function(){
		var q = parseInt($(this).val(), 10) || 1;
		q = Math.max(1, q);
		$total.text('<?php 
                echo esc_js( __( 'Total:', 'simple-points-and-rewards' ) );
                ?> ' + prefix + (q * cost) + ' ' + label);
	});

	$btn.on('click', function(e){
		e.preventDefault();
		if ($btn.prop('disabled')) return;

		var qty   = parseInt($qty.val(), 10) || 1;
		if (qty < 1) { qty = 1; }
		var nonce = $btn.data('nonce');

		$btn.prop('disabled', true).text('<?php 
                echo esc_js( __( 'Processing...', 'simple-points-and-rewards' ) );
                ?>');
		$msg.hide();

		$.ajax({
			url: '<?php 
                echo esc_js( admin_url( 'admin-ajax.php' ) );
                ?>',
			type: 'POST',
			data: {
				action: 'spar_buy_spin_with_points',
				nonce: nonce,
				quantity: qty
			},
			success: function(res) {
				if (res.success) {
					$msg.css('color', '#2ca58d').text(res.data.message).show();
					setTimeout(function(){ window.location.reload(); }, 1200);
				} else {
					$msg.css('color', '#a00').text(res.data && res.data.message ? res.data.message : '<?php 
                echo esc_js( __( 'Something went wrong.', 'simple-points-and-rewards' ) );
                ?>').show();
					$btn.prop('disabled', false).text(btnText);
				}
			},
			error: function() {
				$msg.css('color', '#a00').text('<?php 
                echo esc_js( __( 'Something went wrong. Please try again.', 'simple-points-and-rewards' ) );
                ?>').show();
				$btn.prop('disabled', false).text(btnText);
			}
		});
	});
})(jQuery);
</script>
<?php 
            }
        }
    }

}
spar_render_ways_to_redeem_template();