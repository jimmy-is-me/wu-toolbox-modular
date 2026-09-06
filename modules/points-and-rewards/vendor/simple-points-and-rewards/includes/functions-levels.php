<?php

/**
 * Levels and Badges Functions
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Get shared Font Awesome icon options.
 *
 * @return array Font Awesome class => label.
 */
function spar_get_font_awesome_icon_options() {
    $icons = [
        'fa-solid fa-star'                => esc_html__( 'Font Awesome - Star', 'simple-points-and-rewards' ),
        'fa-solid fa-trophy'              => esc_html__( 'Font Awesome - Trophy', 'simple-points-and-rewards' ),
        'fa-solid fa-medal'               => esc_html__( 'Font Awesome - Medal', 'simple-points-and-rewards' ),
        'fa-solid fa-award'               => esc_html__( 'Font Awesome - Award', 'simple-points-and-rewards' ),
        'fa-solid fa-ranking-star'        => esc_html__( 'Font Awesome - Ranking Star', 'simple-points-and-rewards' ),
        'fa-solid fa-crown'               => esc_html__( 'Font Awesome - Crown', 'simple-points-and-rewards' ),
        'fa-solid fa-gem'                 => esc_html__( 'Font Awesome - Gem', 'simple-points-and-rewards' ),
        'fa-solid fa-diamond'             => esc_html__( 'Font Awesome - Diamond', 'simple-points-and-rewards' ),
        'fa-solid fa-ring'                => esc_html__( 'Font Awesome - Ring', 'simple-points-and-rewards' ),
        'fa-solid fa-gift'                => esc_html__( 'Font Awesome - Gift', 'simple-points-and-rewards' ),
        'fa-solid fa-gifts'               => esc_html__( 'Font Awesome - Gifts', 'simple-points-and-rewards' ),
        'fa-solid fa-ticket'              => esc_html__( 'Font Awesome - Ticket', 'simple-points-and-rewards' ),
        'fa-solid fa-tag'                 => esc_html__( 'Font Awesome - Tag', 'simple-points-and-rewards' ),
        'fa-solid fa-tags'                => esc_html__( 'Font Awesome - Tags', 'simple-points-and-rewards' ),
        'fa-solid fa-percent'             => esc_html__( 'Font Awesome - Percent', 'simple-points-and-rewards' ),
        'fa-solid fa-cart-shopping'       => esc_html__( 'Font Awesome - Shopping Cart', 'simple-points-and-rewards' ),
        'fa-solid fa-bag-shopping'        => esc_html__( 'Font Awesome - Shopping Bag', 'simple-points-and-rewards' ),
        'fa-solid fa-basket-shopping'     => esc_html__( 'Font Awesome - Shopping Basket', 'simple-points-and-rewards' ),
        'fa-solid fa-store'               => esc_html__( 'Font Awesome - Store', 'simple-points-and-rewards' ),
        'fa-solid fa-credit-card'         => esc_html__( 'Font Awesome - Credit Card', 'simple-points-and-rewards' ),
        'fa-solid fa-wallet'              => esc_html__( 'Font Awesome - Wallet', 'simple-points-and-rewards' ),
        'fa-solid fa-money-bill'          => esc_html__( 'Font Awesome - Money Bill', 'simple-points-and-rewards' ),
        'fa-solid fa-money-bill-wave'     => esc_html__( 'Font Awesome - Money Wave', 'simple-points-and-rewards' ),
        'fa-solid fa-coins'               => esc_html__( 'Font Awesome - Coins', 'simple-points-and-rewards' ),
        'fa-solid fa-sack-dollar'         => esc_html__( 'Font Awesome - Money Bag', 'simple-points-and-rewards' ),
        'fa-solid fa-piggy-bank'          => esc_html__( 'Font Awesome - Piggy Bank', 'simple-points-and-rewards' ),
        'fa-solid fa-heart'               => esc_html__( 'Font Awesome - Heart', 'simple-points-and-rewards' ),
        'fa-solid fa-thumbs-up'           => esc_html__( 'Font Awesome - Thumbs Up', 'simple-points-and-rewards' ),
        'fa-solid fa-hand-holding-heart'  => esc_html__( 'Font Awesome - Hand Holding Heart', 'simple-points-and-rewards' ),
        'fa-solid fa-fire'                => esc_html__( 'Font Awesome - Fire', 'simple-points-and-rewards' ),
        'fa-solid fa-bolt'                => esc_html__( 'Font Awesome - Lightning Bolt', 'simple-points-and-rewards' ),
        'fa-solid fa-rocket'              => esc_html__( 'Font Awesome - Rocket', 'simple-points-and-rewards' ),
        'fa-solid fa-wand-magic-sparkles' => esc_html__( 'Font Awesome - Magic Sparkles', 'simple-points-and-rewards' ),
        'fa-solid fa-certificate'         => esc_html__( 'Font Awesome - Certificate', 'simple-points-and-rewards' ),
        'fa-solid fa-ribbon'              => esc_html__( 'Font Awesome - Ribbon', 'simple-points-and-rewards' ),
        'fa-solid fa-bullseye'            => esc_html__( 'Font Awesome - Target', 'simple-points-and-rewards' ),
        'fa-solid fa-thumbtack'           => esc_html__( 'Font Awesome - Thumbtack', 'simple-points-and-rewards' ),
        'fa-solid fa-bell'                => esc_html__( 'Font Awesome - Bell', 'simple-points-and-rewards' ),
        'fa-solid fa-shield-halved'       => esc_html__( 'Font Awesome - Shield', 'simple-points-and-rewards' ),
        'fa-solid fa-user'                => esc_html__( 'Font Awesome - User', 'simple-points-and-rewards' ),
        'fa-solid fa-users'               => esc_html__( 'Font Awesome - Users', 'simple-points-and-rewards' ),
        'fa-solid fa-user-plus'           => esc_html__( 'Font Awesome - User Plus', 'simple-points-and-rewards' ),
        'fa-solid fa-cake-candles'        => esc_html__( 'Font Awesome - Birthday Cake', 'simple-points-and-rewards' ),
        'fa-solid fa-mug-hot'             => esc_html__( 'Font Awesome - Hot Mug', 'simple-points-and-rewards' ),
        'fa-solid fa-truck-fast'          => esc_html__( 'Font Awesome - Fast Delivery', 'simple-points-and-rewards' ),
        'fa-solid fa-earth-americas'      => esc_html__( 'Font Awesome - Globe', 'simple-points-and-rewards' ),
        'fa-solid fa-leaf'                => esc_html__( 'Font Awesome - Leaf', 'simple-points-and-rewards' ),
        'fa-solid fa-seedling'            => esc_html__( 'Font Awesome - Seedling', 'simple-points-and-rewards' ),
        'fa-solid fa-check'               => esc_html__( 'Font Awesome - Check', 'simple-points-and-rewards' ),
        'fa-solid fa-circle-check'        => esc_html__( 'Font Awesome - Check Circle', 'simple-points-and-rewards' ),
        'fa-solid fa-gear'                => esc_html__( 'Font Awesome - Gear', 'simple-points-and-rewards' ),
        'fa-solid fa-cogs'                => esc_html__( 'Font Awesome - Cogs', 'simple-points-and-rewards' ),
        'fa-solid fa-key'                 => esc_html__( 'Font Awesome - Key', 'simple-points-and-rewards' ),
        'fa-solid fa-lock'                => esc_html__( 'Font Awesome - Lock', 'simple-points-and-rewards' ),
        'fa-solid fa-hashtag'             => esc_html__( 'Font Awesome - Hashtag', 'simple-points-and-rewards' ),
    ];
    /**
     * Filter the available shared Font Awesome icons.
     *
     * @param array $icons Array of Font Awesome class => label.
     */
    return apply_filters( 'spar_font_awesome_icon_options', $icons );
}

/**
 * Sanitize a Font Awesome icon class value.
 *
 * @param string $icon Icon value.
 * @return string Sanitized Font Awesome classes, or an empty string.
 */
function spar_get_font_awesome_icon_class(  $icon  ) {
    if ( !is_scalar( $icon ) ) {
        return '';
    }
    $icon = sanitize_text_field( (string) $icon );
    $icon = trim( (string) preg_replace( '/\\s+/', ' ', $icon ) );
    if ( '' === $icon ) {
        return '';
    }
    $classes = array_filter( explode( ' ', $icon ) );
    $safe = [];
    $has_type = false;
    $has_icon = false;
    foreach ( $classes as $class ) {
        $class = sanitize_html_class( $class );
        if ( '' === $class || 0 !== strpos( $class, 'fa-' ) ) {
            continue;
        }
        if ( in_array( $class, ['fa-solid', 'fa-regular', 'fa-brands'], true ) ) {
            $has_type = true;
        } elseif ( preg_match( '/^fa-[a-z0-9-]+$/', $class ) ) {
            $has_icon = true;
        }
        $safe[] = $class;
    }
    $safe = array_values( array_unique( $safe ) );
    if ( !$has_type || !$has_icon ) {
        return '';
    }
    return implode( ' ', $safe );
}

/**
 * Check whether an icon value is a Font Awesome class string.
 *
 * @param string $icon Icon value.
 * @return bool
 */
function spar_is_font_awesome_icon_value(  $icon  ) {
    return '' !== spar_get_font_awesome_icon_class( $icon );
}

/**
 * Format a select option label for a badge icon.
 *
 * @param string $icon  Icon value.
 * @param string $label Icon label.
 * @return string
 */
function spar_format_badge_icon_option_label(  $icon, $label  ) {
    return ( spar_is_font_awesome_icon_value( $icon ) ? $label : trim( $icon . ' ' . $label ) );
}

/**
 * Render a preset icon value.
 *
 * @param string $icon       Emoji/text or Font Awesome class value.
 * @param string $icon_class Base icon class.
 * @param string $icon_color Optional hex color for Font Awesome icons.
 * @return string
 */
function spar_get_badge_icon_markup(
    $icon,
    $icon_class = 'spar-level-badge-icon',
    $icon_color = '',
    $icon_color_mode = ''
) {
    if ( !is_scalar( $icon ) || '' === (string) $icon ) {
        return '';
    }
    $fa_class = spar_get_font_awesome_icon_class( $icon );
    if ( '' !== $fa_class ) {
        $style = '';
        $extra_class = '';
        if ( 'default' === $icon_color_mode ) {
            $extra_class = ' spar-badge-icon--gradient';
        } else {
            $color = ( is_scalar( $icon_color ) ? sanitize_hex_color( (string) $icon_color ) : '' );
            $color = ( is_string( $color ) ? $color : '' );
            if ( '' !== $color ) {
                $style = ' style="color:' . esc_attr( $color ) . ';"';
            }
        }
        return '<span class="' . esc_attr( trim( $icon_class . ' spar-fa-icon ' . $fa_class ) ) . $extra_class . '" aria-hidden="true"' . $style . '></span>';
    }
    return '<span class="' . esc_attr( $icon_class ) . '">' . esc_html( $icon ) . '</span>';
}

/**
 * Get available badge icons for levels.
 */
function spar_get_badge_icons() {
    $icons = spar_get_font_awesome_icon_options() + [
        '🥉' => esc_html__( 'Bronze Medal', 'simple-points-and-rewards' ),
        '🥈' => esc_html__( 'Silver Medal', 'simple-points-and-rewards' ),
        '🥇' => esc_html__( 'Gold Medal', 'simple-points-and-rewards' ),
        '⭐'  => esc_html__( 'Star', 'simple-points-and-rewards' ),
        '🌟' => esc_html__( 'Glowing Star', 'simple-points-and-rewards' ),
        '✨'  => esc_html__( 'Sparkles', 'simple-points-and-rewards' ),
        '🏆' => esc_html__( 'Trophy', 'simple-points-and-rewards' ),
        '👑' => esc_html__( 'Crown', 'simple-points-and-rewards' ),
        '💎' => esc_html__( 'Diamond', 'simple-points-and-rewards' ),
        '🎯' => esc_html__( 'Target', 'simple-points-and-rewards' ),
        '🚀' => esc_html__( 'Rocket', 'simple-points-and-rewards' ),
        '⚡'  => esc_html__( 'Lightning', 'simple-points-and-rewards' ),
        '🔥' => esc_html__( 'Fire', 'simple-points-and-rewards' ),
        '💫' => esc_html__( 'Shooting Star', 'simple-points-and-rewards' ),
        '🌈' => esc_html__( 'Rainbow', 'simple-points-and-rewards' ),
    ];
    /**
     * Filter the available badge icons for levels.
     *
     * @param array $icons Array of emoji/icon => label.
     */
    return apply_filters( 'spar_badge_icons', $icons );
}

/**
 * Get available badge icons for rewards.
 */
function spar_get_reward_badge_icons() {
    $icons = [
        'fa-solid fa-ticket'          => esc_html__( 'Font Awesome - Ticket (Voucher)', 'simple-points-and-rewards' ),
        'fa-solid fa-gift'            => esc_html__( 'Font Awesome - Gift (Product)', 'simple-points-and-rewards' ),
        'fa-solid fa-gifts'           => esc_html__( 'Font Awesome - Gifts (Bundle)', 'simple-points-and-rewards' ),
        'fa-solid fa-gear'            => esc_html__( 'Font Awesome - Gear (Custom)', 'simple-points-and-rewards' ),
        'fa-solid fa-star'            => esc_html__( 'Font Awesome - Star', 'simple-points-and-rewards' ),
        'fa-solid fa-trophy'          => esc_html__( 'Font Awesome - Trophy', 'simple-points-and-rewards' ),
        'fa-solid fa-medal'           => esc_html__( 'Font Awesome - Medal', 'simple-points-and-rewards' ),
        'fa-solid fa-award'           => esc_html__( 'Font Awesome - Award', 'simple-points-and-rewards' ),
        'fa-solid fa-crown'           => esc_html__( 'Font Awesome - Crown', 'simple-points-and-rewards' ),
        'fa-solid fa-gem'             => esc_html__( 'Font Awesome - Gem', 'simple-points-and-rewards' ),
        'fa-solid fa-tag'             => esc_html__( 'Font Awesome - Tag', 'simple-points-and-rewards' ),
        'fa-solid fa-tags'            => esc_html__( 'Font Awesome - Tags', 'simple-points-and-rewards' ),
        'fa-solid fa-percent'         => esc_html__( 'Font Awesome - Percent', 'simple-points-and-rewards' ),
        'fa-solid fa-cart-shopping'   => esc_html__( 'Font Awesome - Shopping Cart', 'simple-points-and-rewards' ),
        'fa-solid fa-bag-shopping'    => esc_html__( 'Font Awesome - Shopping Bag', 'simple-points-and-rewards' ),
        'fa-solid fa-basket-shopping' => esc_html__( 'Font Awesome - Shopping Basket', 'simple-points-and-rewards' ),
        'fa-solid fa-store'           => esc_html__( 'Font Awesome - Store', 'simple-points-and-rewards' ),
        'fa-solid fa-credit-card'     => esc_html__( 'Font Awesome - Credit Card', 'simple-points-and-rewards' ),
        'fa-solid fa-wallet'          => esc_html__( 'Font Awesome - Wallet', 'simple-points-and-rewards' ),
        'fa-solid fa-money-bill'      => esc_html__( 'Font Awesome - Money Bill', 'simple-points-and-rewards' ),
        'fa-solid fa-coins'           => esc_html__( 'Font Awesome - Coins', 'simple-points-and-rewards' ),
        'fa-solid fa-sack-dollar'     => esc_html__( 'Font Awesome - Money Bag', 'simple-points-and-rewards' ),
        'fa-solid fa-piggy-bank'      => esc_html__( 'Font Awesome - Piggy Bank', 'simple-points-and-rewards' ),
        'fa-solid fa-truck-fast'      => esc_html__( 'Font Awesome - Fast Delivery', 'simple-points-and-rewards' ),
        'fa-solid fa-box-open'        => esc_html__( 'Font Awesome - Open Box', 'simple-points-and-rewards' ),
        'fa-solid fa-ribbon'          => esc_html__( 'Font Awesome - Ribbon', 'simple-points-and-rewards' ),
        'fa-solid fa-heart'           => esc_html__( 'Font Awesome - Heart', 'simple-points-and-rewards' ),
        'fa-solid fa-fire'            => esc_html__( 'Font Awesome - Fire', 'simple-points-and-rewards' ),
        'fa-solid fa-bolt'            => esc_html__( 'Font Awesome - Lightning Bolt', 'simple-points-and-rewards' ),
        'fa-solid fa-rocket'          => esc_html__( 'Font Awesome - Rocket', 'simple-points-and-rewards' ),
    ] + [
        '🎟️' => esc_html__( 'Ticket (Voucher)', 'simple-points-and-rewards' ),
        '🎁'    => esc_html__( 'Gift (Product)', 'simple-points-and-rewards' ),
        '⚙️'  => esc_html__( 'Gear (Custom)', 'simple-points-and-rewards' ),
        '🛍️' => esc_html__( 'Shopping Bags', 'simple-points-and-rewards' ),
        '🛒'    => esc_html__( 'Shopping Cart', 'simple-points-and-rewards' ),
        '🏷️' => esc_html__( 'Price Tag', 'simple-points-and-rewards' ),
        '💳'    => esc_html__( 'Credit Card', 'simple-points-and-rewards' ),
        '💰'    => esc_html__( 'Money Bag', 'simple-points-and-rewards' ),
        '💵'    => esc_html__( 'Banknote', 'simple-points-and-rewards' ),
        '🎀'    => esc_html__( 'Ribbon', 'simple-points-and-rewards' ),
        '🎊'    => esc_html__( 'Confetti', 'simple-points-and-rewards' ),
        '🎉'    => esc_html__( 'Party Popper', 'simple-points-and-rewards' ),
        '🎈'    => esc_html__( 'Balloon', 'simple-points-and-rewards' ),
        '💝'    => esc_html__( 'Heart with Ribbon', 'simple-points-and-rewards' ),
        '⭐'     => esc_html__( 'Star', 'simple-points-and-rewards' ),
        '✨'     => esc_html__( 'Sparkles', 'simple-points-and-rewards' ),
        '🏆'    => esc_html__( 'Trophy', 'simple-points-and-rewards' ),
        '🎖️' => esc_html__( 'Medal', 'simple-points-and-rewards' ),
        '👑'    => esc_html__( 'Crown', 'simple-points-and-rewards' ),
        '💎'    => esc_html__( 'Diamond', 'simple-points-and-rewards' ),
        '🔥'    => esc_html__( 'Fire', 'simple-points-and-rewards' ),
        '🚀'    => esc_html__( 'Rocket', 'simple-points-and-rewards' ),
    ];
    /**
     * Filter the available badge icons for rewards.
     *
     * @param array $icons Array of emoji/icon => label.
     */
    return apply_filters( 'spar_reward_badge_icons', $icons );
}

/**
 * Get the default display icon for a reward type.
 *
 * @param string $type Reward type.
 * @return string Default icon.
 */
function spar_get_reward_type_default_icon(  $type = 'voucher'  ) {
    $type = sanitize_key( $type );
    $icons = array(
        'voucher'        => 'fa-solid fa-ticket',
        'product'        => 'fa-solid fa-gift',
        'product_bundle' => 'fa-solid fa-gifts',
        'custom'         => 'fa-solid fa-gear',
    );
    $icon = $icons[$type] ?? $icons['voucher'];
    /**
     * Filter the default display icon for a reward type.
     *
     * @param string $icon  Default icon.
     * @param string $type  Reward type.
     * @param array  $icons Available default icons keyed by reward type.
     */
    return apply_filters(
        'spar_reward_type_default_icon',
        $icon,
        $type,
        $icons
    );
}

/**
 * Get the default display icon for a level badge.
 *
 * @param array $level Level data.
 * @return string Default icon.
 */
function spar_get_level_default_badge_icon(  $level = array()  ) {
    $is_starter = !empty( $level['is_default'] ) || isset( $level['id'] ) && 'level_0' === $level['id'];
    $icon = ( $is_starter ? 'fa-solid fa-star' : 'fa-solid fa-trophy' );
    /**
     * Filter the default display icon for a level badge.
     *
     * @param string $icon  Default icon.
     * @param array  $level Level data.
     */
    return apply_filters( 'spar_level_default_badge_icon', $icon, $level );
}

/**
 * Normalize badge data for a level or starter configuration.
 *
 * @param string $badge_icon Emoji or preset badge icon.
 * @param string $badge_url  Optional custom badge image URL.
 * @param string $badge_type Badge type indicator (preset|custom_url|custom_text).
 * @param string $badge_text Optional custom text badge.
 *
 * @return array Normalized badge data.
 */
function spar_normalize_badge_fields(
    $badge_icon = '',
    $badge_url = '',
    $badge_type = '',
    $badge_text = ''
) {
    $allowed_types = array('preset', 'custom_url', 'custom_text');
    if ( !in_array( $badge_type, $allowed_types, true ) ) {
        $badge_type = '';
    }
    if ( '' === $badge_type ) {
        if ( '' !== $badge_url ) {
            $badge_type = 'custom_url';
        } elseif ( '' !== $badge_text ) {
            $badge_type = 'custom_text';
        } elseif ( '' !== $badge_icon ) {
            $badge_type = 'preset';
        } else {
            $badge_type = 'preset';
        }
    }
    if ( 'custom_url' === $badge_type ) {
        $badge_icon = '';
        $badge_text = '';
    } elseif ( 'custom_text' === $badge_type ) {
        if ( '' === $badge_text && '' !== $badge_icon ) {
            $badge_text = $badge_icon;
        }
        $badge_icon = '';
        $badge_url = '';
    } else {
        $badge_type = 'preset';
        if ( '' === $badge_icon && '' !== $badge_text ) {
            $badge_icon = $badge_text;
        }
        $badge_url = '';
        $badge_text = '';
    }
    return array(
        'badge_type' => $badge_type,
        'badge_icon' => $badge_icon,
        'badge_url'  => $badge_url,
        'badge_text' => $badge_text,
    );
}

/**
 * Render the badge markup for a level based on normalized badge data.
 *
 * @param array $level Level data containing badge fields.
 * @param array $args  Rendering overrides.
 *
 * @return string HTML markup for the badge icon/image.
 */
function spar_get_level_badge_markup(  $level, $args = array()  ) {
    $defaults = array(
        'icon_class'      => 'spar-level-badge-icon',
        'image_class'     => 'spar-level-badge-img',
        'fallback_icon'   => '',
        'icon_color'      => '',
        'icon_color_mode' => '',
    );
    $args = wp_parse_args( $args, $defaults );
    $badge_type = $level['badge_type'] ?? '';
    $badge_color_mode = $level['badge_color_mode'] ?? $args['icon_color_mode'];
    $badge_color_mode = ( in_array( $badge_color_mode, array('default', 'custom'), true ) ? $badge_color_mode : 'default' );
    $badge_icon_color = '';
    $badge_icon_style = '';
    $badge_gradient = '';
    if ( 'custom' === $badge_color_mode ) {
        $badge_icon_color = $level['badge_color'] ?? $args['icon_color'];
        $badge_icon_color = ( is_scalar( $badge_icon_color ) ? sanitize_hex_color( (string) $badge_icon_color ) : '' );
        $badge_icon_color = ( is_string( $badge_icon_color ) ? $badge_icon_color : '' );
        $badge_icon_style = ( '' !== $badge_icon_color ? ' style="color:' . esc_attr( $badge_icon_color ) . ';"' : '' );
    } else {
        $badge_gradient = ' spar-badge-icon--gradient';
    }
    if ( 'custom_url' === $badge_type && !empty( $level['badge_url'] ) ) {
        return '<img src="' . esc_url( $level['badge_url'] ) . '" alt="' . esc_attr( $level['name'] ?? '' ) . '" class="' . esc_attr( $args['image_class'] ) . '" />';
    }
    if ( 'custom_text' === $badge_type && !empty( $level['badge_text'] ) ) {
        return '<span class="' . esc_attr( $args['icon_class'] ) . $badge_gradient . '"' . $badge_icon_style . '>' . esc_html( $level['badge_text'] ) . '</span>';
    }
    if ( !empty( $level['badge_url'] ) ) {
        return '<img src="' . esc_url( $level['badge_url'] ) . '" alt="' . esc_attr( $level['name'] ?? '' ) . '" class="' . esc_attr( $args['image_class'] ) . '" />';
    }
    if ( !empty( $level['badge_icon'] ) ) {
        return spar_get_badge_icon_markup(
            $level['badge_icon'],
            $args['icon_class'],
            $badge_icon_color,
            $badge_color_mode
        );
    }
    if ( !empty( $level['badge_text'] ) ) {
        return '<span class="' . esc_attr( $args['icon_class'] ) . $badge_gradient . '"' . $badge_icon_style . '>' . esc_html( $level['badge_text'] ) . '</span>';
    }
    if ( '' !== $args['fallback_icon'] ) {
        return spar_get_badge_icon_markup(
            $args['fallback_icon'],
            $args['icon_class'],
            $badge_icon_color,
            $badge_color_mode
        );
    }
    return '';
}

/**
 * Get the benefits list shown for the Starter (Level 0) tier.
 *
 * When the "Custom Benefits" field has been filled in, those lines replace the
 * auto-generated defaults entirely so the store owner has full control over the
 * text shown to customers. When left empty, the benefits are derived from the
 * configured earning settings.
 *
 * @param array|null $options Optional plugin options array. Loaded when null.
 * @return array<int, string> List of benefit strings.
 */
function spar_get_starter_level_benefits(  $options = null  ) {
    if ( !is_array( $options ) ) {
        $options = get_option( 'spar_options', [] );
    }
    // Default auto-generated benefits derived from earn settings.
    $earn_options = $options['earn'] ?? [];
    $order_options = $earn_options['order'] ?? [];
    $referral_options = $earn_options['referral'] ?? [];
    $pp_points = ( isset( $order_options['points_per_points'] ) ? (float) $order_options['points_per_points'] : (float) ($order_options['points_per'] ?? 5) );
    $pp_amount = ( isset( $order_options['points_per_amount'] ) && (float) $order_options['points_per_amount'] > 0 ? (float) $order_options['points_per_amount'] : 1.0 );
    $currency_code = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : '$' );
    $referral_points = ( isset( $referral_options['fixed_points'] ) && '' !== (string) $referral_options['fixed_points'] ? $referral_options['fixed_points'] : 100 );
    $benefits = [
        /* translators: 1: points amount, 2: currency symbol, 3: amount */
        sprintf(
            esc_html__( '%1$s points per %2$s%3$s spent', 'simple-points-and-rewards' ),
            spar_format_points_value( (float) $pp_points, ( floor( $pp_points ) == $pp_points ? 0 : 1 ) ),
            $currency_symbol,
            spar_format_currency_amount( $pp_amount )
        ),
        /* translators: %s: points earned per referral */
        sprintf( esc_html__( '%s points for each referral', 'simple-points-and-rewards' ), spar_format_points_value( $referral_points ) ),
    ];
    // Custom benefits are appended to the auto-generated list.
    $custom = ( isset( $options['levels_starter_custom_benefits'] ) ? (string) $options['levels_starter_custom_benefits'] : '' );
    if ( '' !== trim( $custom ) ) {
        foreach ( explode( "\n", trim( $custom ) ) as $benefit_line ) {
            $benefit_line = trim( $benefit_line );
            if ( '' !== $benefit_line ) {
                $benefits[] = $benefit_line;
            }
        }
    }
    /**
     * Filter the benefits list shown for the Starter (Level 0) tier.
     *
     * @param array $benefits List of benefit strings.
     * @param array $options  Plugin options array.
     */
    return apply_filters( 'spar_starter_level_benefits', $benefits, $options );
}

/**
 * Generate a unique level ID
 */
function spar_generate_level_id() {
    return 'level_' . wp_generate_password( 8, false, false );
}

/**
 * Get the WordPress user capability associated with a level.
 *
 * Every level exposes a stable capability derived from its ID. Store owners can
 * grant this capability their own custom permissions (via other plugins or code)
 * to give customers at that level access to specific content. The capability is
 * granted dynamically to the user's current level via the `user_has_cap` filter,
 * so nothing is stored on the user and it always stays in sync as they level up
 * or down.
 *
 * @param array|string $level Level array (as returned by spar_get_all_levels()) or a level ID string.
 * @return string Capability slug (e.g. "spar_level_0"), or an empty string when unavailable.
 */
function spar_get_level_capability(  $level  ) {
    $id = '';
    if ( is_array( $level ) ) {
        $id = ( isset( $level['id'] ) ? (string) $level['id'] : '' );
    } elseif ( is_scalar( $level ) ) {
        $id = (string) $level;
    }
    // sanitize_key() lowercases and strips anything that isn't a-z0-9_- so the
    // capability is always a deterministic, safe slug for the given ID.
    $id = sanitize_key( $id );
    if ( '' === $id ) {
        return '';
    }
    /**
     * Filter the capability slug generated for a level.
     *
     * Note: the dynamic grant fast-path in spar_grant_level_capability() keys off
     * the "spar_level_" prefix, so keep that prefix if you want the automatic
     * granting to continue to fire.
     *
     * @param string       $capability Capability slug (e.g. "spar_level_0").
     * @param string       $id         Sanitized level ID.
     * @param array|string $level      Original level data or ID.
     */
    return apply_filters(
        'spar_level_capability',
        'spar_' . $id,
        $id,
        $level
    );
}

/**
 * Get the capability details for every configured level.
 *
 * Useful for exposing the available capabilities to store owners or for
 * integrating with membership/restriction plugins.
 *
 * @return array<int, array{id:string,name:string,capability:string}>
 */
function spar_get_all_level_capabilities() {
    $capabilities = array();
    foreach ( spar_get_all_levels() as $level ) {
        $capability = spar_get_level_capability( $level );
        if ( '' === $capability ) {
            continue;
        }
        $capabilities[] = array(
            'id'         => ( isset( $level['id'] ) ? (string) $level['id'] : '' ),
            'name'       => ( isset( $level['name'] ) ? (string) $level['name'] : '' ),
            'capability' => $capability,
        );
    }
    return $capabilities;
}

/**
 * Dynamically grant a user the capability for their current level.
 *
 * Hooks into `user_has_cap` so `current_user_can( 'spar_level_<id>' )` (and any
 * membership/restriction plugin that relies on it) returns true for customers
 * whose current level matches. The capability is granted for the user's exact
 * current level only. Nothing is persisted, so the grant follows level changes
 * automatically.
 *
 * @param array   $allcaps All capabilities currently mapped to the user.
 * @param array   $caps    Primitive capabilities required for the check.
 * @param array   $args    Arguments passed to the capability check.
 * @param WP_User $user    The user object being checked.
 * @return array Filtered capabilities.
 */
function spar_grant_level_capability(
    $allcaps,
    $caps,
    $args,
    $user
) {
    // Re-entrancy guard: resolving the level may itself trigger capability
    // checks (e.g. via Freemius), so never recurse into ourselves.
    static $in_progress = false;
    // Fast path: only do work when a level capability is actually being checked.
    $wants_level_cap = false;
    foreach ( (array) $caps as $cap ) {
        if ( is_string( $cap ) && 0 === strpos( $cap, 'spar_level_' ) ) {
            $wants_level_cap = true;
            break;
        }
    }
    if ( !$wants_level_cap || $in_progress ) {
        return $allcaps;
    }
    if ( !$user instanceof WP_User || empty( $user->ID ) ) {
        return $allcaps;
    }
    // Cache the resolved capability per user for the duration of the request;
    // user_has_cap can fire many times per page load.
    static $cache = array();
    $user_id = (int) $user->ID;
    if ( !array_key_exists( $user_id, $cache ) ) {
        $in_progress = true;
        $level_capability = '';
        if ( function_exists( 'spar_get_user_level' ) ) {
            $level = spar_get_user_level( $user_id );
            if ( is_array( $level ) ) {
                $level_capability = spar_get_level_capability( $level );
            }
        }
        $cache[$user_id] = $level_capability;
        $in_progress = false;
    }
    if ( '' !== $cache[$user_id] ) {
        $allcaps[$cache[$user_id]] = true;
    }
    return $allcaps;
}

add_filter(
    'user_has_cap',
    'spar_grant_level_capability',
    10,
    4
);
/**
 * Get all configured levels including Level 0 if enabled
 */
function spar_get_all_levels() {
    $options = get_option( 'spar_options', [] );
    $levels_enabled = !empty( $options['levels_enabled'] );
    $configured_levels = $options['levels'] ?? [];
    if ( !$levels_enabled ) {
        return [];
    }
    $all_levels = [];
    // Always add Level 0 (Starter) when levels are enabled
    $earn_options = $options['earn'] ?? [];
    $order_options = $earn_options['order'] ?? [];
    $referral_options = $earn_options['referral'] ?? [];
    $starter_name = $options['levels_starter_name'] ?? esc_html__( 'Starter', 'simple-points-and-rewards' );
    $starter_badge_data = spar_normalize_badge_fields(
        $options['levels_starter_badge_icon'] ?? spar_get_level_default_badge_icon( array(
            'id'         => 'level_0',
            'is_default' => true,
        ) ),
        $options['levels_starter_badge_url'] ?? '',
        $options['levels_starter_badge_type'] ?? '',
        $options['levels_starter_badge_text'] ?? ''
    );
    // Derive base earning phrasing from split fields
    $pp_points = ( isset( $order_options['points_per_points'] ) ? (float) $order_options['points_per_points'] : (float) ($order_options['points_per'] ?? 5) );
    $pp_amount = ( isset( $order_options['points_per_amount'] ) && (float) $order_options['points_per_amount'] > 0 ? (float) $order_options['points_per_amount'] : 1.0 );
    $currency_code = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : '$' );
    $all_levels[] = [
        'id'                     => 'level_0',
        'name'                   => $starter_name,
        'required_points'        => 0,
        'badge_type'             => $starter_badge_data['badge_type'],
        'badge_icon'             => $starter_badge_data['badge_icon'],
        'badge_url'              => $starter_badge_data['badge_url'],
        'badge_text'             => $starter_badge_data['badge_text'],
        'badge_color'            => ( isset( $options['levels_starter_badge_color'] ) ? (string) $options['levels_starter_badge_color'] : '' ),
        'badge_color_mode'       => ( isset( $options['levels_starter_badge_color_mode'] ) ? (string) $options['levels_starter_badge_color_mode'] : 'default' ),
        'points_multiplier'      => 1.0,
        'is_default'             => true,
        'level_up_email_enabled' => false,
        'level_up_email_subject' => '',
        'level_up_email_body'    => '',
        'description'            => sprintf(
            /* translators: 1: points amount, 2: currency symbol, 3: amount */
            esc_html__( '%1$s points per %2$s%3$s spent', 'simple-points-and-rewards' ),
            spar_format_points_value( (float) $pp_points, ( floor( $pp_points ) == $pp_points ? 0 : 1 ) ),
            $currency_symbol,
            spar_format_currency_amount( $pp_amount )
        ),
        'benefits'               => spar_get_starter_level_benefits( $options ),
    ];
    // Add configured levels
    foreach ( $configured_levels as $level ) {
        // Skip incomplete levels (drafts)
        if ( empty( $level['name'] ) || (int) ($level['required_points'] ?? 0) <= 0 ) {
            continue;
        }
        // Ensure points_multiplier is properly set
        if ( !isset( $level['points_multiplier'] ) ) {
            $level['points_multiplier'] = 1.0;
        }
        $badge_data = spar_normalize_badge_fields(
            $level['badge_icon'] ?? '',
            $level['badge_url'] ?? '',
            $level['badge_type'] ?? '',
            $level['badge_text'] ?? ''
        );
        $level = array_merge( $level, $badge_data );
        $level['level_up_email_enabled'] = !empty( $level['level_up_email_enabled'] );
        $level['level_up_email_subject'] = ( isset( $level['level_up_email_subject'] ) ? (string) $level['level_up_email_subject'] : '' );
        $level['level_up_email_body'] = ( isset( $level['level_up_email_body'] ) ? (string) $level['level_up_email_body'] : '' );
        $level['is_default'] = false;
        $level['description'] = sprintf( 
            /* translators: %s: points multiplier (e.g., 1.5) */
            esc_html__( '%sx points multiplier', 'simple-points-and-rewards' ),
            spar_format_multiplier( $level['points_multiplier'] )
         );
        // Add all benefits for this level.
        $benefits = [];
        $benefits[] = sprintf( 
            /* translators: %s: points multiplier (e.g., 1.5) */
            esc_html__( '%sx points multiplier', 'simple-points-and-rewards' ),
            spar_format_multiplier( $level['points_multiplier'] )
         );
        // Add custom benefits if any exist
        if ( !empty( $level['custom_benefits'] ) ) {
            $custom_benefits_lines = explode( "\n", trim( $level['custom_benefits'] ) );
            foreach ( $custom_benefits_lines as $benefit_line ) {
                $benefit_line = trim( $benefit_line );
                if ( !empty( $benefit_line ) ) {
                    $benefits[] = $benefit_line;
                }
            }
        }
        $level['benefits'] = $benefits;
        $all_levels[] = $level;
    }
    // Sort by required points
    usort( $all_levels, function ( $a, $b ) {
        return $a['required_points'] - $b['required_points'];
    } );
    /**
     * Filter the array of all levels prior to use.
     *
     * @param array $all_levels Assembled levels including Level 0.
     * @param array $options    Plugin options array.
     */
    return apply_filters( 'spar_all_levels', $all_levels, $options );
}

/**
 * Get user's total points earned (lifetime, not current balance).
 *
 * Source of truth is user meta ('_spar_total_earned_points').
 * If missing, lazily backfill from the activity log (sum of 'add' events),
 * save to meta, and return the value.
 */
function spar_get_user_total_points_earned(  $user_id  ) {
    $user_id = absint( $user_id );
    if ( !$user_id ) {
        return 0;
    }
    // Read from user meta first
    $meta_key = '_spar_total_earned_points';
    $total_earned = get_user_meta( $user_id, $meta_key, true );
    // If not set, backfill from logs once and persist
    if ( '' === $total_earned || null === $total_earned ) {
        global $wpdb;
        $table = $wpdb->prefix . 'spar_points_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // Sum genuine earnings only. Absolute admin balance overrides
        // ('admin_set_balance') log an 'add' row but are flagged skip_lifetime,
        // so they must not inflate the reconstructed lifetime total. Legacy rows
        // with a NULL action_id are still counted.
        $computed = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM %i WHERE user_id = %d AND type = %s AND ( action_id IS NULL OR action_id != %s )",
            $table,
            $user_id,
            'add',
            'admin_set_balance'
        ) );
        $computed = absint( $computed );
        update_user_meta( $user_id, $meta_key, $computed );
        return $computed;
    }
    return absint( $total_earned );
}

/**
 * Get the points value used for level calculations.
 *
 * Returns either the total lifetime points earned or the current available
 * balance depending on the `levels_points_type` setting.
 *
 * @param int $user_id User ID.
 * @return int Points value used for level requirements.
 */
function spar_get_user_level_points(  $user_id  ) {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $type = ( isset( $options['levels_points_type'] ) ? $options['levels_points_type'] : 'total_earned' );
    if ( 'available_balance' === $type ) {
        return spar_get_user_points( $user_id );
    }
    return spar_get_user_total_points_earned( $user_id );
}

/**
 * Get user's current level (based on level points type setting)
 */
function spar_get_user_level(  $user_id  ) {
    $total_points_earned = spar_get_user_level_points( $user_id );
    $all_levels = spar_get_all_levels();
    if ( empty( $all_levels ) ) {
        return null;
    }
    $current_level = $all_levels[0];
    // Start with lowest level
    $count = 0;
    foreach ( $all_levels as $level ) {
        $count++;
        if ( $count > 2 ) {
            break;
        }
        if ( $total_points_earned >= $level['required_points'] ) {
            $current_level = $level;
        } else {
            break;
        }
    }
    /**
     * Filter the resolved current level for a user.
     *
     * @param array|null $current_level Current level array or null.
     * @param int        $user_id       User ID.
     * @param array      $all_levels    All levels array.
     */
    return apply_filters(
        'spar_user_level',
        $current_level,
        (int) $user_id,
        $all_levels
    );
}

/**
 * Get user's next level (based on level points type setting)
 */
function spar_get_user_next_level(  $user_id  ) {
    $total_points_earned = spar_get_user_level_points( $user_id );
    $all_levels = spar_get_all_levels();
    if ( empty( $all_levels ) ) {
        return null;
    }
    $count = 0;
    foreach ( $all_levels as $level ) {
        $count++;
        if ( $count > 2 ) {
            break;
        }
        if ( $total_points_earned < $level['required_points'] ) {
            return $level;
        }
    }
    $next = null;
    // User is at max level
    /**
     * Filter the next level for a user (or null if max level).
     *
     * @param array|null $next       Next level array or null.
     * @param int        $user_id    User ID.
     * @param array      $all_levels All levels array.
     */
    return apply_filters(
        'spar_user_next_level',
        $next,
        (int) $user_id,
        $all_levels
    );
}

/**
 * Get level progress percentage (based on level points type setting)
 */
function spar_get_level_progress(  $user_id  ) {
    $current_level = spar_get_user_level( $user_id );
    $next_level = spar_get_user_next_level( $user_id );
    $total_points_earned = spar_get_user_level_points( $user_id );
    if ( !$current_level || !$next_level ) {
        return 100;
        // Max level reached
    }
    $current_required = $current_level['required_points'];
    $next_required = $next_level['required_points'];
    $points_in_level = $total_points_earned - $current_required;
    $points_needed_for_next = $next_required - $current_required;
    if ( $points_needed_for_next <= 0 ) {
        return 100;
    }
    $progress = min( 100, $points_in_level / $points_needed_for_next * 100 );
    /**
     * Filter the computed level progress percentage for a user.
     *
     * @param float     $progress     Percentage 0-100.
     * @param int       $user_id      User ID.
     * @param array     $current_level Current level array.
     * @param array     $next_level    Next level array.
     */
    return (float) apply_filters(
        'spar_level_progress',
        $progress,
        (int) $user_id,
        $current_level,
        $next_level
    );
}

/**
 * Display user level badge
 */
function spar_display_user_level_badge(  $user_id, $show_name = true  ) {
    $level = spar_get_user_level( $user_id );
    if ( !$level ) {
        return '';
    }
    $normalized = spar_normalize_badge_fields(
        $level['badge_icon'] ?? '',
        $level['badge_url'] ?? '',
        $level['badge_type'] ?? '',
        $level['badge_text'] ?? ''
    );
    $level = array_merge( $level, $normalized );
    $badge_markup = spar_get_level_badge_markup( $level );
    if ( '' === $badge_markup ) {
        if ( !$show_name ) {
            return '';
        }
        return '<span class="spar-user-level-badge"><span class="spar-level-name">' . esc_html( $level['name'] ?? '' ) . '</span></span>';
    }
    if ( $show_name && !empty( $level['name'] ) ) {
        $badge_markup .= ' <span class="spar-level-name">' . esc_html( $level['name'] ) . '</span>';
    }
    return '<span class="spar-user-level-badge">' . $badge_markup . '</span>';
}

/**
 * Display level progress bar (based on level points type setting)
 */
function spar_display_level_progress_bar(  $user_id  ) {
    $current_level = spar_get_user_level( $user_id );
    $next_level = spar_get_user_next_level( $user_id );
    $progress = spar_get_level_progress( $user_id );
    $total_points_earned = spar_get_user_level_points( $user_id );
    if ( !$current_level ) {
        return '';
    }
    $html = '<div class="spar-level-progress-container">';
    if ( $next_level ) {
        // Progress bar
        $points_needed = $next_level['required_points'] - $total_points_earned;
        $html .= '<div class="spar-level-progress-bar">';
        $html .= '<div class="spar-level-progress-fill" style="width: ' . esc_attr( $progress ) . '%;"></div>';
        $html .= '</div>';
        // Next level info
        $html .= '<div class="spar-level-next">';
        $html .= '<span>' . sprintf( 
            /* translators: %s: next level name */
            esc_html__( 'Next: %1$s', 'simple-points-and-rewards' ),
            esc_html( $next_level['name'] )
         ) . '</span>';
        $html .= '<span>' . sprintf( 
            /* translators: %s: points needed to reach next level */
            esc_html__( '%1$s points needed', 'simple-points-and-rewards' ),
            spar_format_points_value( (int) $points_needed )
         ) . '</span>';
        $html .= '</div>';
    } else {
        $html .= '<div class="spar-level-max">';
        $html .= '🎉 ' . esc_html__( 'Maximum level reached!', 'simple-points-and-rewards' );
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Get the earn type labels used for per-earn-type multiplier settings.
 *
 * @return array Associative array of earn_type_key => label.
 */
function spar_get_earn_type_labels() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $earn = $options['earn'] ?? [];
    $labels = [
        'order'        => $earn['order']['name'] ?? esc_html__( 'Place an Order', 'simple-points-and-rewards' ),
        'order_fixed'  => $earn['order_fixed']['name'] ?? esc_html__( 'Points for Orders', 'simple-points-and-rewards' ),
        'signup'       => $earn['signup']['name'] ?? esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
        'referral'     => $earn['referral']['name'] ?? esc_html__( 'Referral Bonus', 'simple-points-and-rewards' ),
        'review'       => $earn['review']['name'] ?? esc_html__( 'Write a Review', 'simple-points-and-rewards' ),
        'birthday'     => $earn['birthday']['name'] ?? esc_html__( 'Birthday Bonus', 'simple-points-and-rewards' ),
        'daily_login'  => $earn['daily_login']['name'] ?? esc_html__( 'Daily Login Bonus', 'simple-points-and-rewards' ),
        'first_order'  => $earn['first_order']['name'] ?? esc_html__( 'First Order Bonus', 'simple-points-and-rewards' ),
        'nth_order'    => $earn['nth_order']['name'] ?? esc_html__( 'Bonus after X Orders', 'simple-points-and-rewards' ),
        'buy_products' => ( isset( $earn['buy_products']['name'] ) && '' !== $earn['buy_products']['name'] ? $earn['buy_products']['name'] : esc_html__( 'Product Offers & Bonuses', 'simple-points-and-rewards' ) ),
        'spin_wheel'   => ( isset( $earn['spin_wheel']['name'] ) && '' !== $earn['spin_wheel']['name'] ? $earn['spin_wheel']['name'] : esc_html__( 'Daily Prize Wheel', 'simple-points-and-rewards' ) ),
        'social_share' => esc_html__( 'Social Sharing', 'simple-points-and-rewards' ),
    ];
    // Include developer-registered custom ways to earn (PRO) so each can have its own multiplier.
    if ( function_exists( 'sparp_cwe_get_ways__premium_only' ) ) {
        foreach ( sparp_cwe_get_ways__premium_only() as $custom_way ) {
            if ( empty( $custom_way['id'] ) ) {
                continue;
            }
            $labels[$custom_way['id']] = ( isset( $custom_way['name'] ) && '' !== $custom_way['name'] ? $custom_way['name'] : $custom_way['id'] );
        }
    }
    return apply_filters( 'spar_earn_type_labels', $labels );
}

/**
 * Get points multiplier for user's current level.
 *
 * @param int    $user_id   User ID.
 * @param string $earn_type Optional earn type key (e.g. 'order', 'signup'). When provided and
 *                          the level has custom per-earn-type multipliers, returns the specific
 *                          multiplier for that type.
 * @return float Multiplier value.
 */
function spar_get_user_points_multiplier(  $user_id, $earn_type = ''  ) {
    $level = spar_get_user_level( $user_id );
    if ( !$level ) {
        return 1.0;
    }
    $multiplier = floatval( $level['points_multiplier'] ?? 1.0 );
    $multiplier = max( 0.0, $multiplier );
    // Check for per-earn-type override (PRO only).
    if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
        if ( '' !== $earn_type && !empty( $level['custom_multipliers_enabled'] ) && !empty( $level['earn_type_multipliers'] ) && is_array( $level['earn_type_multipliers'] ) ) {
            if ( isset( $level['earn_type_multipliers'][$earn_type] ) && '' !== $level['earn_type_multipliers'][$earn_type] ) {
                $multiplier = max( 0.0, floatval( $level['earn_type_multipliers'][$earn_type] ) );
            }
        }
    }
    /**
     * Filter the points multiplier for a user's current level.
     *
     * @param float  $multiplier Multiplier (>=0.0).
     * @param int    $user_id    User ID.
     * @param array  $level      Current level array.
     * @param string $earn_type  Earn type key or empty string.
     */
    return (float) apply_filters(
        'spar_user_points_multiplier',
        $multiplier,
        (int) $user_id,
        $level,
        $earn_type
    );
}

/**
 * Apply the user's current level multiplier to a base points amount for a given earn type.
 *
 * Bonus earn types (signup, review, birthday, daily login, etc.) award points by calling
 * spar_update_user_points() directly, which does not apply any multiplier. This helper mirrors
 * how order and product-bonus earning apply the level multiplier at the award call site, so those
 * bonuses also honour the level (and per-earn-type) multiplier.
 *
 * @param int    $user_id     User earning the points.
 * @param int    $base_points Base points before the multiplier.
 * @param string $earn_type   Earn type key (e.g. 'daily_login', 'birthday').
 * @return int Points after applying the level multiplier.
 */
function spar_apply_level_multiplier(  $user_id, $base_points, $earn_type = ''  ) {
    $base_points = (int) $base_points;
    if ( $base_points <= 0 ) {
        return $base_points;
    }
    $multiplier = 1.0;
    if ( function_exists( 'spar_get_user_points_multiplier' ) ) {
        $multiplier = max( 0.0, (float) spar_get_user_points_multiplier( (int) $user_id, (string) $earn_type ) );
    }
    return ( function_exists( 'spar_round_points' ) ? (int) spar_round_points( (float) $base_points * $multiplier ) : (int) round( (float) $base_points * $multiplier ) );
}

/**
 * Get referral points bonus for user's current level (deprecated - use multiplier instead)
 */
function spar_get_user_referral_bonus(  $user_id  ) {
    // This function is kept for backward compatibility but now returns 0
    // Use spar_get_user_points_multiplier() instead
    return 0;
}
