<?php
/**
 * Module: wc-coming-soon-customizer
 * Customizes WooCommerce's native coming-soon page text without replacing its block or visibility controls.
 */
defined( 'ABSPATH' ) || exit;

final class WUTM_WC_Coming_Soon_Customizer {
    private const OPTION = 'wutm_wc_coming_soon_customizer';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'template_redirect', [ $this, 'start_text_replacement' ], 1 );
    }

    public function defaults(): array {
        return [
            'enabled' => 1,
            'title'   => 'Coming Soon',
            'message' => 'We are preparing our store and will launch soon.',
        ];
    }

    private function settings(): array {
        return wp_parse_args( (array) get_option( self::OPTION, [] ), $this->defaults() );
    }

    public function register_settings(): void {
        register_setting( 'wutm_wc_coming_soon_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => function ( $input ): array {
                $input = is_array( $input ) ? $input : [];
                return [
                    'enabled' => empty( $input['enabled'] ) ? 0 : 1,
                    'title'   => sanitize_text_field( $input['title'] ?? '' ),
                    'message' => sanitize_textarea_field( $input['message'] ?? '' ),
                ];
            },
            'default' => $this->defaults(),
        ] );
    }

    public function add_menu(): void {
        add_submenu_page( 'wu-toolbox-modular', 'WC 即將推出修改', 'WC 即將推出修改', 'manage_woocommerce', 'wu-wc-coming-soon-customizer', [ $this, 'render_admin_page' ] );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $settings = $this->settings();
        $is_coming_soon = get_option( 'woocommerce_coming_soon' ) === 'yes';
        ?>
        <div class="wrap wutm-module-wrap wutm-wc-coming-soon-admin">
            <h1>WC 即將推出修改</h1>
            <p class="wutm-module-subtitle">保留 WooCommerce 原生「即將推出」頁面與網站可見性設定，只替換頁面中的預設標題與說明文字。</p>
            <div class="notice <?php echo $is_coming_soon ? 'notice-success' : 'notice-warning'; ?> inline"><p><?php echo $is_coming_soon ? '目前 WooCommerce 已啟用即將推出模式，儲存後會套用下方文字。' : '目前 WooCommerce 未啟用即將推出模式。請到 WooCommerce → 設定 → 網站可見度開啟後再使用。'; ?></p></div>
            <form method="post" action="options.php" class="wutm-wc-coming-soon-form">
                <?php settings_fields( 'wutm_wc_coming_soon_group' ); ?>
                <section class="wutm-wc-coming-soon-card">
                    <h2>顯示文字</h2>
                    <label class="wutm-wc-coming-soon-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> 啟用文字替換</label>
                    <label>標題<input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[title]" value="<?php echo esc_attr( $settings['title'] ); ?>" maxlength="160"></label>
                    <label>說明<textarea name="<?php echo esc_attr( self::OPTION ); ?>[message]" rows="3" maxlength="500"><?php echo esc_textarea( $settings['message'] ); ?></textarea></label>
                    <p class="description">留空即保留 WooCommerce 目前的原始文字。此模組只在訪客看到即將推出頁時處理，不會影響後台或 WooCommerce 的頁面編輯功能。</p>
                </section>
                <?php submit_button( '儲存設定' ); ?>
            </form>
        </div>
        <style>.wutm-wc-coming-soon-admin{max-width:980px}.wutm-wc-coming-soon-card{margin-top:18px;padding:22px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.wutm-wc-coming-soon-card h2{margin:0 0 18px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;font-size:17px}.wutm-wc-coming-soon-card label{display:block;max-width:680px;margin:16px 0;font-weight:600}.wutm-wc-coming-soon-card input[type=text],.wutm-wc-coming-soon-card textarea{display:block;width:100%;margin-top:7px}.wutm-wc-coming-soon-toggle{display:flex!important;gap:8px;align-items:center}.wutm-wc-coming-soon-toggle input{margin:0!important}</style>
        <?php
    }

    public function start_text_replacement(): void {
        if ( is_admin() || current_user_can( 'manage_woocommerce' ) || ! $this->is_coming_soon() ) return;
        $settings = $this->settings();
        if ( empty( $settings['enabled'] ) || ( $settings['title'] === '' && $settings['message'] === '' ) ) return;
        ob_start( function ( $html ) use ( $settings ): string {
            $replace = [];
            if ( $settings['title'] !== '' ) $replace['大事即將發生'] = $settings['title'];
            if ( $settings['message'] !== '' ) {
                $replace['有大事要發生了！ 我們正在籌備商店中，很快就會推出！'] = $settings['message'];
                $replace['有大事要發生了！我們正在籌備商店中，很快就會推出！'] = $settings['message'];
            }
            return str_replace( array_keys( $replace ), array_values( $replace ), $html );
        } );
    }

    private function is_coming_soon(): bool {
        return get_option( 'woocommerce_coming_soon' ) === 'yes';
    }
}

new WUTM_WC_Coming_Soon_Customizer();
