<?php
/**
 * Module: wc-coming-soon-customizer
 * Customizes WooCommerce's native coming-soon page when store-only visibility is enabled.
 */

defined( 'ABSPATH' ) || exit;

final class WUTM_WC_Coming_Soon_Customizer {
    private const TAB_ID = 'site-visibility';
    private const MARKER_ID = 'wutm_cs_section_title';
    private const LEGACY_OPTION = 'wutm_wc_coming_soon_customizer';
    private const OPT_ENABLED = 'wutm_cs_enabled';
    private const OPT_TITLE = 'wutm_cs_custom_title';
    private const OPT_MESSAGE = 'wutm_cs_custom_message';
    private const OPT_STYLE_ENABLED = 'wutm_cs_style_enabled';
    private const OPT_BG_COLOR = 'wutm_cs_bg_color';
    private const OPT_TEXT_COLOR = 'wutm_cs_text_color';
    private const OPT_ACCENT_COLOR = 'wutm_cs_accent_color';
    private const OPT_CARD_WIDTH = 'wutm_cs_card_width';
    private const OPT_RADIUS = 'wutm_cs_radius';
    private const HOME_PRODUCTS_SHORTCODES = [ 'wutm_home_products', 'custom_home_products' ];
    private $settings_cache = null;
    private $legacy_cache = null;

    public function __construct() {
        add_filter( 'woocommerce_get_settings_' . self::TAB_ID, [ $this, 'inject_settings_fields' ], 20, 2 );
        add_action( 'woocommerce_settings_save_' . self::TAB_ID, [ $this, 'after_settings_saved' ], 90 );
        add_action( 'woocommerce_admin_field_wutm_cs_notice', [ $this, 'render_notice_field' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'wp_loaded', [ $this, 'register_frontend_hooks' ], 999 );
    }

    private function defaults(): array { return [ 'enabled' => 1, 'title' => 'Coming Soon', 'message' => 'We are preparing our store and will launch soon.', 'style_enabled' => 0, 'bg_color' => '#ffffff', 'text_color' => '#12233f', 'accent_color' => '#2a63c9', 'card_width' => '640px', 'radius' => '18' ]; }
    private function legacy_settings(): array { if ( null === $this->legacy_cache ) { $value = get_option( self::LEGACY_OPTION, [] ); $this->legacy_cache = is_array( $value ) ? $value : []; } return $this->legacy_cache; }
    private function value( string $option, string $legacy_key, $default ) { $sentinel = '__wutm_cs_missing__'; $value = get_option( $option, $sentinel ); if ( $value !== $sentinel ) return $value; $legacy = $this->legacy_settings(); return array_key_exists( $legacy_key, $legacy ) ? $legacy[ $legacy_key ] : $default; }
    private function settings(): array {
        if ( null !== $this->settings_cache ) return $this->settings_cache;
        $d = $this->defaults(); $yes = static function ( $value ): int { return in_array( $value, [ 'yes', '1', 1, true ], true ) ? 1 : 0; };
        $this->settings_cache = [
            'enabled' => $yes( $this->value( self::OPT_ENABLED, 'enabled', 'yes' ) ),
            'title' => (string) $this->value( self::OPT_TITLE, 'title', $d['title'] ), 'message' => (string) $this->value( self::OPT_MESSAGE, 'message', $d['message'] ),
            'style_enabled' => $yes( $this->value( self::OPT_STYLE_ENABLED, 'style_enabled', 'yes' ) ),
            'bg_color' => (string) $this->value( self::OPT_BG_COLOR, 'bg_color', $d['bg_color'] ), 'text_color' => (string) $this->value( self::OPT_TEXT_COLOR, 'text_color', $d['text_color'] ),
            'accent_color' => (string) $this->value( self::OPT_ACCENT_COLOR, 'accent_color', $d['accent_color'] ), 'card_width' => (string) $this->value( self::OPT_CARD_WIDTH, 'card_width', $d['card_width'] ), 'radius' => (string) $this->value( self::OPT_RADIUS, 'radius', $d['radius'] ),
        ];
        return $this->settings_cache;
    }

    public function admin_assets( $hook ): void { if ( 'woocommerce_page_wc-settings' !== $hook || ( $_GET['tab'] ?? '' ) !== self::TAB_ID ) return; wp_enqueue_style( 'wp-color-picker' ); wp_enqueue_script( 'wp-color-picker' ); wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".wutm-wc-coming-soon-color").wpColorPicker();});' ); }
    public function inject_settings_fields( array $settings, $section ): array {
        if ( '' !== $section || ! current_user_can( 'manage_woocommerce' ) ) return $settings;
        foreach ( $settings as $field ) if ( ( $field['id'] ?? '' ) === self::MARKER_ID ) return $settings;
        $o = $this->settings();
        $fields = [
            [ 'title' => '即將推出頁客製化', 'type' => 'title', 'id' => self::MARKER_ID, 'desc' => $this->status_description() ], [ 'type' => 'wutm_cs_notice', 'id' => 'wutm_cs_apply_rule_notice' ],
            [ 'title' => '啟用文字替換', 'id' => self::OPT_ENABLED, 'type' => 'checkbox', 'default' => $o['enabled'] ? 'yes' : 'no', 'desc' => '只替換 WooCommerce 原生即將推出頁的標題與說明，不調整網站字體、底色或版面。' ],
            [ 'title' => '標題', 'id' => self::OPT_TITLE, 'type' => 'text', 'css' => 'width:400px;max-width:100%;', 'default' => $o['title'] ], [ 'title' => '說明文字', 'id' => self::OPT_MESSAGE, 'type' => 'textarea', 'css' => 'width:520px;max-width:100%;height:90px;', 'default' => $o['message'] ],
            [ 'type' => 'sectionend', 'id' => 'wutm_cs_section_end' ],
        ];
        $after = null; foreach ( $settings as $index => $field ) if ( ( $field['type'] ?? '' ) === 'sectionend' ) $after = $index;
        if ( null === $after ) return array_merge( $settings, $fields ); array_splice( $settings, $after + 1, 0, $fields ); return $settings;
    }
    private function status_description(): string { if ( $this->is_customizer_active() ) return '目前條件符合：已選擇「即將推出」，且已開啟「僅套用至商店頁面」。本區設定與首頁商品簡碼會自動生效。'; if ( get_option( 'woocommerce_coming_soon' ) === 'yes' ) return '目前尚未套用：已選擇「即將推出」，但「僅套用至商店頁面」尚未開啟。'; return '目前尚未套用：請先選擇「即將推出」，並開啟「僅套用至商店頁面」。'; }
    public function render_notice_field( $value ): void { $active = $this->is_customizer_active(); $color = $active ? '#46b450' : '#dba617'; $bg = $active ? '#f0f8f1' : '#fff8e5'; echo '<tr valign="top"><th scope="row" class="titledesc">套用條件</th><td class="forminp"><div style="max-width:760px;padding:12px 14px;border-left:4px solid ' . esc_attr( $color ) . ';background:' . esc_attr( $bg ) . ';line-height:1.7"><strong>本功能只有在上方同時設定：</strong><br>① 選擇「即將推出」<br>② 開啟「僅套用至商店頁面」<br><br>兩個條件都成立時，才會套用這裡的文字／外觀設定，並自動讓首頁商品簡碼顯示即將推出內容。</div></td></tr>'; }
    public function after_settings_saved(): void { if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'woocommerce-settings' ) ) return; $this->sanitize_saved_options(); $this->settings_cache = null; $this->legacy_cache = null; add_action( 'shutdown', [ $this, 'purge_caches' ], 999 ); }
    private function sanitize_saved_options(): void {
        foreach ( [ self::OPT_ENABLED, self::OPT_STYLE_ENABLED ] as $option ) update_option( $option, get_option( $option, 'no' ) === 'yes' ? 'yes' : 'no', false );
        update_option( self::OPT_TITLE, sanitize_text_field( get_option( self::OPT_TITLE, '' ) ), false ); update_option( self::OPT_MESSAGE, sanitize_textarea_field( get_option( self::OPT_MESSAGE, '' ) ), false );
        foreach ( [ self::OPT_BG_COLOR => '#ffffff', self::OPT_TEXT_COLOR => '#12233f', self::OPT_ACCENT_COLOR => '#2a63c9' ] as $option => $fallback ) update_option( $option, sanitize_hex_color( get_option( $option, $fallback ) ) ?: $fallback, false );
        $width = trim( (string) get_option( self::OPT_CARD_WIDTH, '640px' ) ); update_option( self::OPT_CARD_WIDTH, preg_match( '/^\d+(?:\.\d+)?(?:px|%|vw|rem|em)$/', $width ) ? $width : '640px', false ); update_option( self::OPT_RADIUS, (string) max( 0, min( 48, absint( get_option( self::OPT_RADIUS, 18 ) ) ) ), false );
    }
    private function is_customizer_active(): bool { return get_option( 'woocommerce_coming_soon' ) === 'yes' && get_option( 'woocommerce_store_pages_only' ) === 'yes'; }
    public function register_frontend_hooks(): void { if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! $this->is_customizer_active() || current_user_can( 'manage_woocommerce' ) ) return; $this->override_home_product_shortcodes(); $s = $this->settings(); if ( $s['enabled'] && ( $s['title'] !== '' || $s['message'] !== '' ) ) add_action( 'template_redirect', [ $this, 'start_text_replacement' ], 0 ); }
    private function override_home_product_shortcodes(): void { foreach ( self::HOME_PRODUCTS_SHORTCODES as $tag ) { if ( shortcode_exists( $tag ) ) { remove_shortcode( $tag ); add_shortcode( $tag, [ $this, 'render_coming_soon_shortcode' ] ); } } }
    public function render_coming_soon_shortcode( $atts = [], $content = null, $tag = '' ): string {
        // WooCommerce's coming-soon block is a container block. A self-closing instance
        // has no inner blocks on the frontend, so give the native block its default content.
        if ( ! function_exists( 'do_blocks' ) ) return '';
        $settings = $this->settings();
        $title = $settings['enabled'] && $settings['title'] !== '' ? $settings['title'] : 'Coming Soon';
        $message = $settings['enabled'] && $settings['message'] !== '' ? $settings['message'] : 'We are preparing our store and will launch soon.';
        $block = '<!-- wp:woocommerce/coming-soon {"storeOnly":true} -->'
            . '<div class="wp-block-woocommerce-coming-soon woocommerce-coming-soon-store-only">'
            . '<!-- wp:group {"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group">'
            . '<!-- wp:heading {"textAlign":"center","level":1} -->'
            . '<h1 class="wp-block-heading has-text-align-center">' . esc_html( $title ) . '</h1>'
            . '<!-- /wp:heading -->'
            . '<!-- wp:paragraph {"align":"center"} -->'
            . '<p class="has-text-align-center">' . esc_html( $message ) . '</p>'
            . '<!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:woocommerce/coming-soon -->';
        return (string) do_blocks( $block );
    }
    public function start_text_replacement(): void { $s = $this->settings(); ob_start( static function ( $html ) use ( $s ) { $replace = []; if ( $s['title'] !== '' ) $replace = [ '大事即將發生' => $s['title'], 'Something big is coming' => $s['title'], 'Coming soon' => $s['title'] ]; if ( $s['message'] !== '' ) $replace += [ '有大事要發生了！ 我們正在籌備商店中，很快就會推出！' => $s['message'], '有大事要發生了！我們正在籌備商店中，很快就會推出！' => $s['message'], 'We are working on our store and will be back soon.' => $s['message'] ]; return $replace ? str_replace( array_keys( $replace ), array_values( $replace ), $html ) : $html; } ); }
    public function enqueue_frontend_style(): void {
        $s = $this->settings(); $width = preg_match( '/^\d+(?:\.\d+)?(?:px|%|vw|rem|em)$/', $s['card_width'] ) ? $s['card_width'] : '640px'; $radius = max( 0, min( 48, absint( $s['radius'] ) ) );
        $css = '.wp-block-woocommerce-coming-soon,.wp-block-woocommerce-coming-soon *{animation:none!important;transition:none!important}.wp-block-woocommerce-coming-soon{display:flex!important;align-items:center;justify-content:center;min-height:100vh;padding:40px 20px;box-sizing:border-box;background:#f2f4f7}.wp-block-woocommerce-coming-soon .wp-block-cover{min-height:auto!important}.wp-block-woocommerce-coming-soon .wp-block-cover__inner-container,.wp-block-woocommerce-coming-soon .wp-block-group{width:100%;max-width:' . esc_attr( $width ) . ';margin:0 auto;background:' . esc_attr( $s['bg_color'] ) . ';color:' . esc_attr( $s['text_color'] ) . ';border-radius:' . $radius . 'px;padding:48px 40px;box-shadow:0 18px 48px rgba(0,0,0,.10);text-align:center;box-sizing:border-box}.wp-block-woocommerce-coming-soon h1,.wp-block-woocommerce-coming-soon h2,.wp-block-woocommerce-coming-soon p{color:' . esc_attr( $s['text_color'] ) . '!important}.wp-block-woocommerce-coming-soon h1,.wp-block-woocommerce-coming-soon h2{font-size:clamp(28px,5vw,42px);margin:0 0 18px;font-weight:700}.wp-block-woocommerce-coming-soon p{font-size:16px;line-height:1.7;margin:0}.wp-block-woocommerce-coming-soon a{color:' . esc_attr( $s['accent_color'] ) . '!important}@media(max-width:767px){.wp-block-woocommerce-coming-soon .wp-block-cover__inner-container,.wp-block-woocommerce-coming-soon .wp-block-group{padding:36px 22px}}';
        wp_register_style( 'wutm-wc-coming-soon-style', false, [], null ); wp_enqueue_style( 'wutm-wc-coming-soon-style' ); wp_add_inline_style( 'wutm-wc-coming-soon-style', $css );
    }
    public function purge_caches(): void { static $done = false; if ( $done ) return; $done = true; if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain(); if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache(); if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all(); if ( function_exists( 'sg_cachepress_purge_cache' ) ) sg_cachepress_purge_cache(); if ( class_exists( 'Cache_Enabler' ) && is_callable( [ 'Cache_Enabler', 'clear_complete_cache' ] ) ) Cache_Enabler::clear_complete_cache(); if ( class_exists( '\\FlyingPress\\Purge' ) && is_callable( [ '\\FlyingPress\\Purge', 'purge_everything' ] ) ) \FlyingPress\Purge::purge_everything(); do_action( 'litespeed_purge_all' ); do_action( 'wutm_wc_coming_soon_after_purge_cache' ); }
}
function wutm_wc_coming_soon_customizer_init(): WUTM_WC_Coming_Soon_Customizer { static $instance = null; if ( null === $instance ) $instance = new WUTM_WC_Coming_Soon_Customizer(); return $instance; }
wutm_wc_coming_soon_customizer_init();
