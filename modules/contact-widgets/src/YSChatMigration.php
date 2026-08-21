<?php
/**
 * NinjaTeam Click to Chat（WP Support All-in-One）自動移轉
 *
 * 初次啟用時執行：
 * 1. 偵測是否安裝 NinjaTeam 外掛（support-chat/）— 不論啟用與否。
 * 2. 有 → 移轉全部設定與 App 清單；LINE 連結順帶清洗
 *    （完整網址 / 純 ID / 2.3.6 雙重網址 bug 三種格式相融），
 *    然後自動停用對方外掛。
 * 3. 沒有 → 不理會，寫入預設設定單純啟用。
 *
 * 只跑一次（wutm_contact_widgets_migrated flag），重複啟用不會重複移轉。
 *
 * @package YangSheep\ChatWidgets
 * @since   1.0.0
 */

namespace YangSheep\ChatWidgets;

use YangSheep\ChatWidgets\Database\YSChatSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSChatMigration {

    /** NinjaTeam 外掛主檔（相對 plugins 目錄） */
    private const NJT_BASENAME = 'support-chat/wp-support-all-in-one.php';

    /** 移轉狀態 option key */
    private const FLAG_OPTION = 'wutm_contact_widgets_migrated';

    /**
     * 預設設定
     */
    public static function default_settings(): array {
        return [
            'enabled'       => 1,
            'position'      => 'right',
            'bottom'        => 30,
            'side'          => 20,
            'size_outer'    => 56,
            'size_inner'    => 46,
            'button_color'  => '#8fa8b8',
            'button_icon'   => '',
            'icon_style'    => 'contain',
            'show_desktop'  => 1,
            'show_mobile'   => 1,
            'tooltip'       => 'appname',
            'mode'          => 'redirect',
            'style_mode'    => 'auto',
            'display'       => 'all',
            'include_pages' => [],
            'exclude_pages' => [],
            'apps'          => [],
        ];
    }

    /**
     * 啟用時執行（只跑一次）
     *
     * @return array{status:string, apps?:int}
     */
    public static function maybe_migrate(): array {
        // 已處理過 → 跳過（避免重複啟用時覆蓋使用者後續修改）。
        if ( get_option( self::FLAG_OPTION ) ) {
            return [ 'status' => 'skipped' ];
        }

        // 確保已有設定列（無論走哪條路徑）。
        $existing = YSChatSettingsRepo::get( 'settings' );

        if ( ! self::njt_installed() ) {
            // 沒偵測到 NinjaTeam → 不理會，單純啟用。
            if ( ! is_array( $existing ) ) {
                YSChatSettingsRepo::set( 'settings', self::default_settings() );
            }
            update_option( self::FLAG_OPTION, wp_json_encode( [ 'status' => 'clean', 'at' => gmdate( 'Y-m-d H:i:s' ) ] ), false );
            return [ 'status' => 'clean' ];
        }

        // ── 移轉 NinjaTeam 設定 ────────────────────
        $settings = self::build_settings_from_njt();
        YSChatSettingsRepo::set( 'settings', $settings );

        // ── 自動停用對方外掛 ───────────────────────
        self::deactivate_njt();

        update_option(
            self::FLAG_OPTION,
            wp_json_encode( [
                'status' => 'migrated',
                'from'   => 'support-chat',
                'apps'   => count( $settings['apps'] ),
                'at'     => gmdate( 'Y-m-d H:i:s' ),
            ] ),
            false
        );

        // 啟用後顯示一次性通知。
        set_transient( 'wutm_contact_widgets_migration_notice', count( $settings['apps'] ), 300 );

        return [ 'status' => 'migrated', 'apps' => count( $settings['apps'] ) ];
    }

    /**
     * NinjaTeam 外掛是否存在（不論啟用與否）
     */
    public static function njt_installed(): bool {
        return file_exists( WP_PLUGIN_DIR . '/' . self::NJT_BASENAME );
    }

    /**
     * 取得移轉狀態（後台顯示用）
     */
    public static function status(): ?array {
        $raw = get_option( self::FLAG_OPTION );
        if ( ! $raw ) {
            return null;
        }
        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * 由 NinjaTeam options 組出本外掛設定
     */
    private static function build_settings_from_njt(): array {
        $settings = self::default_settings();

        // 一般設定（wpsaio_*）。
        $settings['enabled']      = (int) (bool) get_option( 'wpsaio_enable_plugin', 1 );
        $settings['position']     = ( 'left' === get_option( 'wpsaio_widget_position', 'right' ) ) ? 'left' : 'right';
        $settings['bottom']       = max( 0, (int) get_option( 'wpsaio_bottom_distance', 30 ) );
        $settings['show_desktop'] = (int) (bool) get_option( 'wpsaio_show_on_desktop', 1 );
        $settings['show_mobile']  = (int) (bool) get_option( 'wpsaio_show_on_mobile', 1 );
        $settings['tooltip']      = ( 'content' === get_option( 'wpsaio_tooltip', 'appname' ) ) ? 'content' : 'appname';

        // 點擊模式：NinjaTeam wpsaio_style（redirect / popup）→ mode。
        // 對方 popup = iframe 嵌 line.me（QR 來源）；本外掛 popup = 本地生成 QR 卡片。
        $settings['mode'] = ( 'popup' === get_option( 'wpsaio_style', 'redirect' ) ) ? 'popup' : 'redirect';

        $color = (string) get_option( 'wpsaio_button_color', '' );
        if ( $color && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
            $settings['button_color'] = strtolower( $color );
        }

        $icon = (string) get_option( 'wpsaio_button_icon', '' );
        if ( $icon ) {
            $settings['button_icon'] = esc_url_raw( $icon );
        }

        // 自訂圖顯示方式：NinjaTeam wpsaio_button_image（contain=底色圓+60% 圖 / cover=圖佔滿）。
        $settings['icon_style'] = ( 'cover' === get_option( 'wpsaio_button_image', 'contain' ) ) ? 'cover' : 'contain';

        // 顯示條件。
        $condition = (string) get_option( 'wpsaio_display_condition', 'allPages' );
        $map       = [ 'allPages' => 'all', 'includePages' => 'include', 'excludePages' => 'exclude' ];
        $settings['display']       = $map[ $condition ] ?? 'all';
        $settings['include_pages'] = array_values( array_map( 'intval', (array) get_option( 'wpsaio_includes_pages', [] ) ) );
        $settings['exclude_pages'] = array_values( array_map( 'intval', (array) get_option( 'wpsaio_excludes_pages', [] ) ) );

        // App 清單（njt_wp_saio：key => ['params' => [...]]）。
        $settings['apps'] = self::migrate_apps( (array) get_option( 'njt_wp_saio', [] ) );

        return $settings;
    }

    /**
     * 移轉 App 清單（含 LINE 連結清洗）
     *
     * NinjaTeam params 的主值 key 不一致（url / phone / email / username /
     * account / phone_number⋯），一律取「排除輔助欄位後第一個非空字串」。
     */
    private static function migrate_apps( array $njt_apps ): array {
        $aux_keys = [ 'state', 'custom-app-title', 'url-icon', 'color-icon' ];
        $apps     = [];

        foreach ( $njt_apps as $njt_key => $row ) {
            if ( ! is_array( $row ) || empty( $row['params'] ) || ! is_array( $row['params'] ) ) {
                continue;
            }
            $params = $row['params'];

            // 主值：排除輔助欄位後第一個非空字串。
            $value = '';
            foreach ( $params as $pk => $pv ) {
                if ( in_array( (string) $pk, $aux_keys, true ) ) {
                    continue;
                }
                if ( is_string( $pv ) && '' !== trim( $pv ) ) {
                    $value = trim( $pv );
                    break;
                }
            }
            if ( '' === $value ) {
                continue;
            }

            $key   = self::map_app_key( (string) $njt_key );
            $title = '';
            $icon  = '';
            if ( 'custom' === $key ) {
                if ( ! empty( $params['custom-app-title'] ) && is_string( $params['custom-app-title'] ) ) {
                    $title = sanitize_text_field( $params['custom-app-title'] );
                }
                if ( ! empty( $params['url-icon'] ) && is_string( $params['url-icon'] ) ) {
                    $icon = esc_url_raw( $params['url-icon'] );
                }
            }

            // 連結清洗：完整網址 / 純 ID / 雙重網址 bug 全部相融。
            $value = YSChatApps::normalize_value( $key, $value );
            if ( '' === $value ) {
                continue;
            }

            $apps[] = [
                'key'   => $key,
                'value' => $value,
                'title' => $title,
                'icon'  => $icon,
            ];
        }

        return $apps;
    }

    /**
     * NinjaTeam app key → 本外掛 app key
     */
    private static function map_app_key( string $njt_key ): string {
        // custom-app / custom-app-2 / custom-app-3⋯ → custom。
        if ( 0 === strpos( $njt_key, 'custom-app' ) ) {
            return 'custom';
        }
        $known = YSChatApps::all();
        return isset( $known[ $njt_key ] ) ? $njt_key : 'custom';
    }

    /**
     * 停用 NinjaTeam 外掛
     */
    private static function deactivate_njt(): void {
        if ( ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( self::NJT_BASENAME ) ) {
            deactivate_plugins( self::NJT_BASENAME );
        }
    }
}
