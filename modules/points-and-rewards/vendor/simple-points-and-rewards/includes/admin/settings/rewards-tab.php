<?php

/**
 * Rewards Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_rewards() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $avalara_tax_active = ( function_exists( 'spar_is_avalara_tax_plugin_active' ) ? spar_is_avalara_tax_plugin_active() : false );
    $tax_class_options = ( function_exists( 'spar_get_woocommerce_tax_class_options' ) ? spar_get_woocommerce_tax_class_options() : [
        '' => esc_html__( 'Default (standard rate)', 'simple-points-and-rewards' ),
    ] );
    $rewards = ( isset( $options['rewards'] ) ? $options['rewards'] : [] );
    $reward_vouchers_enabled = !empty( $options['rewards_vouchers_enabled'] );
    $points_label = ( isset( $options['points_label'] ) ? $options['points_label'] : '' );
    $rewards_label = ( isset( $options['rewards_label'] ) ? $options['rewards_label'] : '' );
    $points_label = ( $points_label !== '' ? $points_label : esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $rewards_label = ( $rewards_label !== '' ? $rewards_label : esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    $badge_icons = spar_get_reward_badge_icons();
    $reward_type_default_icons = [
        'voucher'        => spar_get_reward_type_default_icon( 'voucher' ),
        'product'        => spar_get_reward_type_default_icon( 'product' ),
        'product_bundle' => spar_get_reward_type_default_icon( 'product_bundle' ),
        'custom'         => spar_get_reward_type_default_icon( 'custom' ),
    ];
    ?>
    <div class="spar-rewards-settings">
    <h3><?php 
    /* translators: %s: Rewards label */
    printf( esc_html__( '%s Vouchers', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    ?></h3>
    <p><?php 
    esc_html_e( 'Create rewards that customers can claim with their points.', 'simple-points-and-rewards' );
    ?></p>

    <div class="spar-settings-section spar-settings-section--master">
        <label class="spar-settings-toggle-label">
            <div class="spar-toggle-switch">
                <input type="checkbox" id="spar-rewards-vouchers-enabled" name="rewards_vouchers_enabled" value="1" <?php 
    checked( $reward_vouchers_enabled );
    ?> />
                <span class="spar-toggle-slider"></span>
            </div>
            <span><?php 
    esc_html_e( 'Enable Reward Vouchers', 'simple-points-and-rewards' );
    ?></span>
        </label>
    </div>

        <div id="spar-rewards-disabled-notice"
        style="margin-bottom: 20px;"
        class="spar-rewards-disabled-notice <?php 
    echo ( $reward_vouchers_enabled ? 'spar-hidden' : '' );
    ?>">
            <p><?php 
    esc_html_e( 'Reward vouchers are currently disabled. Enable the option above to configure rewards and allow customers to redeem them.', 'simple-points-and-rewards' );
    ?></p>
            <p><?php 
    esc_html_e( 'The "redeem rewards" will be hidden on the rewards dashboard, checkout box, and floating widget.', 'simple-points-and-rewards' );
    ?></p>
        </div>

    <div id="spar-rewards-settings-panel" class="<?php 
    echo ( $reward_vouchers_enabled ? '' : 'spar-hidden' );
    ?>">

        <div class="spar-settings-section spar-settings-section--list">

        <div id="spar-rewards-list">
            <?php 
    if ( !empty( $rewards ) ) {
        ?>
                <?php 
        foreach ( $rewards as $index => $reward ) {
            ?>

                    <?php 
            $rw_type = $reward['type'] ?? 'voucher';
            $rw_badge_data = spar_normalize_badge_fields(
                $reward['badge_icon'] ?? '',
                $reward['badge_url'] ?? '',
                $reward['badge_type'] ?? '',
                $reward['badge_text'] ?? ''
            );
            $rw_badge_color = ( isset( $reward['badge_color'] ) ? sanitize_hex_color( $reward['badge_color'] ) : '' );
            $rw_badge_color = ( is_string( $rw_badge_color ) ? $rw_badge_color : '' );
            $rw_badge_preview = spar_get_level_badge_markup( array_merge( $rw_badge_data, [
                'name'        => $reward['name'] ?? '',
                'badge_color' => $rw_badge_color,
            ] ), [
                'icon_class'  => 'spar-level-badge-icon',
                'image_class' => 'spar-level-badge-img',
            ] );
            $rw_default_icon = $reward_type_default_icons[$rw_type] ?? spar_get_reward_type_default_icon( 'voucher' );
            $rw_default_markup = spar_get_level_badge_markup( array(
                'name' => $reward['name'] ?? '',
            ), [
                'icon_class'    => 'spar-level-badge-icon',
                'image_class'   => 'spar-level-badge-img',
                'fallback_icon' => $rw_default_icon,
                'icon_color'    => $rw_badge_color,
            ] );
            $rw_badge_type = $rw_badge_data['badge_type'];
            $rw_selected_icon = ( 'preset' === $rw_badge_type ? $rw_badge_data['badge_icon'] ?? '' : '' );
            $rw_effective_icon = ( '' !== $rw_selected_icon ? $rw_selected_icon : $rw_default_icon );
            $rw_show_badge_color = 'custom_text' === $rw_badge_type || function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $rw_effective_icon );
            $rw_badge_color_mode = $reward['badge_color_mode'] ?? 'default';
            $rw_badge_color_mode = ( in_array( $rw_badge_color_mode, ['default', 'custom'], true ) ? $rw_badge_color_mode : 'default' );
            $rw_badge_color_value = ( $rw_badge_color ?: '#667eea' );
            // When "Product Image" is selected for a product/bundle reward, render the
            // product image (or bundle carousel) in the header preview, matching the dashboard.
            $rw_product_image_markup = '';
            if ( in_array( $rw_type, array('product', 'product_bundle'), true ) && !empty( $reward['use_product_image'] ) && function_exists( 'wc_get_product' ) ) {
                if ( 'product' === $rw_type && !empty( $reward['product_id'] ) ) {
                    $rw_preview_product = wc_get_product( absint( $reward['product_id'] ) );
                    if ( $rw_preview_product ) {
                        $rw_product_image_markup = $rw_preview_product->get_image( 'thumbnail', array(
                            'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
                        ) );
                    }
                } elseif ( 'product_bundle' === $rw_type && !empty( $reward['bundle_product_ids'] ) && function_exists( 'spar_render_bundle_image_carousel' ) ) {
                    $rw_preview_products = array();
                    foreach ( array_map( 'absint', (array) $reward['bundle_product_ids'] ) as $rw_preview_pid ) {
                        if ( !$rw_preview_pid ) {
                            continue;
                        }
                        $rw_preview_bundle_product = wc_get_product( $rw_preview_pid );
                        if ( $rw_preview_bundle_product ) {
                            $rw_preview_products[] = $rw_preview_bundle_product;
                        }
                    }
                    $rw_product_image_markup = spar_render_bundle_image_carousel( $rw_preview_products );
                }
            }
            ?>
                    <div class="spar-reward-item" data-index="<?php 
            echo esc_attr( $index );
            ?>">
                        <div class="spar-reward-header clickable-header">
                            <span class="spar-drag-handle dashicons dashicons-move" aria-label="<?php 
            echo esc_attr__( 'Drag to reorder', 'simple-points-and-rewards' );
            ?>" title="<?php 
            echo esc_attr__( 'Drag to reorder', 'simple-points-and-rewards' );
            ?>"></span>
                            <span class="spar-reward-type-badge">
                                <?php 
            if ( '' !== $rw_product_image_markup ) {
                ?>
                                    <?php 
                echo wp_kses_post( $rw_product_image_markup );
                ?>
                                <?php 
            } elseif ( $rw_badge_preview ) {
                ?>
                                    <?php 
                echo wp_kses_post( $rw_badge_preview );
                ?>
                                <?php 
            } else {
                ?>
                                    <?php 
                echo wp_kses_post( $rw_default_markup );
                ?>
                                <?php 
            }
            ?>
                            </span>
                            <h4>
                                <?php 
            $reward_title = ( isset( $reward['name'] ) && $reward['name'] !== '' ? $reward['name'] : esc_html__( 'Untitled Reward', 'simple-points-and-rewards' ) );
            echo esc_html( $reward_title );
            ?>
                                
                            </h4>
                            <div class="spar-reward-actions">
                                <button type="button" class="button spar-toggle-reward"><?php 
            esc_html_e( 'Edit', 'simple-points-and-rewards' );
            ?></button>
                                <?php 
            ?>
                            </div>
                        </div>
                        
                        <div class="spar-reward-content spar-hidden">
                            <input type="hidden" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][id]" value="<?php 
            echo esc_attr( $reward['id'] ?? spar_generate_reward_id() );
            ?>" />

                            <fieldset>
                            
                            <div class="spar-reward-row">
                                <div class="spar-reward-col">
                                    <label class="spar-toggle-label spar-flex-center-gap8">
                                        <span><?php 
            esc_html_e( 'Active', 'simple-points-and-rewards' );
            ?></span>
                                        &nbsp;
                                        <input type="hidden" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][status]" value="inactive" />
                                        <input type="checkbox" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][status]" value="active" <?php 
            checked( $reward['status'] ?? 'active', 'active' );
            ?> />
                                    </label>
                                </div>
                            </div>

                            <div class="spar-reward-row">
                                <div class="spar-reward-col">
                                    <label><?php 
            esc_html_e( 'Reward Type:', 'simple-points-and-rewards' );
            ?></label>
                                    <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][type]" class="reward-type-select" required>
                                        <option value="voucher" <?php 
            selected( $reward['type'] ?? 'voucher', 'voucher' );
            ?>><?php 
            esc_html_e( 'Discount Voucher/Coupon', 'simple-points-and-rewards' );
            ?></option>
                                        <option value="product" <?php 
            selected( $reward['type'] ?? 'voucher', 'product' );
            ?>><?php 
            esc_html_e( 'Free Product', 'simple-points-and-rewards' );
            ?></option>
                                        <?php 
            ?>
                                        <option value="product_bundle" disabled><?php 
            esc_html_e( 'Free Product Bundle (PRO)', 'simple-points-and-rewards' );
            ?></option>
                                        <?php 
            ?>
                                        <option value="custom" <?php 
            selected( $reward['type'] ?? 'voucher', 'custom' );
            ?>><?php 
            esc_html_e( 'Custom (Developer Hook)', 'simple-points-and-rewards' );
            ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="spar-reward-row">
                                <div class="spar-reward-col">
                                    <label><?php 
            esc_html_e( 'Reward Name:', 'simple-points-and-rewards' );
            ?></label>
                                    <input type="text" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][name]" value="<?php 
            echo esc_attr( $reward['name'] ?? '' );
            ?>" placeholder="<?php 
            esc_attr_e( '$10 Off Voucher', 'simple-points-and-rewards' );
            ?>" required />
                                </div>
                            </div>

                            <div class="spar-reward-row spar-reward-icon-row">
                                <div class="spar-reward-col spar-reward-icon-select-col">
                                    <label style="margin-top: 0;"><?php 
            esc_html_e( 'Reward Icon:', 'simple-points-and-rewards' );
            ?></label>
                                    <?php 
            $rw_is_product_like = in_array( $rw_type, array('product', 'product_bundle'), true );
            $rw_use_product_image = $rw_is_product_like && !empty( $reward['use_product_image'] );
            ?>
                                    <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_icon]" class="spar-reward-badge-select">
                                        <option value=""><?php 
            esc_html_e( 'Default (based on type)', 'simple-points-and-rewards' );
            ?></option>
                                        <option value="product_image" class="spar-product-image-option"<?php 
            echo ( $rw_is_product_like ? '' : ' disabled hidden' );
            ?> <?php 
            selected( $rw_use_product_image );
            ?>><?php 
            esc_html_e( 'Product Image', 'simple-points-and-rewards' );
            ?></option>
                                        <option value="custom_url" <?php 
            selected( 'custom_url', $rw_badge_type );
            ?>><?php 
            esc_html_e( 'Custom (Image URL)', 'simple-points-and-rewards' );
            ?></option>
                                        <option value="custom_text" <?php 
            selected( 'custom_text', $rw_badge_type );
            ?>><?php 
            esc_html_e( 'Custom (Text/Emoji)', 'simple-points-and-rewards' );
            ?></option>
                                        <?php 
            foreach ( $badge_icons as $icon => $label ) {
                ?>
                                            <option value="<?php 
                echo esc_attr( $icon );
                ?>" <?php 
                selected( $rw_selected_icon, $icon );
                ?>>
                                                <?php 
                echo esc_html( spar_format_badge_icon_option_label( $icon, $label ) );
                ?>
                                            </option>
                                        <?php 
            }
            ?>
                                    </select>
                                    <input type="hidden" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_type]" class="spar-reward-badge-type-field" value="<?php 
            echo esc_attr( $rw_badge_type );
            ?>" />
                                </div>
                                <div class="spar-reward-col spar-reward-badge-color-col <?php 
            echo ( $rw_show_badge_color ? '' : 'spar-hidden' );
            ?>" <?php 
            echo ( $rw_show_badge_color ? '' : 'style="display:none;"' );
            ?>>
                                    <label><?php 
            esc_html_e( 'Icon Color:', 'simple-points-and-rewards' );
            ?></label>
                                    <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_color_mode]" class="spar-reward-badge-color-mode-select" <?php 
            disabled( !$rw_show_badge_color );
            ?>>
                                        <option value="default" <?php 
            selected( $rw_badge_color_mode, 'default' );
            ?>><?php 
            esc_html_e( 'Default (Theme Colors)', 'simple-points-and-rewards' );
            ?></option>
                                        <option value="custom" <?php 
            selected( $rw_badge_color_mode, 'custom' );
            ?>><?php 
            esc_html_e( 'Custom', 'simple-points-and-rewards' );
            ?></option>
                                    </select>
                                    <input type="color" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_color]" class="spar-reward-badge-color-input" value="<?php 
            echo esc_attr( $rw_badge_color_value );
            ?>" <?php 
            disabled( !$rw_show_badge_color );
            ?> <?php 
            echo ( 'custom' !== $rw_badge_color_mode ? 'style="display:none;"' : '' );
            ?> />
                                </div>
                            </div>
                            <div class="spar-reward-row spar-reward-badge-url-row <?php 
            echo ( 'custom_url' === $rw_badge_type ? '' : 'spar-hidden' );
            ?>" data-badge-field="url">
                                <div class="spar-reward-col">
                                    <label><?php 
            esc_html_e( 'Custom Icon (Image URL):', 'simple-points-and-rewards' );
            ?></label>
                                    <span class="spar-media-upload-field">
                                        <input type="url" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_url]" class="spar-reward-badge-url-input" value="<?php 
            echo esc_attr( $rw_badge_data['badge_url'] ?? '' );
            ?>" placeholder="https://example.com/icon.png" />
                                        <button type="button" class="button spar-media-upload-button"><?php 
            esc_html_e( 'Upload / Select', 'simple-points-and-rewards' );
            ?></button>
                                    </span>
                                    <small><?php 
            esc_html_e( 'Provide an image URL to use as the reward icon.', 'simple-points-and-rewards' );
            ?></small>
                                </div>
                            </div>
                            <div class="spar-reward-row spar-reward-badge-text-row <?php 
            echo ( 'custom_text' === $rw_badge_type ? '' : 'spar-hidden' );
            ?>" data-badge-field="text">
                                <div class="spar-reward-col">
                                    <label><?php 
            esc_html_e( 'Custom Icon (Text/Emoji):', 'simple-points-and-rewards' );
            ?></label>
                                    <input type="text" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][badge_text]" class="spar-reward-badge-text-input" value="<?php 
            echo esc_attr( $rw_badge_data['badge_text'] ?? '' );
            ?>" placeholder="<?php 
            esc_attr_e( 'e.g. 🎁 or VIP', 'simple-points-and-rewards' );
            ?>" />
                                    <small><?php 
            esc_html_e( 'Shown instead of the preset icon when Custom (Text/Emoji) is selected.', 'simple-points-and-rewards' );
            ?></small>
                                </div>
                            </div>
                            <div class="spar-reward-row">
                                <div class="spar-reward-col">
                                    <label><?php 
            esc_html_e( 'Points Required:', 'simple-points-and-rewards' );
            ?></label>
                                    <input type="number" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][points]" value="<?php 
            echo esc_attr( $reward['points'] ?? '' );
            ?>" min="1" placeholder="100" required />
                                </div>

                                <div class="spar-reward-row">
                                    <div class="spar-reward-col">
                                        <label><?php 
            esc_html_e( 'Voucher Amount:', 'simple-points-and-rewards' );
            ?></label>
                                        <input type="number" step="0.01" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][voucher_amount]" value="<?php 
            echo esc_attr( $reward['voucher_amount'] ?? '' );
            ?>" min="0" placeholder="10.00" <?php 
            echo ( ($reward['type'] ?? 'voucher') === 'voucher' ? 'required' : 'disabled' );
            ?> />
                                    </div>
                                    
                                    <div class="spar-reward-col">
                                        <label><?php 
            esc_html_e( 'Discount Type:', 'simple-points-and-rewards' );
            ?></label>
                                        <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][discount_type]">
                                            <option value="fixed_cart" <?php 
            selected( $reward['discount_type'] ?? 'fixed_cart', 'fixed_cart' );
            ?>><?php 
            esc_html_e( 'Fixed Amount', 'simple-points-and-rewards' );
            ?></option>
                                            <option value="percent" <?php 
            selected( $reward['discount_type'] ?? 'fixed_cart', 'percent' );
            ?>><?php 
            esc_html_e( 'Percentage', 'simple-points-and-rewards' );
            ?></option>
                                        </select>
                                    </div>
                                </div>

                                <div class="spar-field-group">
                                    <label class="spar-toggle-label spar-flex-center-gap8">
                                        <input type="checkbox" id="spar-free-shipping-<?php 
            echo esc_attr( $index );
            ?>" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][free_shipping]" value="1" <?php 
            checked( !empty( $reward['free_shipping'] ) );
            ?> />
                                        <span><?php 
            esc_html_e( 'Free Shipping', 'simple-points-and-rewards' );
            ?></span>
                                    </label>
                                    <small><?php 
            esc_html_e( 'When enabled, the generated coupon will grant free shipping on the order (requires a Free Shipping method that accepts coupons).', 'simple-points-and-rewards' );
            ?></small>
                                </div>
                            </div>
                            
                            <!-- Custom Reward Settings -->
                            <div class="spar-custom-settings<?php 
            echo ( ($reward['type'] ?? 'voucher') === 'custom' ? '' : ' spar-hidden' );
            ?>">
                                <h5><?php 
            esc_html_e( 'Custom Reward Settings', 'simple-points-and-rewards' );
            ?></h5>
                                <div class="spar-field-group">
                                    <label><?php 
            esc_html_e( 'Short Description:', 'simple-points-and-rewards' );
            ?></label>
                                    <textarea name="rewards[<?php 
            echo esc_attr( $index );
            ?>][custom_description]" rows="3" placeholder="<?php 
            esc_attr_e( 'Explain what this custom reward grants the user', 'simple-points-and-rewards' );
            ?>" class="spar-full-width"><?php 
            echo ( isset( $reward['custom_description'] ) ? esc_html( $reward['custom_description'] ) : '' );
            ?></textarea>
                                    <small><?php 
            esc_html_e( 'Shown to customers when viewing available rewards.', 'simple-points-and-rewards' );
            ?></small>
                                </div>
                                <div class="spar-field-group">
                                    <label><?php 
            esc_html_e( 'Developer Reward ID:', 'simple-points-and-rewards' );
            ?></label>
                                    <input type="text" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][developer_id]" value="<?php 
            echo esc_attr( $reward['developer_id'] ?? '' );
            ?>" pattern="[a-z0-9\-_]{3,60}" placeholder="reward_internal_key" />
                                    <small><?php 
            esc_html_e( 'Lowercase unique identifier (used in hooks). Only letters, numbers, hyphens and underscores.', 'simple-points-and-rewards' );
            ?></small>
                                </div>
                                <p class="description" style="margin-top:8px;">
                                    <?php 
            esc_html_e( 'When a customer claims this reward, the plugin will fire the hook spar_custom_reward_claimed.', 'simple-points-and-rewards' );
            ?>
                                </p>
                            </div>

                            <!-- Product Settings -->
                            <div class="spar-product-settings<?php 
            echo ( ($reward['type'] ?? 'voucher') === 'product' ? '' : ' spar-hidden' );
            ?>">
                                <div class="spar-reward-row">
                                    <div class="spar-reward-col">
                                        <label><?php 
            esc_html_e( 'Product:', 'simple-points-and-rewards' );
            ?></label>
                                        <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][product_id]" class="spar-product-select spar-full-width" <?php 
            echo ( ($reward['type'] ?? 'voucher') === 'product' ? 'required' : 'disabled' );
            ?>>
                                            <?php 
            if ( !empty( $reward['product_id'] ) ) {
                $product = wc_get_product( $reward['product_id'] );
                if ( $product ) {
                    ?>
                                                    <option value="<?php 
                    echo esc_attr( $reward['product_id'] );
                    ?>" selected>
                                                        <?php 
                    echo esc_html( $product->get_name() . ' (#' . $reward['product_id'] . ')' );
                    ?>
                                                    </option>
                                                <?php 
                }
            }
            ?>
                                        </select>
                                        <small><?php 
            esc_html_e( 'Search and select a WooCommerce product for the free product reward', 'simple-points-and-rewards' );
            ?></small>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Bundle Settings (PRO only) -->
                            <?php 
            ?>

                            <?php 
            $rw_show_value_visible = in_array( $rw_type, array('product', 'product_bundle'), true );
            ?>
                            <div class="spar-field-group spar-reward-product-value-row<?php 
            echo ( $rw_show_value_visible ? '' : ' spar-hidden' );
            ?>"<?php 
            echo ( $rw_show_value_visible ? '' : ' style="display:none;"' );
            ?>>
                                <label class="spar-toggle-label spar-flex-center-gap8">
                                    <input type="checkbox" name="rewards[<?php 
            echo esc_attr( $index );
            ?>][show_product_value]" value="1" <?php 
            checked( !empty( $reward['show_product_value'] ) );
            ?> />
                                    <span><?php 
            esc_html_e( 'Show total value', 'simple-points-and-rewards' );
            ?></span>
                                </label>
                                <small><?php 
            esc_html_e( 'Display the total value of the product(s) under the name on the rewards/claim display.', 'simple-points-and-rewards' );
            ?></small>
                            </div>

                            <?php 
            ?>

                            <?php 
            $reward_template_coupon_id = ( isset( $reward['template_coupon_id'] ) ? absint( $reward['template_coupon_id'] ) : 0 );
            $tc_has_value = $reward_template_coupon_id > 0;
            ?>
                            <div class="spar-field-group">
                                <label class="spar-toggle-label" style="display:flex;align-items:center;gap:8px;">
                                    <div class="spar-toggle-switch">
                                        <input type="checkbox"
                                            class="spar-enable-template-coupon"
                                            <?php 
            checked( $tc_has_value );
            ?>
                                            <?php 
            disabled( $tc_has_value, true );
            ?>
                                        />
                                        <span class="spar-toggle-slider"></span>
                                    </div>
                                    <?php 
            esc_html_e( 'Enable Template Coupon', 'simple-points-and-rewards' );
            ?>
                                </label>
                            </div>
                            <div class="spar-template-coupon-section<?php 
            echo ( $tc_has_value ? '' : ' spar-hidden' );
            ?>">
                            <div class="spar-field-group">
                                <label><?php 
            esc_html_e( 'Template Coupon (optional):', 'simple-points-and-rewards' );
            ?></label>
                                <?php 
            $template_coupons = get_posts( [
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'     => '_spar_referral_coupon',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_spar_premium_voucher',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_spar_reward_voucher',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ] );
            ?>
                                <select name="rewards[<?php 
            echo esc_attr( $index );
            ?>][template_coupon_id]"
                                        id="spar-reward-template-coupon-<?php 
            echo esc_attr( $index );
            ?>"
                                        class="spar-reward-template-coupon spar-minw-280">
                                    <option value="0"><?php 
            esc_html_e( 'No template – use basic settings above', 'simple-points-and-rewards' );
            ?></option>
                                    <?php 
            foreach ( $template_coupons as $tc ) {
                if ( strpos( $tc->post_title, 'gift-' ) === 0 || strpos( $tc->post_title, 'ref-' ) === 0 || strpos( $tc->post_title, 'voucher-' ) === 0 || strpos( $tc->post_title, 'spin-' ) === 0 ) {
                    continue;
                }
                ?>
                                        <option value="<?php 
                echo esc_attr( $tc->ID );
                ?>" <?php 
                selected( $reward_template_coupon_id, $tc->ID );
                ?>>
                                            <?php 
                echo esc_html( $tc->post_title . ' (#' . $tc->ID . ')' );
                ?>
                                        </option>
                                    <?php 
            }
            ?>
                                </select>
                                <small><?php 
            esc_html_e( 'Select an existing coupon to use as a template. All coupon settings (minimum spend, product restrictions, usage limits, etc) will be copied to the generated voucher. Discount type, amount, shipping, expiry, and customer email are always set by the reward.', 'simple-points-and-rewards' );
            ?></small>
                                <div style="margin-top:6px;<?php 
            echo ( $reward_template_coupon_id > 0 ? ' display:none;' : '' );
            ?>">
                                    <button type="button" class="button button-secondary spar-create-template-coupon" data-index="<?php 
            echo esc_attr( $index );
            ?>"><?php 
            esc_html_e( 'Create New Template', 'simple-points-and-rewards' );
            ?></button>
                                </div>
                                <div id="spar-reward-template-coupon-link-<?php 
            echo esc_attr( $index );
            ?>" class="spar-my-5">
                                    <em><?php 
            esc_html_e( 'No template selected.', 'simple-points-and-rewards' );
            ?></em>
                                </div>
                            </div>
                            </div><!-- /.spar-template-coupon-section -->
                            </fieldset>
                        </div>
                    </div>
                <?php 
        }
        ?>
            <?php 
    }
    ?>
        </div>

            <?php 
    // The button is also kept in sync client-side as rows change.
    $spar_max_rewards = 3;
    $spar_rewards_at_max = count( $rewards ) >= $spar_max_rewards;
    ?>
            <p>
                <button type="button" id="spar-add-reward" class="button button-secondary<?php 
    echo ( $spar_rewards_at_max ? ' spar-disabled-button' : '' );
    ?>"<?php 
    disabled( $spar_rewards_at_max );
    ?>>
                    <?php 
    /* translators: %s: Rewards label */
    printf( esc_html__( 'Add New %s', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    echo ( $spar_rewards_at_max ? ' (PRO)' : '' );
    ?>
                </button>
            </p>
            <?php 
    if ( $spar_rewards_at_max ) {
        ?>
            <small><?php 
        esc_html_e( 'Add unlimited rewards with the PRO version.', 'simple-points-and-rewards' );
        ?></small>
            <?php 
    }
    ?>

            <div class="spar-rewards-info" style="margin-top: 16px;">
                <details class="spar-how-it-works">
                    <summary><?php 
    esc_html_e( 'How do rewards work?', 'simple-points-and-rewards' );
    ?></summary>
                    <ul>
                        <li><?php 
    esc_html_e( 'Customers can redeem points for vouchers or free products', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'Vouchers are automatically created as WooCommerce coupons', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'Free products are added to cart automatically when redeemed', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    /* translators: %s: Rewards label */
    printf( esc_html__( '%s appear on the customer account page and rewards widget', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    ?></li>
                    </ul>
                </details>
            </div>

            </div>

    </div>

        <?php 
    // Redemption controls moved here to appear at the bottom of the Rewards settings panel
    $redeem_individual_enabled = !empty( $options['redeem_individual_enabled'] );
    $redeem_individual_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'both' );
    $redeem_individual_display = ( in_array( $redeem_individual_display, array('totals', 'box', 'both'), true ) ? $redeem_individual_display : 'both' );
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
    $store_currency_name = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies()[$store_currency] ?? $store_currency : $store_currency );
    $redeem_ppp = ( isset( $options['redeem_points_per_points'] ) ? (float) $options['redeem_points_per_points'] : 100.0 );
    $redeem_ppa = ( isset( $options['redeem_points_per_amount'] ) && (float) $options['redeem_points_per_amount'] > 0 ? (float) $options['redeem_points_per_amount'] : 1.0 );
    $all_currencies = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [] );
    $redeem_rates = ( isset( $options['redeem_currency_rates'] ) && is_array( $options['redeem_currency_rates'] ) ? $options['redeem_currency_rates'] : [] );
    // Normalize rows: only keep valid
    $redeem_rates = array_values( array_filter( $redeem_rates, function ( $row ) {
        if ( !is_array( $row ) ) {
            return false;
        }
        $currency = ( isset( $row['currency'] ) ? trim( (string) $row['currency'] ) : '' );
        $amount = ( isset( $row['amount'] ) ? (float) $row['amount'] : 0.0 );
        return $currency !== '' && $amount > 0;
    } ) );
    ?>
        
    </div>

    <div class="spar-rewards-settings">

        <div class="spar-settings-section">
            <div class="spar-settings-section-header">
                <h4><?php 
    esc_html_e( 'Points Discount on Checkout', 'simple-points-and-rewards' );
    ?></h4>
                <p><?php 
    esc_html_e( 'Let customers redeem points directly for an instant cart or checkout discount.', 'simple-points-and-rewards' );
    ?></p>
            </div>
            <div class="spar-field-group">
                    <div class="spar-toggle-switch">
                        <input type="checkbox" id="spar-redeem-individual-enabled" name="redeem_individual_enabled" value="1" <?php 
    checked( $redeem_individual_enabled );
    ?> />
                        <span class="spar-toggle-slider"></span>
                    </div>
                    <span><?php 
    esc_html_e( 'Enable Points Discounts', 'simple-points-and-rewards' );
    ?></span>
                <p class="description"><?php 
    esc_html_e( 'Let customers redeem any number of their points directly at checkout as an instant discount.', 'simple-points-and-rewards' );
    ?></p>
            </div>

            <!-- Redemption Rate Settings (shown when toggle enabled) -->
            <div class="spar-field-group" data-toggle-input="redeem_individual_enabled">
                <p>
                    <?php 
    esc_html_e( 'This is a more simple, traditional points redemption method that does not require setting up individual rewards.', 'simple-points-and-rewards' );
    ?>
                </p>
                <p>
                    <?php 
    esc_html_e( 'With this option enabled, customers can easily redeem their points for a discount on the cart or checkout page.', 'simple-points-and-rewards' );
    ?>
                </p>
                <div class="spar-mb-10">
                    <label class="spar-block" for="spar-redeem-display">
                        <strong><?php 
    esc_html_e( 'Display discount redemption tool on', 'simple-points-and-rewards' );
    ?>:</strong>
                    </label>
                    <select id="spar-redeem-display" name="redeem_individual_display" class="spar-w-300">
                        <option value="both" <?php 
    selected( $redeem_individual_display, 'both' );
    ?>><?php 
    esc_html_e( 'Both locations: Checkout rewards box + Cart/checkout totals summary', 'simple-points-and-rewards' );
    ?></option>
                        <option value="totals" <?php 
    selected( $redeem_individual_display, 'totals' );
    ?>><?php 
    esc_html_e( 'Cart/checkout totals summary', 'simple-points-and-rewards' );
    ?></option>
                        <option value="box" <?php 
    selected( $redeem_individual_display, 'box' );
    ?>><?php 
    esc_html_e( 'Checkout rewards box', 'simple-points-and-rewards' );
    ?></option>
                    </select>
                </div>
                <?php 
    $redeem_individual_page = ( isset( $options['redeem_individual_page'] ) ? sanitize_key( $options['redeem_individual_page'] ) : 'both' );
    $redeem_individual_page = ( in_array( $redeem_individual_page, array('both', 'cart', 'checkout'), true ) ? $redeem_individual_page : 'both' );
    ?>
                <div class="spar-mb-10">
                    <label class="spar-block" for="spar-redeem-page"><strong><?php 
    esc_html_e( 'Show points discount on', 'simple-points-and-rewards' );
    ?>:</strong></label>
                    <select id="spar-redeem-page" name="redeem_individual_page" class="spar-w-300">
                        <option value="both" <?php 
    selected( $redeem_individual_page, 'both' );
    ?>><?php 
    esc_html_e( 'Cart + Checkout', 'simple-points-and-rewards' );
    ?></option>
                        <option value="cart" <?php 
    selected( $redeem_individual_page, 'cart' );
    ?>><?php 
    esc_html_e( 'Cart', 'simple-points-and-rewards' );
    ?></option>
                        <option value="checkout" <?php 
    selected( $redeem_individual_page, 'checkout' );
    ?>><?php 
    esc_html_e( 'Checkout', 'simple-points-and-rewards' );
    ?></option>
                    </select>
                </div>
                <?php 
    $redeem_discount_value_include_tax = !empty( $options['redeem_discount_value_include_tax'] );
    ?>
                <div class="spar-field-group">
                    <div class="spar-toggle-switch">
                        <input type="checkbox" name="redeem_discount_value_include_tax" value="1" <?php 
    checked( $redeem_discount_value_include_tax );
    ?> />
                        <span class="spar-toggle-slider"></span>
                    </div>
                    <span><?php 
    esc_html_e( 'Include tax in points preview', 'simple-points-and-rewards' );
    ?></span>
                    <p class="description"><?php 
    esc_html_e( 'When enabled, the Discount Value preview follows WooCommerce cart and checkout tax display: tax-inclusive stores show the combined value, while tax-exclusive stores show the base value with the tax amount beside it.', 'simple-points-and-rewards' );
    ?></p>
                </div>
                <?php 
    if ( $avalara_tax_active ) {
        ?>
                    <?php 
        $redeem_discount_fee_taxable = !empty( $options['redeem_discount_fee_taxable'] );
        $redeem_discount_fee_tax_class_mode = ( isset( $options['redeem_discount_fee_tax_class_mode'] ) && 'custom' === $options['redeem_discount_fee_tax_class_mode'] ? 'custom' : 'default' );
        $redeem_discount_fee_tax_class = ( function_exists( 'spar_sanitize_woocommerce_tax_class' ) ? spar_sanitize_woocommerce_tax_class( $options['redeem_discount_fee_tax_class'] ?? '' ) : '' );
        ?>
                    <div class="spar-field-group">
                        <div class="spar-toggle-switch">
                            <input type="checkbox" id="spar-redeem-discount-fee-taxable" name="redeem_discount_fee_taxable" value="1" <?php 
        checked( $redeem_discount_fee_taxable );
        ?> />
                            <span class="spar-toggle-slider"></span>
                        </div>
                        <span><?php 
        esc_html_e( 'Apply tax reduction to redeemed points', 'simple-points-and-rewards' );
        ?></span>
                        <p class="description"><?php 
        esc_html_e( 'When enabled, points redemptions are added as a taxable discount fee so Avalara can reduce the taxable order amount.', 'simple-points-and-rewards' );
        ?></p>
                    </div>
                    <div id="spar-redeem-discount-fee-tax-settings" class="spar-mt-10<?php 
        echo ( $redeem_discount_fee_taxable ? '' : ' spar-hidden' );
        ?>"<?php 
        echo ( $redeem_discount_fee_taxable ? '' : ' style="display:none;"' );
        ?>>
                        <label class="spar-block" for="spar-redeem-discount-fee-tax-class-mode" style="font-weight: bold;">
                            <?php 
        esc_html_e( 'Points redemption tax class:', 'simple-points-and-rewards' );
        ?>
                        </label>
                        <select id="spar-redeem-discount-fee-tax-class-mode" name="redeem_discount_fee_tax_class_mode" class="spar-w-300">
                            <option value="default" <?php 
        selected( $redeem_discount_fee_tax_class_mode, 'default' );
        ?>><?php 
        esc_html_e( 'Default tax class', 'simple-points-and-rewards' );
        ?></option>
                            <option value="custom" <?php 
        selected( $redeem_discount_fee_tax_class_mode, 'custom' );
        ?>><?php 
        esc_html_e( 'Custom tax class', 'simple-points-and-rewards' );
        ?></option>
                        </select>
                        <div id="spar-redeem-discount-fee-tax-class-wrap" class="spar-mt-10<?php 
        echo ( 'custom' === $redeem_discount_fee_tax_class_mode ? '' : ' spar-hidden' );
        ?>"<?php 
        echo ( 'custom' === $redeem_discount_fee_tax_class_mode ? '' : ' style="display:none;"' );
        ?>>
                            <select id="spar-redeem-discount-fee-tax-class" name="redeem_discount_fee_tax_class" class="spar-w-300">
                                <?php 
        foreach ( $tax_class_options as $tax_class_slug => $tax_class_label ) {
            ?>
                                    <option value="<?php 
            echo esc_attr( $tax_class_slug );
            ?>" <?php 
            selected( $redeem_discount_fee_tax_class, $tax_class_slug );
            ?>><?php 
            echo esc_html( $tax_class_label );
            ?></option>
                                <?php 
        }
        ?>
                            </select>
                        </div>
                    </div>
                <?php 
    }
    ?>
                <label class="spar-block" style="font-weight: bold;">
                    <?php 
    esc_html_e( 'Points Redemption Rate:', 'simple-points-and-rewards' );
    ?>
                </label>
                <div class="spar-default-currency-rate spar-flex-center-gap8 spar-mb-10">
                    <label class="spar-block"><?php 
    esc_html_e( 'Currency:', 'simple-points-and-rewards' );
    ?></label>
                    <select class="spar-w-200" disabled="disabled">
                        <option value="<?php 
    echo esc_attr( $store_currency );
    ?>"><?php 
    echo esc_html( $store_currency_name . ' (' . $store_currency . ')' );
    ?></option>
                    </select>
                    <label class="spar-block"><?php 
    esc_html_e( 'Number of points', 'simple-points-and-rewards' );
    ?>:</label>
                    <input class="spar-w-150" type="number" step="0.1" min="0" name="redeem_points_per_points" value="<?php 
    echo esc_attr( $redeem_ppp );
    ?>" />
                    <label class="spar-block spar-redeem-per-label" data-role="default"><?php 
    printf( esc_html__( 'Discount in %s', 'simple-points-and-rewards' ), esc_html( $store_currency ) );
    ?>:</label>
                    <input id="spar-redeem-default-amount" class="spar-w-150" type="number" step="0.01" min="0.01" name="redeem_points_per_amount" value="<?php 
    echo esc_attr( $redeem_ppa );
    ?>" />
                </div>

                <?php 
    ?>
                    <button type="button" class="button spar-add-redeem-currency-rate" style="margin: 5px 0 10px 0;" disabled="disabled"><?php 
    esc_html_e( 'Add New Currency', 'simple-points-and-rewards' );
    ?> (PRO)</button>
                <?php 
    ?>

            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #e2e2e2;" />
            <button type="button" class="button spar-redeem-advanced-toggle" aria-expanded="false" style="display: inline-flex; align-items: center; gap: 4px; margin: 5px 0 10px 0;">
                <?php 
    esc_html_e( 'Advanced Settings', 'simple-points-and-rewards' );
    ?>
                <span class="dashicons dashicons-arrow-down-alt2 spar-redeem-advanced-arrow" style="transition: transform 0.2s ease; line-height: 1;"></span>
            </button>
            <div class="spar-redeem-advanced-settings" style="display:none;">

            <?php 
    $redeem_button_text = ( isset( $options['redeem_button_text'] ) ? sanitize_text_field( $options['redeem_button_text'] ) : '' );
    $default_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    ?>
            <div class="spar-mt-10" style="flex-wrap:wrap;">
                <label class="spar-block" for="spar-redeem-button-text">
                    <?php 
    esc_html_e( 'Button text for "Add Discount to Cart"', 'simple-points-and-rewards' );
    ?>
                </label>
                <input
                    id="spar-redeem-button-text"
                    class="spar-w-300"
                    type="text"
                    name="redeem_button_text"
                    value="<?php 
    echo esc_attr( $redeem_button_text );
    ?>"
                    style="width:300px;"
                    placeholder="<?php 
    echo esc_attr( $default_button_label );
    ?>"
                />
            </div>

            <?php 
    $redeem_points_min = ( isset( $options['redeem_points_min'] ) ? (int) $options['redeem_points_min'] : 0 );
    $redeem_points_max = ( isset( $options['redeem_points_max'] ) ? (int) $options['redeem_points_max'] : 0 );
    $redeem_max_cart_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? (int) $options['redeem_max_cart_percentage'] : 0 );
    $redeem_min_cart_total = ( isset( $options['redeem_min_cart_total'] ) ? (float) $options['redeem_min_cart_total'] : 0 );
    ?>
            <?php 
    $redeem_text_color = ( isset( $options['redeem_text_color'] ) ? sanitize_hex_color( $options['redeem_text_color'] ) : '' );
    ?>
            <div class="spar-mt-10" style="flex-wrap:wrap;">
                <label class="spar-block" for="spar-redeem-text-color">
                    <?php 
    esc_html_e( 'Main Text Color', 'simple-points-and-rewards' );
    ?>
                </label>
                <input
                    id="spar-redeem-text-color"
                    type="color"
                    name="redeem_text_color"
                    value="<?php 
    echo esc_attr( ( $redeem_text_color ?: '#111827' ) );
    ?>"
                />
                <span class="description" style="display:block; margin-top:4px;">
                    <?php 
    esc_html_e( 'Controls the font color of descriptive text such as "You have X Reward Points" and limit notices inside the points discount tool.', 'simple-points-and-rewards' );
    ?>
                </span>
            </div>

            <div class="spar-mt-10">
                <?php 
    if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
        ?>
                    <label class="spar-block" for="spar-redeem-points-min">
                        <?php 
        esc_html_e( 'Minimum points per redemption:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <input id="spar-redeem-points-min"
                    class="spar-w-150" type="number" step="1" min="0"
                    style="width:150px;"
                    name="redeem_points_min" value="<?php 
        echo esc_attr( $redeem_points_min );
        ?>" />
                    <br/>
                    <label class="spar-block" for="spar-redeem-points-max">
                        <?php 
        esc_html_e( 'Maximum points per redemption:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <input id="spar-redeem-points-max"
                    class="spar-w-150" type="number" step="1" min="0"
                    style="width:150px;"
                    name="redeem_points_max" value="<?php 
        echo esc_attr( $redeem_points_max );
        ?>" />
                    <br/>
                    <label class="spar-block" for="spar-redeem-max-cart-percentage">
                        <?php 
        esc_html_e( 'Maximum cart percentage for points redemption:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <input id="spar-redeem-max-cart-percentage"
                    class="spar-w-150" type="number" step="1" min="0" max="100"
                    style="width:150px;"
                    name="redeem_max_cart_percentage" value="<?php 
        echo esc_attr( $redeem_max_cart_percentage );
        ?>" />
                    <span class="description">%</span>
                    <br/>
                    <label class="spar-block" for="spar-redeem-min-cart-total">
                        <?php 
        esc_html_e( 'Minimum cart total required to redeem points:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <input id="spar-redeem-min-cart-total"
                    class="spar-w-150" type="number" step="0.01" min="0"
                    style="width:150px;"
                    name="redeem_min_cart_total" value="<?php 
        echo esc_attr( $redeem_min_cart_total );
        ?>" />
                    <span class="description"><?php 
        echo esc_html( get_woocommerce_currency_symbol() );
        ?></span>
                    <span class="description" style="display:block; margin-top:4px;">
                        <?php 
        esc_html_e( 'Customers cannot pay with points until the cart subtotal reaches this amount. Set to 0 for no minimum.', 'simple-points-and-rewards' );
        ?>
                    </span>

                    <?php 
        $redeem_exclude_product_ids = ( isset( $options['redeem_exclude_product_ids'] ) && is_array( $options['redeem_exclude_product_ids'] ) ? array_values( array_filter( array_map( 'absint', $options['redeem_exclude_product_ids'] ) ) ) : [] );
        $redeem_exclude_category_ids = ( isset( $options['redeem_exclude_category_ids'] ) && is_array( $options['redeem_exclude_category_ids'] ) ? array_values( array_filter( array_map( 'absint', $options['redeem_exclude_category_ids'] ) ) ) : [] );
        ?>
                    <br/>
                    <label class="spar-block" for="spar-redeem-exclude-products">
                        <?php 
        esc_html_e( 'Exclude products from points redemption:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <select id="spar-redeem-exclude-products" class="spar-product-select" name="redeem_exclude_product_ids[]" multiple="multiple" style="width:100%; max-width:500px;">
                        <?php 
        foreach ( $redeem_exclude_product_ids as $pid ) {
            $product = ( function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null );
            if ( !$product ) {
                continue;
            }
            ?>
                            <option value="<?php 
            echo esc_attr( (int) $pid );
            ?>" selected="selected"><?php 
            echo esc_html( $product->get_name() );
            ?></option>
                        <?php 
        }
        ?>
                    </select>
                    <span class="description" style="display:block; margin-top:4px;">
                        <?php 
        esc_html_e( 'Points cannot be redeemed against the value of these products. Their value is removed from the cart total used to work out the maximum discount.', 'simple-points-and-rewards' );
        ?>
                    </span>

                    <label class="spar-block" for="spar-redeem-exclude-categories">
                        <?php 
        esc_html_e( 'Exclude product categories from points redemption:', 'simple-points-and-rewards' );
        ?>
                    </label>
                    <select id="spar-redeem-exclude-categories" class="spar-category-select" name="redeem_exclude_category_ids[]" multiple="multiple" style="width:100%; max-width:500px;">
                        <?php 
        foreach ( $redeem_exclude_category_ids as $cid ) {
            $term = get_term( $cid, 'product_cat' );
            if ( !$term || is_wp_error( $term ) ) {
                continue;
            }
            ?>
                            <option value="<?php 
            echo esc_attr( (int) $cid );
            ?>" selected="selected"><?php 
            echo esc_html( $term->name );
            ?></option>
                        <?php 
        }
        ?>
                    </select>
                    <span class="description" style="display:block; margin-top:4px;">
                        <?php 
        esc_html_e( 'Points cannot be redeemed against products in these categories. Their value is removed from the cart total used to work out the maximum discount.', 'simple-points-and-rewards' );
        ?>
                    </span>
                <?php 
    } else {
        ?>
                    <div style="margin-top:10px; opacity:0.5; pointer-events:none;">
                        <label class="spar-block" for="spar-redeem-points-min">
                            <?php 
        esc_html_e( 'Minimum points per redemption:', 'simple-points-and-rewards' );
        ?> (PRO)
                        </label>
                        <input id="spar-redeem-points-min"
                        class="spar-w-150" type="number" step="1" min="0"
                        style="width:150px;"
                        name="redeem_points_min" value="" disabled="disabled" />
                        <br/>
                        <label class="spar-block" for="spar-redeem-points-max">
                            <?php 
        esc_html_e( 'Maximum points per redemption:', 'simple-points-and-rewards' );
        ?> (PRO)
                        </label>
                        <input id="spar-redeem-points-max"
                        class="spar-w-150" type="number" step="1" min="0"
                        style="width:150px;"
                        name="redeem_points_max" value="" disabled="disabled" />
                        <br/>
                        <label class="spar-block" for="spar-redeem-max-cart-percentage">
                            <?php 
        esc_html_e( 'Maximum cart percentage for points redemption:', 'simple-points-and-rewards' );
        ?> (PRO)
                        </label>
                        <input id="spar-redeem-max-cart-percentage"
                        class="spar-w-150" type="number" step="1" min="0" max="100"
                        style="width:150px;"
                        name="redeem_max_cart_percentage" value="" disabled="disabled" />
                        <span class="description">%</span>
                        <br/>
                        <label class="spar-block" for="spar-redeem-min-cart-total">
                            <?php 
        esc_html_e( 'Minimum cart total required to redeem points:', 'simple-points-and-rewards' );
        ?> (PRO)
                        </label>
                        <input id="spar-redeem-min-cart-total"
                        class="spar-w-150" type="number" step="0.01" min="0"
                        style="width:150px;"
                        name="redeem_min_cart_total" value="" disabled="disabled" />
                        <span class="description"><?php 
        echo esc_html( get_woocommerce_currency_symbol() );
        ?></span>
                    </div>
                <?php 
    }
    ?>
            </div>

            </div><!-- /.spar-redeem-advanced-settings -->

        </div>
		</div>

    </div>

    <?php 
}
