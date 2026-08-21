<?php
/**
 * 主外掛類別（Singleton）
 *
 * @package YangSheep\ChatWidgets
 * @since   1.0.0
 */

namespace YangSheep\ChatWidgets;

use YangSheep\ChatWidgets\Admin\YSChatAdmin;
use YangSheep\ChatWidgets\Admin\YSChatAjaxHandler;
use YangSheep\ChatWidgets\Database\YSChatSettingsRepo;
use YangSheep\ChatWidgets\Database\YSChatTableMaker;
use YangSheep\ChatWidgets\Frontend\YSChatFrontend;

defined( 'ABSPATH' ) || exit;

class YSChatWidgets {

    /** @var self|null 單一實例 */
    private static ?self $instance = null;

    /**
     * 取得單一實例
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 建構子 — 私有，防止外部 new
     */
    private function __construct() {
        $this->maybe_upgrade_schema();
        $this->init_components();
    }

    /**
     * 取得目前設定（含預設值合併）
     */
    public static function get_settings(): array {
        $saved = YSChatSettingsRepo::get( 'settings' );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return array_merge( YSChatMigration::default_settings(), $saved );
    }

    /**
     * 檢查 schema 版本，必要時升級資料表
     */
    private function maybe_upgrade_schema(): void {
        $current = get_option( 'wutm_contact_widgets_schema_version', '0' );
        if ( version_compare( $current, WUTM_CONTACT_WIDGETS_VERSION, '<' ) ) {
            YSChatTableMaker::create_tables();
            update_option( 'wutm_contact_widgets_schema_version', WUTM_CONTACT_WIDGETS_VERSION );
        }
    }

    /**
     * 初始化各元件
     */
    private function init_components(): void {
        if ( is_admin() ) {
            new YSChatAdmin();
            new YSChatAjaxHandler();
        }
        new YSChatFrontend();
    }
}
