<?php
/**
 * 後台管理頁面
 *
 * @package YangSheep\ChatWidgets\Admin
 * @since   1.0.0
 */

namespace YangSheep\ChatWidgets\Admin;

use YangSheep\ChatWidgets\YSChatApps;
use YangSheep\ChatWidgets\YSChatMigration;
use YangSheep\ChatWidgets\YSChatWidgets;

defined( 'ABSPATH' ) || exit;

class YSChatAdmin {

    /** @var string 頁面 slug */
    private const PAGE_SLUG = 'wu-contact-widgets';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'migration_notice' ] );
    }

    /**
     * 註冊選單
     *
     * 獨立頂層選單（user 指定：不掛 YS Plugin 下，選單名 浮動聯絡按鈕）
     */
    public function register_menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '浮動聯絡按鈕',
            '浮動聯絡按鈕',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * 移轉成功一次性通知
     */
    public function migration_notice(): void {
        $count = get_transient( 'wutm_contact_widgets_migration_notice' );
        if ( false === $count ) {
            return;
        }
        delete_transient( 'wutm_contact_widgets_migration_notice' );

        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
            esc_html__( '浮動聯絡按鈕：', 'wu-contact-widgets' ),
            esc_html(
                sprintf(
                    /* translators: %d: 移轉的 App 數量 */
                    __( '已自動移轉 NinjaTeam Click to Chat 的 %d 個 App 設定（LINE 連結已修正），並停用舊外掛。', 'wu-contact-widgets' ),
                    (int) $count
                )
            )
        );
    }

    /**
     * 載入後台 CSS / JS（僅在本外掛頁面）
     */
    public function enqueue_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'ys-chat-admin',
            WUTM_CONTACT_WIDGETS_PLUGIN_URL . 'assets/css/ys-chat-admin.css',
            [],
            WUTM_CONTACT_WIDGETS_VERSION
        );

        wp_enqueue_script(
            'ys-chat-admin',
            WUTM_CONTACT_WIDGETS_PLUGIN_URL . 'assets/js/ys-chat-admin.js',
            [ 'jquery' ],
            WUTM_CONTACT_WIDGETS_VERSION,
            true
        );

        $apps_meta = [];
        foreach ( YSChatApps::all() as $key => $def ) {
            $apps_meta[ $key ] = [
                'title'       => $def['title'],
                'color'       => $def['color'],
                'icon_fg'     => $def['icon_fg'],
                'placeholder' => $def['placeholder'],
                'desc'        => $def['desc'],
                'icon'        => YSChatApps::icon( $key ),
            ];
        }

        wp_localize_script( 'ys-chat-admin', 'ysChatAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wutm_contact_widgets_nonce' ),
            'apps'     => $apps_meta,
            'settings' => YSChatWidgets::get_settings(),
            'i18n'     => [
                'saving'      => __( '儲存中…', 'wu-contact-widgets' ),
                'saved'       => __( '設定已儲存', 'wu-contact-widgets' ),
                'error'       => __( '儲存失敗，請重試', 'wu-contact-widgets' ),
                'remove'      => __( '移除', 'wu-contact-widgets' ),
                'moveUp'      => __( '上移', 'wu-contact-widgets' ),
                'moveDown'    => __( '下移', 'wu-contact-widgets' ),
                'customTitle' => __( '顯示名稱（留空用預設）', 'wu-contact-widgets' ),
                'chooseImage' => __( '選擇圖片', 'wu-contact-widgets' ),
                'uploadIcon'  => __( '自訂圖示', 'wu-contact-widgets' ),
                'removeIcon'  => __( '移除圖示', 'wu-contact-widgets' ),
                'emptyApps'   => __( '尚未加入任何 App — 從上方選擇要顯示的聯絡方式。', 'wu-contact-widgets' ),
            ],
        ] );
    }

    /**
     * 渲染後台頁面
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '你沒有足夠的權限。', 'wu-contact-widgets' ) );
        }

        $settings  = YSChatWidgets::get_settings();
        $migration = YSChatMigration::status();

        $template = WUTM_CONTACT_WIDGETS_PLUGIN_DIR . 'templates/admin/settings.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }
}
