<?php
/**
 * 前端浮動聯絡按鈕輸出
 *
 * 安全設計原則：
 * - 每個 App 都是純 <a href> 錨點 — 只有使用者「點擊」才會開啟。
 * - 不使用 iframe、不使用 JS 導向、不預載任何外部資源
 *   （NinjaTeam 以 iframe 載入 line.me 造成 iOS/Apple 裝置
 *   一進頁就跳「要開啟 LINE 嗎？」，本外掛從結構上根絕）。
 *
 * @package WUTM\ContactWidgets\Frontend
 * @since   1.0.0
 */

namespace WUTM\ContactWidgets\Frontend;

use WUTM\ContactWidgets\YSChatApps;
use WUTM\ContactWidgets\YSChatWidgets;

defined( 'ABSPATH' ) || exit;

class YSChatFrontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ $this, 'render_widget' ] );
    }

    /**
     * 是否應該輸出（啟用 + 裝置 + 頁面條件）
     */
    private function should_render( array $settings ): bool {
        if ( empty( $settings['enabled'] ) ) {
            return false;
        }
        if ( empty( $settings['apps'] ) ) {
            return false;
        }

        $is_mobile = wp_is_mobile();
        if ( $is_mobile && empty( $settings['show_mobile'] ) ) {
            return false;
        }
        if ( ! $is_mobile && empty( $settings['show_desktop'] ) ) {
            return false;
        }

        // 頁面條件。
        $display = $settings['display'] ?? 'all';
        if ( 'all' === $display ) {
            return true;
        }

        $current_id = $this->current_page_id();

        if ( 'include' === $display ) {
            return in_array( $current_id, array_map( 'intval', (array) $settings['include_pages'] ), true );
        }
        if ( 'exclude' === $display ) {
            return ! in_array( $current_id, array_map( 'intval', (array) $settings['exclude_pages'] ), true );
        }

        return true;
    }

    /**
     * 目前頁面 ID（含文章頁 / WooCommerce 商店頁對應）
     */
    private function current_page_id(): int {
        $id = (int) get_queried_object_id();

        if ( ! is_front_page() && is_home() ) {
            $id = (int) get_option( 'page_for_posts' );
        }
        if ( function_exists( 'is_shop' ) && is_shop() ) {
            $id = (int) get_option( 'woocommerce_shop_page_id' );
        }

        return $id;
    }

    /**
     * 載入前端資源（僅在需要輸出時）
     */
    public function enqueue_assets(): void {
        $settings = YSChatWidgets::get_settings();
        if ( ! $this->should_render( $settings ) ) {
            return;
        }

        wp_enqueue_style(
            'wu-contact-widgets',
            WUTM_CONTACT_WIDGETS_PLUGIN_URL . 'assets/css/ys-chat-frontend.css',
            [],
            WUTM_CONTACT_WIDGETS_VERSION
        );

        $deps = [];

        // popup（QR 卡片）模式才載入本地 QR 生成器（MIT — Kazuhiko Arase）。
        if ( 'popup' === ( $settings['mode'] ?? 'redirect' ) ) {
            wp_enqueue_script(
                'ys-chat-qrcode',
                WUTM_CONTACT_WIDGETS_PLUGIN_URL . 'assets/js/vendor/qrcode-generator.js',
                [],
                WUTM_CONTACT_WIDGETS_VERSION,
                [ 'in_footer' => true, 'strategy' => 'defer' ]
            );
            $deps[] = 'ys-chat-qrcode';
        }

        wp_enqueue_script(
            'wu-contact-widgets',
            WUTM_CONTACT_WIDGETS_PLUGIN_URL . 'assets/js/ys-chat-frontend.js',
            $deps,
            WUTM_CONTACT_WIDGETS_VERSION,
            [ 'in_footer' => true, 'strategy' => 'defer' ]
        );

        wp_localize_script( 'wu-contact-widgets', 'ysChatWidgets', [
            'mode' => ( 'popup' === ( $settings['mode'] ?? 'redirect' ) ) ? 'popup' : 'redirect',
            'i18n' => [
                'scanQr'   => __( '用手機掃描 QR Code', 'wu-contact-widgets' ),
                'openLink' => __( '直接開啟', 'wu-contact-widgets' ),
                'close'    => __( '關閉', 'wu-contact-widgets' ),
            ],
        ] );
    }

    /**
     * 組出 inline 樣式字串；$force=true 時每條宣告加上 !important。
     *
     * @param array<string,string> $decls 屬性 => 值
     */
    private static function inline_style( array $decls, bool $force ): string {
        $imp = $force ? '!important' : '';
        $out = '';
        foreach ( $decls as $prop => $val ) {
            $out .= $prop . ':' . $val . $imp . ';';
        }
        return $out;
    }

    /**
     * 輸出浮動按鈕 HTML
     */
    public function render_widget(): void {
        $settings = YSChatWidgets::get_settings();
        if ( ! $this->should_render( $settings ) ) {
            return;
        }

        $position = (string) ( $settings['position'] ?? 'right' );
        if ( ! in_array( $position, [ 'left', 'center', 'right' ], true ) ) {
            $position = 'right';
        }
        $bottom   = max( 0, (int) ( $settings['bottom'] ?? 30 ) );
        $side     = max( 0, (int) ( $settings['side'] ?? 20 ) );
        // 外層（主按鈕）與內層（App 圖示）大小；SVG 依比例縮放。
        $size_outer = min( 96, max( 36, (int) ( $settings['size_outer'] ?? 56 ) ) );
        $size_inner = min( 80, max( 28, (int) ( $settings['size_inner'] ?? 46 ) ) );
        $svg_outer  = max( 12, (int) round( $size_outer * 0.46 ) );
        $svg_inner  = max( 10, (int) round( $size_inner * 0.52 ) );
        $color    = (string) ( $settings['button_color'] ?? '#8fa8b8' );
        if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
            $color = '#8fa8b8';
        }
        $btn_icon = (string) ( $settings['button_icon'] ?? '' );
        $tooltip  = (string) ( $settings['tooltip'] ?? 'appname' );
        $defs     = YSChatApps::all();
        $toggle_fg = '#ffffff'; // Keep the default button icon white for consistent contrast.

        // 相容模式：auto（偵測到被主題破壞才由 JS 強制，防呆預設）／force（一律 !important）／off（不強制）。
        $style_mode = (string) ( $settings['style_mode'] ?? 'auto' );
        if ( ! in_array( $style_mode, [ 'auto', 'force', 'off' ], true ) ) {
            $style_mode = 'auto';
        }
        // 只有 force 模式在 server 端就加 !important；auto 交給 JS 偵測後補上（省去友善主題的多餘 !important）。
        $force = ( 'force' === $style_mode );

        // Wrap 定位一律 inline（主題極少覆蓋 fixed 的自訂 ID，故不用 !important）：含 center 對齊。
        $wrap_pos  = 'position:fixed;z-index:99998;display:flex;flex-direction:column;gap:12px;bottom:' . $bottom . 'px;';
        if ( 'center' === $position ) {
            $wrap_pos .= 'left:50%;transform:translateX(-50%);align-items:center;';
        } elseif ( 'left' === $position ) {
            $wrap_pos .= 'left:' . $side . 'px;align-items:flex-start;';
        } else {
            $wrap_pos .= 'right:' . $side . 'px;align-items:flex-end;';
        }
        $style = sprintf(
            '--ysch-bottom:%dpx;--ysch-side:%dpx;--ysch-color:%s;%s',
            $bottom,
            $side,
            $color,
            $wrap_pos
        );

        // 關鍵樣式一律 inline（優先級高於主題 class）；force 模式再加 !important（連 stylesheet 的 !important 都壓得過）。
        $toggle_inline = self::inline_style( [
            'position'         => 'relative',
            'box-sizing'       => 'border-box',
            'display'          => 'flex',
            'align-items'      => 'center',
            'justify-content'  => 'center',
            'width'            => $size_outer . 'px',
            'height'           => $size_outer . 'px',
            'min-width'        => $size_outer . 'px',
            'padding'          => '0',
            'margin'           => '0',
            'border'           => 'none',
            'border-radius'    => '50%',
            'background'       => $color,
            'color'            => $toggle_fg,
            'cursor'           => 'pointer',
            'line-height'      => '0',
            'box-shadow'       => '0 4px 14px rgba(0,0,0,0.22)',
            '-webkit-appearance' => 'none',
            'appearance'       => 'none',
        ], $force );
        $swap_inline = self::inline_style( [
            'position'        => 'absolute',
            'inset'           => '0',
            'display'         => 'flex',
            'align-items'     => 'center',
            'justify-content' => 'center',
            'margin'          => '0',
        ], $force );
        ?>
<div id="wu-contact-widgets" class="ysch-wrap ysch-pos-<?php echo esc_attr( $position ); ?>" data-mode="<?php echo esc_attr( ( 'popup' === ( $settings['mode'] ?? 'redirect' ) ) ? 'popup' : 'redirect' ); ?>" data-stylemode="<?php echo esc_attr( $style_mode ); ?>" data-color="<?php echo esc_attr( $color ); ?>" data-fg="<?php echo esc_attr( $toggle_fg ); ?>" data-outer="<?php echo esc_attr( (string) $size_outer ); ?>" data-inner="<?php echo esc_attr( (string) $size_inner ); ?>" style="<?php echo esc_attr( $style ); ?>">
    <ul class="ysch-items" role="list">
        <?php
        foreach ( (array) $settings['apps'] as $app ) {
            if ( empty( $app['key'] ) || ! isset( $app['value'] ) || '' === trim( (string) $app['value'] ) ) {
                continue;
            }
            $key = (string) $app['key'];
            $def = $defs[ $key ] ?? $defs['custom'];
            $url = YSChatApps::build_url( $key, (string) $app['value'] );
            if ( '' === $url ) {
                continue;
            }

            $label = ! empty( $app['title'] ) ? (string) $app['title'] : (string) $def['title'];
            if ( 'content' === $tooltip ) {
                $label = (string) $app['value'];
            }
            $show_label  = ( 'none' !== $tooltip );
            $custom_icon = ! empty( $app['icon'] ) ? esc_url_raw( (string) $app['icon'] ) : '';

            // tel:/mailto: 等本地 scheme 不需要新分頁。
            $is_external = (bool) preg_match( '#^https?://#i', $url );
            ?>
        <li class="ysch-item-row">
            <a class="ysch-item ysch-app-<?php echo esc_attr( $key ); ?>"
               href="<?php echo esc_url( $url ); ?>"
               <?php echo $is_external ? 'target="_blank" rel="noopener nofollow"' : ''; ?>
               data-app="<?php echo esc_attr( $key ); ?>"
               data-applabel="<?php echo esc_attr( ! empty( $app['title'] ) ? (string) $app['title'] : (string) $def['title'] ); ?>"
               data-appvalue="<?php echo esc_attr( (string) $app['value'] ); ?>"
               data-appcolor="<?php echo esc_attr( $def['color'] ); ?>"
               data-appfg="<?php echo esc_attr( $def['icon_fg'] ); ?>"
               style="--ysch-app-color:<?php echo esc_attr( $def['color'] ); ?>;--ysch-app-fg:<?php echo esc_attr( $def['icon_fg'] ); ?>;text-decoration:none;">
                <?php
                $icon_inline = self::inline_style( [
                    'box-sizing'      => 'border-box',
                    'display'         => 'flex',
                    'align-items'     => 'center',
                    'justify-content' => 'center',
                    'width'           => $size_inner . 'px',
                    'height'          => $size_inner . 'px',
                    'min-width'       => $size_inner . 'px',
                    'border-radius'   => '50%',
                    'background'      => $def['color'],
                    'color'           => $def['icon_fg'],
                    'flex'            => '0 0 auto',
                    'line-height'     => '0',
                ], $force );
                ?>
                <span class="ysch-icon" aria-hidden="true" style="<?php echo esc_attr( $icon_inline ); ?>"><?php
                if ( $custom_icon ) {
                    $ci_inline = self::inline_style( [ 'width' => '62%', 'height' => '62%', 'object-fit' => 'contain', 'display' => 'block' ], $force );
                    printf( '<img src="%s" alt="" loading="lazy" style="%s" />', esc_url( $custom_icon ), esc_attr( $ci_inline ) );
                } else {
                    echo YSChatApps::icon( $key, $svg_inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?></span>
                <?php if ( $show_label ) : ?>
                <span class="ysch-label"><?php echo esc_html( $label ); ?></span>
                <?php endif; ?>
            </a>
        </li>
            <?php
        }
        ?>
    </ul>
    <button type="button" class="ysch-toggle" aria-expanded="false" aria-controls="wu-contact-widgets"
            style="<?php echo esc_attr( $toggle_inline ); ?>"
            aria-label="<?php echo esc_attr__( '開啟聯絡選單', 'wu-contact-widgets' ); ?>">
        <?php if ( $btn_icon ) : ?>
            <?php
            $icon_style = ( 'cover' === ( $settings['icon_style'] ?? 'contain' ) ) ? 'cover' : 'contain';
            $img_inline = ( 'cover' === $icon_style )
                ? self::inline_style( [ 'width' => '100%', 'height' => '100%', 'border-radius' => '50%', 'object-fit' => 'cover', 'display' => 'block' ], $force )
                : self::inline_style( [ 'width' => '60%', 'height' => '60%', 'border-radius' => '0', 'object-fit' => 'contain', 'display' => 'block' ], $force );
            ?>
            <span class="ysch-toggle-open ysch-toggle-img ysch-icon-<?php echo esc_attr( $icon_style ); ?>" style="<?php echo esc_attr( $swap_inline ); ?>"><img src="<?php echo esc_url( $btn_icon ); ?>" alt="" loading="lazy" style="<?php echo esc_attr( $img_inline ); ?>" /></span>
        <?php else : ?>
            <span class="ysch-toggle-open" style="<?php echo esc_attr( $swap_inline ); ?>"><?php echo YSChatApps::toggle_icon( $svg_outer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <?php endif; ?>
        <span class="ysch-toggle-close" style="<?php echo esc_attr( $swap_inline ); ?>opacity:0;"><?php echo YSChatApps::close_icon( $svg_outer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
    </button>
</div>
        <?php
    }
}
