<?php
/**
 * AJAX 處理器
 *
 * @package YangSheep\ChatWidgets\Admin
 * @since   1.0.0
 */

namespace YangSheep\ChatWidgets\Admin;

use YangSheep\ChatWidgets\Database\YSChatSettingsRepo;
use YangSheep\ChatWidgets\YSChatApps;
use YangSheep\ChatWidgets\YSChatMigration;

defined( 'ABSPATH' ) || exit;

class YSChatAjaxHandler {

    public function __construct() {
        add_action( 'wp_ajax_wutm_contact_widgets_save', [ $this, 'save_settings' ] );
    }

    /**
     * 儲存設定（AJAX）
     */
    public function save_settings(): void {
        // Nonce 驗證。
        if ( ! check_ajax_referer( 'wutm_contact_widgets_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( '安全驗證失敗', 'wu-contact-widgets' ) ], 403 );
        }

        // 權限驗證。
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '權限不足', 'wu-contact-widgets' ) ], 403 );
        }

        $raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( (string) $_POST['settings'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( [ 'message' => __( '沒有收到設定資料', 'wu-contact-widgets' ) ] );
        }

        $settings = $this->sanitize_settings( $raw );

        if ( ! YSChatSettingsRepo::set( 'settings', $settings ) ) {
            wp_send_json_error( [ 'message' => __( '寫入資料庫失敗', 'wu-contact-widgets' ) ] );
        }

        wp_send_json_success( [
            'message'  => __( '設定已儲存', 'wu-contact-widgets' ),
            'settings' => $settings,
        ] );
    }

    /**
     * 白名單式清理全部設定
     */
    private function sanitize_settings( array $raw ): array {
        $defaults = YSChatMigration::default_settings();
        $out      = $defaults;

        $out['enabled']      = ! empty( $raw['enabled'] ) ? 1 : 0;
        $pos                 = isset( $raw['position'] ) ? (string) $raw['position'] : 'right';
        $out['position']     = in_array( $pos, [ 'left', 'center', 'right' ], true ) ? $pos : 'right';
        $out['bottom']       = isset( $raw['bottom'] ) ? min( 400, max( 0, (int) $raw['bottom'] ) ) : $defaults['bottom'];
        $out['side']         = isset( $raw['side'] ) ? min( 400, max( 0, (int) $raw['side'] ) ) : $defaults['side'];
        $out['size_outer']   = isset( $raw['size_outer'] ) ? min( 96, max( 36, (int) $raw['size_outer'] ) ) : $defaults['size_outer'];
        $out['size_inner']   = isset( $raw['size_inner'] ) ? min( 80, max( 28, (int) $raw['size_inner'] ) ) : $defaults['size_inner'];
        $out['show_desktop'] = ! empty( $raw['show_desktop'] ) ? 1 : 0;
        $out['show_mobile']  = ! empty( $raw['show_mobile'] ) ? 1 : 0;
        $tt                  = isset( $raw['tooltip'] ) ? (string) $raw['tooltip'] : 'appname';
        $out['tooltip']      = in_array( $tt, [ 'appname', 'content', 'none' ], true ) ? $tt : 'appname';
        $out['mode']         = ( isset( $raw['mode'] ) && 'popup' === $raw['mode'] ) ? 'popup' : 'redirect';
        $style_mode          = isset( $raw['style_mode'] ) ? (string) $raw['style_mode'] : 'auto';
        $out['style_mode']   = in_array( $style_mode, [ 'auto', 'force', 'off' ], true ) ? $style_mode : 'auto';

        $color = isset( $raw['button_color'] ) ? (string) $raw['button_color'] : '';
        $out['button_color'] = preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? strtolower( $color ) : $defaults['button_color'];

        $out['button_icon'] = isset( $raw['button_icon'] ) ? esc_url_raw( (string) $raw['button_icon'] ) : '';
        $out['icon_style']  = ( isset( $raw['icon_style'] ) && 'cover' === $raw['icon_style'] ) ? 'cover' : 'contain';

        $display = isset( $raw['display'] ) ? (string) $raw['display'] : 'all';
        $out['display'] = in_array( $display, [ 'all', 'include', 'exclude' ], true ) ? $display : 'all';

        $out['include_pages'] = $this->sanitize_id_list( $raw['include_pages'] ?? [] );
        $out['exclude_pages'] = $this->sanitize_id_list( $raw['exclude_pages'] ?? [] );

        // Apps（白名單 key + 值清洗 + 上限 20 個）。
        $known = YSChatApps::all();
        $apps  = [];
        foreach ( (array) ( $raw['apps'] ?? [] ) as $app ) {
            if ( ! is_array( $app ) || empty( $app['key'] ) || ! isset( $known[ (string) $app['key'] ] ) ) {
                continue;
            }
            $key   = (string) $app['key'];
            $value = isset( $app['value'] ) ? sanitize_text_field( (string) $app['value'] ) : '';
            $value = YSChatApps::normalize_value( $key, $value );
            if ( '' === $value ) {
                continue;
            }
            $apps[] = [
                'key'   => $key,
                'value' => $value,
                'title' => isset( $app['title'] ) ? sanitize_text_field( (string) $app['title'] ) : '',
                'icon'  => isset( $app['icon'] ) ? esc_url_raw( (string) $app['icon'] ) : '',
            ];
            if ( count( $apps ) >= 20 ) {
                break;
            }
        }
        $out['apps'] = $apps;

        return $out;
    }

    /**
     * 頁面 ID 清單清理
     *
     * @param mixed $list 來源清單
     * @return int[]
     */
    private function sanitize_id_list( mixed $list ): array {
        $ids = array_filter( array_map( 'intval', (array) $list ), fn( $id ) => $id > 0 );
        return array_values( array_unique( $ids ) );
    }
}
