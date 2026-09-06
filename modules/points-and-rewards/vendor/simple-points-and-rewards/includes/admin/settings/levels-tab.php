<?php

/**
 * Levels & Badges Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_levels() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $levels = ( isset( $options['levels'] ) ? $options['levels'] : [] );
    $levels = ( is_array( $levels ) ? $levels : [] );
    $badge_icons = spar_get_badge_icons();
    $starter_badge = spar_normalize_badge_fields(
        $options['levels_starter_badge_icon'] ?? 'fa-solid fa-star',
        $options['levels_starter_badge_url'] ?? '',
        $options['levels_starter_badge_type'] ?? '',
        $options['levels_starter_badge_text'] ?? ''
    );
    $starter_badge_type = $starter_badge['badge_type'];
    $starter_selected_icon = ( 'preset' === $starter_badge_type ? $starter_badge['badge_icon'] ?? '' : '' );
    $starter_badge_color = ( isset( $options['levels_starter_badge_color'] ) ? sanitize_hex_color( $options['levels_starter_badge_color'] ) : '' );
    $starter_badge_color = ( is_string( $starter_badge_color ) ? $starter_badge_color : '' );
    $starter_badge_color_mode = $options['levels_starter_badge_color_mode'] ?? 'default';
    $starter_badge_color_mode = ( in_array( $starter_badge_color_mode, array('default', 'custom'), true ) ? $starter_badge_color_mode : 'default' );
    $starter_badge_color_value = ( $starter_badge_color ?: '#667eea' );
    $starter_show_badge_color = 'custom_text' === $starter_badge_type || 'preset' === $starter_badge_type && function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $starter_selected_icon );
    $starter_badge_preview = spar_get_level_badge_markup( array(
        'badge_type'       => $starter_badge['badge_type'],
        'badge_icon'       => $starter_badge['badge_icon'],
        'badge_url'        => $starter_badge['badge_url'],
        'badge_text'       => $starter_badge['badge_text'],
        'badge_color'      => $starter_badge_color,
        'badge_color_mode' => $starter_badge_color_mode,
        'name'             => $options['levels_starter_name'] ?? esc_html__( 'Starter', 'simple-points-and-rewards' ),
    ), array(
        'icon_class'  => 'spar-level-badge-icon',
        'image_class' => 'spar-level-badge-img',
    ) );
    if ( !empty( $levels ) ) {
        foreach ( $levels as $level_index => $level_data ) {
            $badge_data = spar_normalize_badge_fields(
                $level_data['badge_icon'] ?? '',
                $level_data['badge_url'] ?? '',
                $level_data['badge_type'] ?? '',
                $level_data['badge_text'] ?? ''
            );
            $levels[$level_index] = array_merge( $level_data, $badge_data );
        }
    }
    ?>
    <div class="spar-levels-settings">
        <h3><?php 
    esc_html_e( 'Levels & Badges System', 'simple-points-and-rewards' );
    ?></h3>

		<p><?php 
    esc_html_e( 'Create a tiered loyalty system where customers unlock benefits and exclusive access as they earn more points.', 'simple-points-and-rewards' );
    ?></p>

		<div class="spar-settings-section spar-settings-section--master">
			<label class="spar-settings-toggle-label">
                <div class="spar-toggle-switch">
                    <input type="checkbox" name="levels_enabled" data-canonical-toggle="true" <?php 
    checked( !empty( $options['levels_enabled'] ) );
    ?> />
                    <span class="spar-toggle-slider"></span>
                </div>
				<span><?php 
    esc_html_e( 'Enable Levels & Badges System', 'simple-points-and-rewards' );
    ?></span>
            </label>
		</div>

        <div id="spar-levels-body" class="<?php 
    echo esc_attr( ( !empty( $options['levels_enabled'] ) ? '' : 'spar-hidden' ) );
    ?>">
            <div id="spar-levels-configuration">
            <div class="spar-settings-section spar-settings-section--list">
            
            <div class="spar-level-zero-option">
                <div class="spar-level-item spar-level-starter" data-index="starter">
                    <div class="spar-level-header clickable-header">
                        <span class="spar-level-badge">
                                <?php 
    echo ( $starter_badge_preview ? wp_kses_post( $starter_badge_preview ) : wp_kses_post( spar_get_badge_icon_markup( $starter_badge['badge_icon'] ?? 'fa-solid fa-star' ) ) );
    ?>
                        </span>
                        <h4>
                            <?php 
    echo esc_html( $options['levels_starter_name'] ?? esc_html__( 'Starter', 'simple-points-and-rewards' ) );
    ?>
                            <span class="spar-small spar-text-muted spar-ml-8"><?php 
    esc_html_e( '(Level 0)', 'simple-points-and-rewards' );
    ?></span>
                        </h4>
                        <div class="spar-level-actions">
                            <button type="button" class="button spar-toggle-level-starter"><?php 
    esc_html_e( 'Edit', 'simple-points-and-rewards' );
    ?></button>
                        </div>
                    </div>
                    <div class="spar-level-content-starter spar-hidden">
                        <div class="spar-p-18">
                            <div class="spar-level-row">
                                <div class="spar-level-col">
                                    <label><?php 
    esc_html_e( 'Level Name:', 'simple-points-and-rewards' );
    ?></label>
                                    <input type="text" name="levels_starter_name" value="<?php 
    echo esc_attr( $options['levels_starter_name'] ?? esc_html__( 'Starter', 'simple-points-and-rewards' ) );
    ?>" placeholder="<?php 
    esc_attr_e( 'Starter', 'simple-points-and-rewards' );
    ?>" />
                                </div>
                            </div>
                            <div class="spar-level-row spar-level-icon-row">
                                <div class="spar-level-col spar-level-icon-select-col">
                                    <label><?php 
    esc_html_e( 'Badge Icon:', 'simple-points-and-rewards' );
    ?></label>
                                    <select name="levels_starter_badge_icon" class="spar-badge-select" data-badge-context="starter">
                                        <option value=""><?php 
    esc_html_e( 'Select an icon', 'simple-points-and-rewards' );
    ?></option>
                                        <option value="custom_url" <?php 
    selected( 'custom_url', $starter_badge_type );
    ?>><?php 
    esc_html_e( 'Custom (Image URL)', 'simple-points-and-rewards' );
    ?></option>
                                        <option value="custom_text" <?php 
    selected( 'custom_text', $starter_badge_type );
    ?>><?php 
    esc_html_e( 'Custom (Text/Emoji)', 'simple-points-and-rewards' );
    ?></option>
                                        <?php 
    foreach ( $badge_icons as $icon => $label ) {
        ?>
                                            <option value="<?php 
        echo esc_attr( $icon );
        ?>" <?php 
        selected( $starter_selected_icon, $icon );
        ?>>
                                                <?php 
        echo esc_html( spar_format_badge_icon_option_label( $icon, $label ) );
        ?>
                                            </option>
                                        <?php 
    }
    ?>
                                    </select>
                                    <input type="hidden" name="levels_starter_badge_type" class="spar-badge-type-field" value="<?php 
    echo esc_attr( $starter_badge_type );
    ?>" />
                                </div>
                                <div class="spar-level-col spar-level-badge-color-col <?php 
    echo ( $starter_show_badge_color ? '' : 'spar-hidden' );
    ?>" <?php 
    echo ( $starter_show_badge_color ? '' : 'style="display:none;"' );
    ?>>
                                    <label><?php 
    esc_html_e( 'Icon Color:', 'simple-points-and-rewards' );
    ?></label>
                                    <select name="levels_starter_badge_color_mode" class="spar-level-badge-color-mode-select" <?php 
    disabled( !$starter_show_badge_color );
    ?>>
                                        <option value="default" <?php 
    selected( $starter_badge_color_mode, 'default' );
    ?>><?php 
    esc_html_e( 'Default (Theme Color)', 'simple-points-and-rewards' );
    ?></option>
                                        <option value="custom" <?php 
    selected( $starter_badge_color_mode, 'custom' );
    ?>><?php 
    esc_html_e( 'Custom', 'simple-points-and-rewards' );
    ?></option>
                                    </select>
                                    <input type="color" name="levels_starter_badge_color" class="spar-level-badge-color-input" value="<?php 
    echo esc_attr( $starter_badge_color_value );
    ?>" <?php 
    disabled( !$starter_show_badge_color );
    ?> <?php 
    echo ( 'custom' !== $starter_badge_color_mode ? 'style="display:none;"' : '' );
    ?> />
                                </div>
                            </div>
                            <div class="spar-level-row spar-badge-url-row <?php 
    echo ( 'custom_url' === $starter_badge_type ? '' : 'spar-hidden' );
    ?>" data-badge-field="url">
                                <div class="spar-level-col">
                                    <label><?php 
    esc_html_e( 'Custom Badge (Image URL):', 'simple-points-and-rewards' );
    ?></label>
                                    <input type="url" name="levels_starter_badge_url" class="spar-badge-url-input" value="<?php 
    echo esc_attr( $starter_badge['badge_url'] ?? '' );
    ?>" placeholder="https://example.com/badge.png" />
                                    <small><?php 
    esc_html_e( 'Provide an image URL that will be used when Custom (Image URL) is selected.', 'simple-points-and-rewards' );
    ?></small>
                                </div>
                            </div>
                            <div class="spar-level-row spar-badge-text-row <?php 
    echo ( 'custom_text' === $starter_badge_type ? '' : 'spar-hidden' );
    ?>" data-badge-field="text">
                                <div class="spar-level-col">
                                    <label><?php 
    esc_html_e( 'Custom Badge (Text/Emoji):', 'simple-points-and-rewards' );
    ?></label>
                                    <input type="text" name="levels_starter_badge_text" class="spar-badge-text-input" value="<?php 
    echo esc_attr( $starter_badge['badge_text'] ?? '' );
    ?>" placeholder="<?php 
    esc_attr_e( 'e.g. VIP or 😎', 'simple-points-and-rewards' );
    ?>" />
                                    <small><?php 
    esc_html_e( 'Shown instead of the preset icon when Custom (Text/Emoji) is selected.', 'simple-points-and-rewards' );
    ?></small>
                                </div>
                            </div>
                            <div class="spar-level-section-box">
                                <div class="spar-level-section-box-title"><?php 
    esc_html_e( 'Custom Benefits', 'simple-points-and-rewards' );
    ?></div>
                                <div class="spar-level-row">
                                    <div class="spar-level-col">
                                        <label><?php 
    esc_html_e( 'Custom Benefits:', 'simple-points-and-rewards' );
    ?></label>
                                        <textarea name="levels_starter_custom_benefits" placeholder="<?php 
    esc_attr_e( 'Enter one benefit per line...', 'simple-points-and-rewards' );
    ?>" rows="4" class="spar-full-width"><?php 
    echo esc_textarea( $options['levels_starter_custom_benefits'] ?? '' );
    ?></textarea>
                                        <small><?php 
    esc_html_e( 'Enter one benefit per line. These are added to the default benefits below and shown to customers for the Starter level.', 'simple-points-and-rewards' );
    ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php 
    $starter_capability = ( function_exists( 'spar_get_level_capability' ) ? spar_get_level_capability( 'level_0' ) : '' );
    ?>
                            <div class="spar-level-section-box">
                                <div class="spar-level-section-box-title"><?php 
    esc_html_e( 'User Capability', 'simple-points-and-rewards' );
    ?></div>
                                <div class="spar-level-row">
                                    <div class="spar-level-col">
                                        <label><?php 
    esc_html_e( 'Capability name:', 'simple-points-and-rewards' );
    ?></label>
                                        <input type="text" class="spar-level-capability-field spar-full-width" value="<?php 
    echo esc_attr( $starter_capability );
    ?>" readonly onclick="this.select();" />
                                        <small><?php 
    esc_html_e( 'Customers at this level are automatically granted this WordPress capability. Use it with a membership/restriction plugin or current_user_can() in your own code to give this level access to specific content.', 'simple-points-and-rewards' );
    ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="spar-level-row">
                                <div class="spar-level-col">
                                    <label><?php 
    esc_html_e( 'Benefits shown to customers:', 'simple-points-and-rewards' );
    ?></label>
                                    <ul class="spar-ul-indent" data-spar-starter-benefits>
                                        <?php 
    foreach ( spar_get_starter_level_benefits( $options ) as $starter_benefit ) {
        ?>
                                            <li><?php 
        echo esc_html( $starter_benefit );
        ?></li>
                                        <?php 
    }
    ?>
                                    </ul>
                                    <small><?php 
    esc_html_e( 'This is the exact list customers see. Add to it using the Custom Benefits field above.', 'simple-points-and-rewards' );
    ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="spar-levels-list">
                <?php 
    if ( !empty( $levels ) ) {
        ?>
                    <?php 
        foreach ( $levels as $index => $level ) {
            ?>

                        <div class="spar-level-item" data-index="<?php 
            echo esc_attr( $index );
            ?>">
                            <div class="spar-level-header clickable-header">
                                <span class="spar-drag-handle dashicons dashicons-move" aria-label="<?php 
            echo esc_attr__( 'Drag to reorder', 'simple-points-and-rewards' );
            ?>" title="<?php 
            echo esc_attr__( 'Drag to reorder', 'simple-points-and-rewards' );
            ?>"></span>
                                <?php 
            $level_badge_type = $level['badge_type'] ?? 'preset';
            $level_selected_icon = ( 'preset' === $level_badge_type ? $level['badge_icon'] ?? '' : '' );
            $level_badge_color = ( isset( $level['badge_color'] ) ? sanitize_hex_color( $level['badge_color'] ) : '' );
            $level_badge_color = ( is_string( $level_badge_color ) ? $level_badge_color : '' );
            $level_badge_color_mode = $level['badge_color_mode'] ?? 'default';
            $level_badge_color_mode = ( in_array( $level_badge_color_mode, array('default', 'custom'), true ) ? $level_badge_color_mode : 'default' );
            $level_badge_color_value = ( $level_badge_color ?: '#667eea' );
            $level_show_badge_color = 'custom_text' === $level_badge_type || 'preset' === $level_badge_type && function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $level_selected_icon );
            $level_badge_preview = spar_get_level_badge_markup( array_merge( $level, array(
                'name' => $level['name'] ?? '',
            ) ), array(
                'icon_class'  => 'spar-level-badge-icon',
                'image_class' => 'spar-level-badge-img',
            ) );
            $level_email_enabled = !empty( $level['level_up_email_enabled'] );
            $level_email_subject = ( isset( $level['level_up_email_subject'] ) ? (string) $level['level_up_email_subject'] : '' );
            $level_email_body = ( isset( $level['level_up_email_body'] ) ? (string) $level['level_up_email_body'] : '' );
            // Keep the customise email section closed by default.
            // Users can open it via the "Customise Email" button when needed.
            $level_email_open = false;
            $email_button_label = esc_html__( 'Customise Email', 'simple-points-and-rewards' );
            $level_email_subject_value = ( '' !== trim( $level_email_subject ) ? $level_email_subject : spar_get_default_level_email_subject() );
            ?>
                                <span class="spar-level-badge">
                                    <?php 
            echo ( $level_badge_preview ? wp_kses_post( $level_badge_preview ) : esc_html( $level_selected_icon ) );
            ?>
                                </span>
                                <h4>
                                    <?php 
            echo esc_html( $level['name'] ?? esc_html__( 'Untitled Level', 'simple-points-and-rewards' ) );
            ?>
                                </h4>
                                <div class="spar-level-actions">
                                    <button type="button" class="button spar-toggle-level"><?php 
            esc_html_e( 'Edit', 'simple-points-and-rewards' );
            ?></button>
                                    <button type="button" class="button spar-duplicate-level"><?php 
            esc_html_e( 'Duplicate', 'simple-points-and-rewards' );
            ?></button>
                                    <button type="button" class="button spar-delete-level"><?php 
            esc_html_e( 'Delete', 'simple-points-and-rewards' );
            ?></button>
                                </div>
                            </div>
                            
                            <div class="spar-level-content spar-hidden">
                                <input type="hidden" name="levels[<?php 
            echo esc_attr( $index );
            ?>][id]" value="<?php 
            echo esc_attr( $level['id'] ?? spar_generate_level_id() );
            ?>" />

                                <fieldset>
                                
                                <div class="spar-level-row">
                                    <div class="spar-level-col">
                                        <label><?php 
            esc_html_e( 'Level Name:', 'simple-points-and-rewards' );
            ?></label>
                                        <input type="text" name="levels[<?php 
            echo esc_attr( $index );
            ?>][name]" value="<?php 
            echo esc_attr( $level['name'] ?? '' );
            ?>" placeholder="<?php 
            esc_attr_e( 'Bronze Member', 'simple-points-and-rewards' );
            ?>" />
                                    </div>
                                    
                                    <div class="spar-level-col">
                                        <label><?php 
            esc_html_e( 'Required Points:', 'simple-points-and-rewards' );
            ?></label>
                                        <input type="number" name="levels[<?php 
            echo esc_attr( $index );
            ?>][required_points]" value="<?php 
            echo esc_attr( $level['required_points'] ?? '' );
            ?>" min="0" placeholder="100" />
                                    </div>
                                </div>
                                
                                <div class="spar-level-row spar-level-icon-row">
                                    <div class="spar-level-col spar-level-icon-select-col">
                                        <label><?php 
            esc_html_e( 'Badge Icon:', 'simple-points-and-rewards' );
            ?></label>
                                        <select name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_icon]" class="spar-badge-select" data-badge-context="level">
                                            <option value=""><?php 
            esc_html_e( 'Select an icon', 'simple-points-and-rewards' );
            ?></option>
                                            <option value="custom_url" <?php 
            selected( 'custom_url', $level_badge_type );
            ?>><?php 
            esc_html_e( 'Custom (Image URL)', 'simple-points-and-rewards' );
            ?></option>
                                            <option value="custom_text" <?php 
            selected( 'custom_text', $level_badge_type );
            ?>><?php 
            esc_html_e( 'Custom (Text/Emoji)', 'simple-points-and-rewards' );
            ?></option>
                                            <?php 
            foreach ( $badge_icons as $icon => $label ) {
                ?>
                                                <option value="<?php 
                echo esc_attr( $icon );
                ?>" <?php 
                selected( $level_selected_icon, $icon );
                ?>>
                                                    <?php 
                echo esc_html( spar_format_badge_icon_option_label( $icon, $label ) );
                ?>
                                                </option>
                                            <?php 
            }
            ?>
                                        </select>
                                        <input type="hidden" name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_type]" class="spar-badge-type-field" value="<?php 
            echo esc_attr( $level_badge_type );
            ?>" />
                                    </div>
                                    <div class="spar-level-col spar-level-badge-color-col <?php 
            echo ( $level_show_badge_color ? '' : 'spar-hidden' );
            ?>" <?php 
            echo ( $level_show_badge_color ? '' : 'style="display:none;"' );
            ?>>
                                        <label><?php 
            esc_html_e( 'Icon Color:', 'simple-points-and-rewards' );
            ?></label>
                                        <select name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_color_mode]" class="spar-level-badge-color-mode-select" <?php 
            disabled( !$level_show_badge_color );
            ?>>
                                            <option value="default" <?php 
            selected( $level_badge_color_mode, 'default' );
            ?>><?php 
            esc_html_e( 'Default (Theme Color)', 'simple-points-and-rewards' );
            ?></option>
                                            <option value="custom" <?php 
            selected( $level_badge_color_mode, 'custom' );
            ?>><?php 
            esc_html_e( 'Custom', 'simple-points-and-rewards' );
            ?></option>
                                        </select>
                                        <input type="color" name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_color]" class="spar-level-badge-color-input" value="<?php 
            echo esc_attr( $level_badge_color_value );
            ?>" <?php 
            disabled( !$level_show_badge_color );
            ?> <?php 
            echo ( 'custom' !== $level_badge_color_mode ? 'style="display:none;"' : '' );
            ?> />
                                    </div>
                                </div>
                                <div class="spar-level-row spar-badge-url-row <?php 
            echo ( 'custom_url' === $level_badge_type ? '' : 'spar-hidden' );
            ?>" data-badge-field="url">
                                    <div class="spar-level-col">
                                        <label><?php 
            esc_html_e( 'Custom Badge (Image URL):', 'simple-points-and-rewards' );
            ?></label>
                                        <input type="url" name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_url]" class="spar-badge-url-input" value="<?php 
            echo esc_attr( $level['badge_url'] ?? '' );
            ?>" placeholder="https://example.com/badge.png" />
                                        <small><?php 
            esc_html_e( 'Provide an image URL that will be used when Custom (Image URL) is selected.', 'simple-points-and-rewards' );
            ?></small>
                                    </div>
                                </div>
                                <div class="spar-level-row spar-badge-text-row <?php 
            echo ( 'custom_text' === $level_badge_type ? '' : 'spar-hidden' );
            ?>" data-badge-field="text">
                                    <div class="spar-level-col">
                                        <label><?php 
            esc_html_e( 'Custom Badge (Text/Emoji):', 'simple-points-and-rewards' );
            ?></label>
                                        <input type="text" name="levels[<?php 
            echo esc_attr( $index );
            ?>][badge_text]" class="spar-badge-text-input" value="<?php 
            echo esc_attr( $level['badge_text'] ?? '' );
            ?>" placeholder="<?php 
            esc_attr_e( 'e.g. VIP or 😎', 'simple-points-and-rewards' );
            ?>" />
                                        <small><?php 
            esc_html_e( 'Shown instead of the preset icon when Custom (Text/Emoji) is selected.', 'simple-points-and-rewards' );
            ?></small>
                                    </div>
                                </div>
                                
                                <h5 style="margin-top: 0;"><?php 
            esc_html_e( 'Level Benefits', 'simple-points-and-rewards' );
            ?></h5>

                                <div class="spar-level-section-box">
                                    <div class="spar-level-section-box-title"><?php 
            esc_html_e( 'Points Multiplier', 'simple-points-and-rewards' );
            ?></div>
                                    <div class="spar-level-row">
                                        <div class="spar-level-col">
                                            <label><?php 
            esc_html_e( 'Points Multiplier:', 'simple-points-and-rewards' );
            ?></label>
                                            <input type="number" step="0.01" name="levels[<?php 
            echo esc_attr( $index );
            ?>][points_multiplier]" value="<?php 
            echo esc_attr( $level['points_multiplier'] ?? '1.0' );
            ?>" min="0" placeholder="1.0" class="spar-global-multiplier-input" />
                                            <small><?php 
            esc_html_e( 'Multiply all earned points by this amount (e.g., 1.5 = 50% bonus on all activities)', 'simple-points-and-rewards' );
            ?></small>
                                        </div>
                                    </div>

                                    <?php 
            ?>
                                    <div class="spar-level-row">
                                        <div class="spar-level-col">
                                            <label style="opacity: 0.6; cursor: default;">
                                                <input type="checkbox" disabled />
                                                <?php 
            esc_html_e( 'Use different multipliers per earn type', 'simple-points-and-rewards' );
            ?>
                                                <span class="spar-premium-settings-badge">PRO</span>
                                            </label>
                                            <small><?php 
            esc_html_e( 'Override the global multiplier above with a unique multiplier for each way to earn points.', 'simple-points-and-rewards' );
            ?></small>
                                        </div>
                                    </div>
                                    <?php 
            ?>
                                </div>

                                <div class="spar-level-section-box">
                                    <div class="spar-level-section-box-title"><?php 
            esc_html_e( 'Custom Benefits', 'simple-points-and-rewards' );
            ?></div>
                                    <div class="spar-level-row">
                                        <div class="spar-level-col">
                                            <label><?php 
            esc_html_e( 'Custom Benefits:', 'simple-points-and-rewards' );
            ?></label>
                                            <textarea name="levels[<?php 
            echo esc_attr( $index );
            ?>][custom_benefits]" placeholder="<?php 
            esc_attr_e( 'Enter one benefit per line...', 'simple-points-and-rewards' );
            ?>" rows="4" class="spar-full-width"><?php 
            echo esc_textarea( $level['custom_benefits'] ?? '' );
            ?></textarea>
                                            <small><?php 
            esc_html_e( 'Enter one benefit per line. These will be displayed to customers alongside the automatic benefits.', 'simple-points-and-rewards' );
            ?></small>
                                        </div>
                                    </div>
                                </div>

                                <?php 
            $level_capability = ( function_exists( 'spar_get_level_capability' ) ? spar_get_level_capability( $level ) : '' );
            ?>
                                <div class="spar-level-section-box">
                                    <div class="spar-level-section-box-title"><?php 
            esc_html_e( 'User Capability', 'simple-points-and-rewards' );
            ?></div>
                                    <div class="spar-level-row">
                                        <div class="spar-level-col">
                                            <label><?php 
            esc_html_e( 'Capability name:', 'simple-points-and-rewards' );
            ?></label>
                                            <input type="text" class="spar-level-capability-field spar-full-width" value="<?php 
            echo esc_attr( $level_capability );
            ?>" readonly onclick="this.select();" />
                                            <small><?php 
            esc_html_e( 'Customers at this level are automatically granted this WordPress capability. Use it with a membership/restriction plugin or current_user_can() in your own code to give this level access to specific content.', 'simple-points-and-rewards' );
            ?></small>
                                        </div>
                                    </div>
                                </div>

                                <div class="spar-level-section-box">
                                    <div class="spar-level-section-box-title"><?php 
            esc_html_e( 'Level Up Email', 'simple-points-and-rewards' );
            ?></div>
                                    <div class="spar-level-row spar-level-email-row">
                                        <div class="spar-level-col">
                                            <label>
                                                <input type="hidden" name="levels[<?php 
            echo esc_attr( $index );
            ?>][level_up_email_enabled]" value="0" />
                                                <input type="checkbox" name="levels[<?php 
            echo esc_attr( $index );
            ?>][level_up_email_enabled]" value="1" class="spar-level-email-toggle" <?php 
            checked( $level_email_enabled );
            ?> />
                                                <span><?php 
            esc_html_e( 'Send email to customer on level up', 'simple-points-and-rewards' );
            ?></span>
                                            </label>
                                            <small><?php 
            esc_html_e( 'Email is sent as soon as the customer reaches this level.', 'simple-points-and-rewards' );
            ?></small>
                                            <div class="spar-level-email-actions<?php 
            echo ( $level_email_enabled ? '' : ' spar-hidden' );
            ?>">
                                                <button type="button" class="button button-secondary spar-level-email-customize" data-open-text="<?php 
            esc_attr_e( 'Customise Email', 'simple-points-and-rewards' );
            ?>" data-close-text="<?php 
            esc_attr_e( 'Close', 'simple-points-and-rewards' );
            ?>">
                                                    <?php 
            echo esc_html( $email_button_label );
            ?>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
            if ( !isset( $level_body_editor_index ) ) {
                $level_body_editor_index = 0;
            }
            $level_body_editor_index++;
            $editor_id = 'level_up_email_body_' . $level_body_editor_index;
            $editor_value = $level_email_body;
            $editor_value = ( '' === trim( wp_strip_all_tags( $editor_value ) ) ? spar_get_default_level_email_body() : $editor_value );
            ?>
                                    <div class="spar-level-row spar-level-email-fields <?php 
            echo ( $level_email_open ? '' : 'spar-hidden' );
            ?>" data-email-fields="1">
                                        <div class="spar-level-col">
                                            <label><?php 
            esc_html_e( 'Subject:', 'simple-points-and-rewards' );
            ?></label>
                                            <input type="text" name="levels[<?php 
            echo esc_attr( $index );
            ?>][level_up_email_subject]" value="<?php 
            echo esc_attr( $level_email_subject_value );
            ?>" placeholder="<?php 
            esc_attr_e( 'Congratulations! You reached {level_name}', 'simple-points-and-rewards' );
            ?>" class="spar-full-width" />
                                            <label><?php 
            esc_html_e( 'Email Body:', 'simple-points-and-rewards' );
            ?></label>
                                            <?php 
            wp_editor( $editor_value, $editor_id, array(
                'textarea_name' => 'levels[' . esc_attr( $index ) . '][level_up_email_body]',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'         => true,
            ) );
            ?>
                                            <small>
                                                <?php 
            esc_html_e( 'Available placeholders: {user_name}, {user_email}, {level_name}, {previous_level_name}, {points_label}, {total_points}, {site_name}, {site_url}, {rewards_url}', 'simple-points-and-rewards' );
            ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
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
    // The button is also kept in sync client-side as rows are added/removed.
    $spar_max_levels = 1;
    $spar_levels_at_max = count( $levels ) >= $spar_max_levels;
    ?>
        <p>
            <button type="button" id="spar-add-level" class="button button-secondary<?php 
    echo ( $spar_levels_at_max ? ' spar-disabled-button' : '' );
    ?>"<?php 
    disabled( $spar_levels_at_max );
    ?>>
                <?php 
    esc_html_e( 'Add New Level', 'simple-points-and-rewards' );
    echo ( $spar_levels_at_max ? ' (PRO)' : '' );
    ?>
            </button>
        </p>
        <?php 
    if ( $spar_levels_at_max ) {
        ?>
            <small><?php 
        esc_html_e( 'Add unlimited levels with the PRO version.', 'simple-points-and-rewards' );
        ?></small>
        <?php 
    }
    ?>
            
            <div class="spar-levels-info">
                <details class="spar-how-it-works">
                    <summary><?php 
    esc_html_e( 'How It Works', 'simple-points-and-rewards' );
    ?></summary>
                    <ul>
                        <li><?php 
    esc_html_e( 'Customers automatically reach new levels when they accumulate enough points', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'Higher levels can earn more points per order and referral bonuses', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'You can restrict access to specific products and pages by level', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'Each level has its own WordPress capability (shown when editing the level) that is granted to customers at that level, so you can gate content with a membership plugin or your own code', 'simple-points-and-rewards' );
    ?></li>
                        <li><?php 
    esc_html_e( 'Levels are displayed on the customer\'s account page and rewards widget', 'simple-points-and-rewards' );
    ?></li>
                    </ul>
                </details>
            </div>

            </div>

            <div class="spar-settings-section">
            <div class="spar-settings-section-header">
                <h4><?php 
    esc_html_e( 'Level Points Requirement', 'simple-points-and-rewards' );
    ?></h4>
                <p><?php 
    esc_html_e( 'Choose how the required points for levels are calculated.', 'simple-points-and-rewards' );
    ?></p>
            </div>

            <select name="levels_points_type">
                <option value="total_earned" <?php 
    selected( $options['levels_points_type'] ?? 'total_earned', 'total_earned' );
    ?>>
                    <?php 
    esc_html_e( 'Total Points Earned (Lifetime)', 'simple-points-and-rewards' );
    ?>
                </option>
                <option value="available_balance" <?php 
    selected( $options['levels_points_type'] ?? 'total_earned', 'available_balance' );
    ?>>
                    <?php 
    esc_html_e( 'Available Points Balance', 'simple-points-and-rewards' );
    ?>
                </option>
            </select>
            <br/>
            <small class="spar-text-muted">
                <?php 
    esc_html_e( 'Total Points Earned: Levels are based on the total points a customer has earned over time. Points spent on rewards do not affect their level.', 'simple-points-and-rewards' );
    ?>
                <br/>
                <?php 
    esc_html_e( 'Available Points Balance: Levels are based on the customer\'s current available points balance. Spending points on rewards may lower their level.', 'simple-points-and-rewards' );
    ?>
            </small>
			</div>
        
            </div><!-- /#spar-levels-configuration -->
        </div><!-- /#spar-levels-body -->
    </div>
    

    <?php 
}
