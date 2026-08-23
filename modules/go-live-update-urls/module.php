<?php
/**
 * WU Toolbox Modular module: 網站網址更新。
 * 將網站資料庫中的舊網址安全替換為新網址，並處理序列化資料。
 */
defined( 'ABSPATH' ) || exit;

define( 'WUTM_GO_LIVE_URLS_VERSION', '7.0.8' );
define( 'WUTM_GO_LIVE_URLS_URL', plugin_dir_url( __FILE__ ) );
define( 'WUTM_GO_LIVE_URLS_PLUGIN_FILE', __FILE__ );

if ( ! function_exists( 'wutm_go_live_update_urls_sanitize_field' ) ) {
    function wutm_go_live_update_urls_sanitize_field( $value ): string {
        $filtered = wp_unslash( (string) $value );
        $filtered = wp_check_invalid_utf8( $filtered );
        $filtered = preg_replace( '/[\\r\\n\\t ]+/', ' ', $filtered );
        return trim( (string) $filtered );
    }
}

spl_autoload_register( static function ( $class ) {
    $prefix = 'WUTM\\GoLive\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $base = __DIR__ . '/src/';
    $relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    $file = $base . $relative;
    if ( is_readable( $file ) ) {
        require_once $file;
    }
} );

try {
    if ( class_exists( 'WUTM\\GoLive\\Admin' ) ) {
        \WUTM\GoLive\Admin::init();
        \WUTM\GoLive\Core::init();
    }
} catch ( Throwable $e ) {
    error_log( 'WU Toolbox Modular go-live-update-urls: ' . $e->getMessage() );
    add_action( 'admin_notices', static function () {
        if ( current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( '網站網址更新模組載入失敗，請檢查外掛檔案。', 'wu-go-live-urls' ) . '</p></div>';
        }
    } );
}
