<?php
/**
 * Module: admin-bar-cleaner
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * 後台設定模組
 * 功能：後台設定、安全性強化
 * 版本：5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WU_Admin_Bar_Cleaner {

    private const CHECKBOX_OPTIONS = [
        'wu_remove_wp_logo',
        'wu_remove_new_content',
        'wu_hide_login_logo',
        'wu_disable_login_language_switcher',
        'wu_disable_all_dashboard_widgets',
        'wu_remove_admin_footer_text',
        'wu_hide_tools_menu',
        'wu_hide_wordpress_address',
        'wu_hide_site_address',
        'wu_hide_writing_settings',
        'wu_hide_privacy_settings',
        'wu_hide_wumetax_toolkit',
        'wu_hide_admin_updates',
        'wu_enable_copy_protection',
    ];

    private const TEXT_OPTIONS = [
        'wu_custom_admin_footer_text',
        'wu_custom_frontend_footer_text',
        'wu_copy_protection_message',
    ];

    private const PROTECTED_ROLES = [ 'subscriber', 'administrator' ];

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ], 5 );
    }

    public function init(): void {
        if ( is_admin() || wp_doing_ajax() ) {
            add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 10 );
            add_action( 'admin_init', [ $this, 'admin_init' ] );
            add_action( 'wp_ajax_wu_admin_bar_save', [ $this, 'ajax_save_settings' ] );
        }

        $this->register_frontend_features();
        $this->register_admin_features();
    }

    // ===== 後台選單 =====

    public function add_admin_menu(): void {
        add_submenu_page(
            'wumetax-toolkit',
            '後台介面管理',
            '後台介面管理',
            'manage_options',
            'wu-admin-bar-cleaner',
            'wu_admin_bar_settings'
        );
    }

    // ===== Settings API 註冊 =====

    public function admin_init(): void {
        // ✅ 修正：TEXT_OPTIONS 加上 sanitize_callback
        foreach ( self::CHECKBOX_OPTIONS as $opt ) {
            register_setting( 'wu_admin_bar_settings', $opt );
        }
        foreach ( self::TEXT_OPTIONS as $opt ) {
            register_setting( 'wu_admin_bar_settings', $opt, [
                'sanitize_callback' => 'sanitize_text_field',
            ] );
        }
        register_setting( 'wu_admin_bar_settings', 'wu_disabled_user_roles' );

        add_settings_section( 'wu_admin_bar_section',  '後台設定',               [ $this, 'settings_section_callback' ],   'wu_admin_bar_settings' );
        add_settings_section( 'wu_login_section',      'WordPress 登入頁面設定', [ $this, 'login_section_callback' ],      'wu_admin_bar_settings' );
        add_settings_section( 'wu_dashboard_section',  '儀表板設定',             [ $this, 'dashboard_section_callback' ],  'wu_admin_bar_settings' );
        add_settings_section( 'wu_backend_section',    '後台隱藏設定',           [ $this, 'backend_section_callback' ],    'wu_admin_bar_settings' );
        add_settings_section( 'wu_frontend_section',   '前台設定',               [ $this, 'frontend_section_callback' ],   'wu_admin_bar_settings' );
        add_settings_section( 'wu_user_roles_section', '使用者角色管理',         [ $this, 'user_roles_section_callback' ], 'wu_admin_bar_settings' );

        $fields = [
            [ 'wu_remove_wp_logo',                 '移除 WordPress 標誌',              'remove_wp_logo_callback',                  'wu_admin_bar_section'  ],
            [ 'wu_remove_new_content',              '移除管理列新增項目',               'remove_new_content_callback',              'wu_admin_bar_section'  ],
            [ 'wu_hide_login_logo',                 '隱藏登入頁面 WordPress 標誌',      'hide_login_logo_callback',                 'wu_login_section'      ],
            [ 'wu_disable_login_language_switcher', '停用登入語言切換器',               'disable_login_language_switcher_callback', 'wu_login_section'      ],
            [ 'wu_disable_all_dashboard_widgets',   '儀表板小工具一鍵管理',             'dashboard_widgets_settings_callback',      'wu_dashboard_section'  ],
            [ 'wu_remove_admin_footer_text',        '移除管理頁尾文本',                 'remove_admin_footer_text_callback',        'wu_dashboard_section'  ],
            [ 'wu_custom_admin_footer_text',        '自訂管理頁尾文本',                 'custom_admin_footer_text_callback',        'wu_dashboard_section'  ],
            [ 'wu_hide_tools_menu',                 '隱藏後台 Tools 選單',              'hide_tools_menu_callback',                 'wu_backend_section'    ],
            [ 'wu_hide_wordpress_address',          '隱藏 WordPress Address (URL)',      'hide_wordpress_address_callback',          'wu_backend_section'    ],
            [ 'wu_hide_site_address',               '隱藏 Site Address (URL)',           'hide_site_address_callback',               'wu_backend_section'    ],
            [ 'wu_hide_writing_settings',           '隱藏 Writing Settings',             'hide_writing_settings_callback',           'wu_backend_section'    ],
            [ 'wu_hide_privacy_settings',           '隱藏 Privacy 設定',                'hide_privacy_settings_callback',           'wu_backend_section'    ],
            [ 'wu_hide_admin_updates',              '隱藏後台更新通知',                 'hide_admin_updates_callback',              'wu_backend_section'    ],
            [ 'wu_hide_wumetax_toolkit',            '向其他管理員隱藏 WumetaxToolkit',  'hide_wumetax_toolkit_callback',            'wu_backend_section'    ],
            [ 'wu_custom_frontend_footer_text',     '自訂前台頁尾文本',                 'custom_frontend_footer_text_callback',     'wu_frontend_section'   ],
            [ 'wu_enable_copy_protection',          '內容複製保護',                     'copy_protection_callback',                 'wu_frontend_section'   ],
            [ 'wu_disabled_user_roles',             '停用使用者角色',                   'user_roles_management_callback',           'wu_user_roles_section' ],
        ];

        foreach ( $fields as [ $id, $title, $callback, $section ] ) {
            add_settings_field( $id, $title, [ $this, $callback ], 'wu_admin_bar_settings', $section );
        }
    }

    // ===== Section 說明 =====

    public function settings_section_callback(): void {
        echo '<p>後台設定功能可以幫助您移除不必要的 WordPress 預設項目，讓管理界面更加簡潔。</p>';
    }
    public function login_section_callback(): void {
        echo '<p>自訂登入頁面的外觀和功能，提供更專業的使用者體驗。</p>';
    }
    public function dashboard_section_callback(): void {
        echo '<p>管理 WordPress 儀表板的小工具和介面元素，簡化管理體驗。</p>';
    }
    public function backend_section_callback(): void {
        echo '<p>選擇性隱藏後台特定設定選項和選單，讓後台介面更加簡潔專業。</p>';
    }
    public function frontend_section_callback(): void {
        echo '<p>自訂前台網站的顯示內容和外觀設定。</p>';
        echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px 15px;margin:12px 0;border-radius:4px;">';
        echo '<strong>⚠️ 安全提醒：</strong>JavaScript 防複製功能僅具備基本防禦能力，無法完全阻止技術使用者透過瀏覽器開發工具存取內容。';
        echo '</div>';
    }
    public function user_roles_section_callback(): void {
        echo '<p>管理 WordPress 使用者角色的顯示和可用性，可選擇停用特定角色以簡化使用者管理。</p>';
    }

    // ===== 欄位 Callbacks =====

    private function checkbox_field( string $name, string $label, string $description = '' ): void {
        $value = get_option( $name, false );
        echo '<label>';
        echo '<input type="checkbox" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 1, $value, false ) . '> ';
        echo esc_html( $label );
        echo '</label>';
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
    }

    public function remove_wp_logo_callback(): void {
        $this->checkbox_field( 'wu_remove_wp_logo', '移除管理列中的 WordPress 標誌（W 圖示）' );
    }
    public function remove_new_content_callback(): void {
        $this->checkbox_field( 'wu_remove_new_content', '移除管理列中的新增項目（+ 新增）' );
    }
    public function hide_login_logo_callback(): void {
        $this->checkbox_field( 'wu_hide_login_logo', '隱藏登入頁面的 WordPress 標誌' );
    }
    public function disable_login_language_switcher_callback(): void {
        $this->checkbox_field( 'wu_disable_login_language_switcher', '停用 WordPress 登入語言切換器' );
    }
    public function remove_admin_footer_text_callback(): void {
        $this->checkbox_field( 'wu_remove_admin_footer_text', '移除預設管理頁尾文本' );
    }
    public function hide_tools_menu_callback(): void {
        $this->checkbox_field( 'wu_hide_tools_menu', '隱藏後台 Tools 選單' );
    }
    public function hide_wordpress_address_callback(): void {
        $this->checkbox_field( 'wu_hide_wordpress_address', '隱藏 WordPress Address (URL)' );
    }
    public function hide_site_address_callback(): void {
        $this->checkbox_field( 'wu_hide_site_address', '隱藏 Site Address (URL)' );
    }
    public function hide_writing_settings_callback(): void {
        $this->checkbox_field( 'wu_hide_writing_settings', '隱藏 Writing Settings 選單項目' );
    }
    public function hide_privacy_settings_callback(): void {
        $this->checkbox_field( 'wu_hide_privacy_settings', '隱藏 Privacy 設定選單項目' );
    }
    public function hide_admin_updates_callback(): void {
        $this->checkbox_field( 'wu_hide_admin_updates', '隱藏後台更新通知（僅隱藏 UI 提示，不阻止更新檢查）' );
    }

    public function dashboard_widgets_settings_callback(): void {
        $value = get_option( 'wu_disable_all_dashboard_widgets', false );
        echo '<div style="background:#f9f9f9;padding:18px;border-radius:8px;">';
        echo '<p>一鍵停用所有儀表板小工具，讓後台載入更快速、界面更簡潔。</p>';
        echo '<label style="display:flex;align-items:center;gap:10px;font-size:15px;">';
        echo '<input type="checkbox" id="wu_disable_all_dashboard_widgets" name="wu_disable_all_dashboard_widgets" value="1" ' . checked( 1, $value, false ) . '>';
        echo '<strong>停用所有儀表板小工具</strong></label>';
        echo '<p style="margin-top:12px;padding:10px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;"><strong>保留：</strong>郵件發送追蹤管理、網站維運管理儀表板</p>';
        echo '</div>';
    }

    public function custom_admin_footer_text_callback(): void {
        $value = get_option( 'wu_custom_admin_footer_text', '' );
        echo '<input type="text" id="wu_custom_admin_footer_text" name="wu_custom_admin_footer_text" value="' . esc_attr( $value ) . '" class="regular-text">';
        echo '<p class="description">輸入自訂的管理頁尾文本。留空則不顯示。移除頁尾文本開啟時，此設定無效。</p>';
    }

    public function hide_wumetax_toolkit_callback(): void {
        $current_user = wp_get_current_user();
        $saved_id     = (int) get_option( 'wu_hide_wumetax_toolkit_admin_id', 0 );
        $this->checkbox_field( 'wu_hide_wumetax_toolkit', '向其他管理員隱藏 WumetaxToolkit 外掛選單' );
        echo '<p class="description"><strong>當前管理員：</strong>ID: ' . (int) $current_user->ID . ' | Email: ' . esc_html( $current_user->user_email ) . '</p>';
        if ( $saved_id && $saved_id !== (int) $current_user->ID ) {
            echo '<p class="description" style="color:#d63638;">⚠️ 此設定由管理員 ID: ' . $saved_id . ' 建立，其他管理員目前無法看到此選單。</p>';
        }
    }

    public function custom_frontend_footer_text_callback(): void {
        $value = get_option( 'wu_custom_frontend_footer_text', '' );
        echo '<input type="text" id="wu_custom_frontend_footer_text" name="wu_custom_frontend_footer_text" value="' . esc_attr( $value ) . '" class="regular-text">';
        echo '<p class="description">輸入自訂的前台頁尾文本。留空則不顯示。</p>';
    }

    public function copy_protection_callback(): void {
        $enabled = get_option( 'wu_enable_copy_protection', false );
        $message = get_option( 'wu_copy_protection_message', '此網站已啟用內容保護，禁止複製與右鍵操作。' );
        echo '<label><input type="checkbox" name="wu_enable_copy_protection" value="1" ' . checked( 1, $enabled, false ) . '> 啟用前台內容複製保護</label>';
        echo '<p style="margin-top:10px;"><label>警告訊息：<br>';
        echo '<input type="text" name="wu_copy_protection_message" value="' . esc_attr( $message ) . '" class="regular-text"></label></p>';
    }

    public function user_roles_management_callback(): void {
        $disabled_roles = (array) get_option( 'wu_disabled_user_roles', [] );
        $wp_roles       = wp_roles();
        $all_roles      = $wp_roles->get_names();

        echo '<p><strong>偵測到 ' . count( $all_roles ) . ' 個使用者角色</strong>（subscriber、administrator 受保護，無法停用）</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:15px;">';

        foreach ( $all_roles as $role_key => $role_name ) {
            $is_protected = in_array( $role_key, self::PROTECTED_ROLES, true );
            $is_disabled  = ! empty( $disabled_roles[ $role_key ] );
            $role_obj     = get_role( $role_key );
            $cap_count    = $role_obj ? count( $role_obj->capabilities ) : 0;

            $border = $is_protected ? '2px solid #0073aa' : '1px solid #ddd';
            $bg     = $is_protected ? '#f0f6ff' : '#fff';

            echo '<div style="background:' . $bg . ';padding:16px;border:' . $border . ';border-radius:8px;">';
            if ( $is_protected ) {
                echo '<div style="margin-bottom:10px;padding:5px 10px;background:#0073aa;color:#fff;border-radius:4px;font-size:12px;text-align:center;">受保護角色</div>';
                echo '<label style="cursor:not-allowed;opacity:.6;"><input type="checkbox" disabled>';
            } else {
                echo '<label style="cursor:pointer;display:block;"><input type="checkbox" name="wu_disabled_user_roles[' . esc_attr( $role_key ) . ']" value="1" ' . checked( 1, $is_disabled ? 1 : 0, false ) . '>';
            }
            echo ' <strong>' . esc_html( translate_user_role( $role_name ) ) . '</strong>';
            echo '<div style="margin-top:4px;color:#999;font-size:12px;">代碼：' . esc_html( $role_key ) . '　權限：' . (int) $cap_count . ' 個</div>';
            if ( $is_protected ) {
                echo '<div style="color:#0073aa;font-size:12px;margin-top:6px;font-style:italic;">此角色受保護，無法停用</div>';
            }
            echo '</label></div>';
        }
        echo '</div>';
    }

    // ===== AJAX 儲存 =====

    public function ajax_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => '沒有權限執行此操作' ] );
        }
        // ✅ 修正：改用 check_ajax_referer，驗證失敗自動 die()
        check_ajax_referer( 'wu_admin_bar_settings-options', 'wu_admin_bar_nonce' );
        $this->save_settings_from_post();
        wp_send_json_success( [ 'message' => '設定已儲存！變更已立即生效。' ] );
    }

    // ===== 統一儲存邏輯 =====

    private function save_settings_from_post(): void {
        foreach ( self::CHECKBOX_OPTIONS as $key ) {
            update_option( $key, isset( $_POST[ $key ] ) ? 1 : 0 );
        }
        foreach ( self::TEXT_OPTIONS as $key ) {
            update_option( $key, sanitize_text_field( $_POST[ $key ] ?? '' ) );
        }

        // ✅ user_roles 白名單過濾
        $wp_roles      = wp_roles();
        $all_role_keys = array_keys( $wp_roles->get_names() );
        $raw_roles     = isset( $_POST['wu_disabled_user_roles'] ) && is_array( $_POST['wu_disabled_user_roles'] )
            ? $_POST['wu_disabled_user_roles']
            : [];

        $clean_roles = [];
        foreach ( $raw_roles as $role_key => $val ) {
            $role_key = sanitize_key( $role_key );
            if ( in_array( $role_key, $all_role_keys, true ) && ! in_array( $role_key, self::PROTECTED_ROLES, true ) ) {
                $clean_roles[ $role_key ] = 1;
            }
        }
        update_option( 'wu_disabled_user_roles', $clean_roles );

        // ✅ 修正：hide_wumetax_toolkit admin_id 只在儲存時處理，不在 admin_menu render 時寫入
        if ( isset( $_POST['wu_hide_wumetax_toolkit'] ) ) {
            $current_id = get_current_user_id();
            $saved_id   = (int) get_option( 'wu_hide_wumetax_toolkit_admin_id', 0 );
            if ( ! $saved_id ) {
                update_option( 'wu_hide_wumetax_toolkit_admin_id', $current_id );
            }
        } else {
            delete_option( 'wu_hide_wumetax_toolkit_admin_id' );
        }
    }

    // ===== 管理頁面 UI =====

    public function admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '權限不足' );
        }

        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'wu_admin_bar_settings-options' );
            $this->save_settings_from_post();
            echo '<div class="notice notice-success is-dismissible"><p>✅ 設定已儲存！變更已立即生效。</p></div>';
        }

        $opts = [];
        foreach ( array_merge( self::CHECKBOX_OPTIONS, self::TEXT_OPTIONS ) as $key ) {
            $opts[ $key ] = get_option( $key, false );
        }
        $disabled_roles = (array) get_option( 'wu_disabled_user_roles', [] );
        ?>
        <div class="wrap">
            <h1>後台介面管理</h1>

            <div class="card" style="max-width:100%;">
                <h2 style="margin-top:0;">📊 當前狀態</h2>
                <table class="wu-status-table">
                    <thead><tr><th>項目</th><th>狀態</th></tr></thead>
                    <tbody>
                    <?php
                    $status_rows = [
                        [ '管理列 WordPress 標誌',   $opts['wu_remove_wp_logo'],                   '已隱藏',              '顯示中'               ],
                        [ '管理列新增項目',           $opts['wu_remove_new_content'],               '已隱藏',              '顯示中'               ],
                        [ '登入頁面標誌',             $opts['wu_hide_login_logo'],                  '已隱藏',              '顯示中'               ],
                        [ '登入語言切換器',           $opts['wu_disable_login_language_switcher'],  '已停用',              '已啟用'               ],
                        [ '儀表板小工具',             $opts['wu_disable_all_dashboard_widgets'],    '已全部停用',          '已啟用'               ],
                        [ '後台 Tools 選單',          $opts['wu_hide_tools_menu'],                  '已隱藏',              '顯示中'               ],
                        [ '後台更新通知',             $opts['wu_hide_admin_updates'],               '已隱藏（僅 UI）',     '顯示中'               ],
                        [ '內容複製保護',             $opts['wu_enable_copy_protection'],           '已啟用',              '已停用'               ],
                        [ 'WumetaxToolkit 選單隱藏',  $opts['wu_hide_wumetax_toolkit'],             '已啟用（限定管理員）', '停用（所有管理員可見）' ],
                    ];
                    foreach ( $status_rows as [ $label, $active, $on_text, $off_text ] ):
                        $cls  = $active ? 'wu-status-on' : 'wu-status-off';
                        $text = $active ? $on_text : $off_text;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $label ); ?></strong></td>
                            <td><span class="<?php echo $cls; ?>"><?php echo esc_html( $text ); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>管理頁尾文本</strong></td>
                        <td>
                            <?php if ( $opts['wu_remove_admin_footer_text'] ): ?>
                                <span class="wu-status-on">已隱藏</span>
                            <?php elseif ( ! empty( $opts['wu_custom_admin_footer_text'] ) ): ?>
                                <span class="wu-status-custom">自訂：<?php echo esc_html( $opts['wu_custom_admin_footer_text'] ); ?></span>
                            <?php else: ?>
                                <span class="wu-status-off">預設</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>前台頁尾文本</strong></td>
                        <td>
                            <?php if ( ! empty( $opts['wu_custom_frontend_footer_text'] ) ): ?>
                                <span class="wu-status-custom">自訂：<?php echo esc_html( $opts['wu_custom_frontend_footer_text'] ); ?></span>
                            <?php else: ?>
                                <span class="wu-status-off">預設</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>使用者角色管理</strong></td>
                        <td>
                            <?php if ( ! empty( $disabled_roles ) ): ?>
                                <span class="wu-status-on">已停用 <?php echo count( $disabled_roles ); ?> 個角色</span>
                            <?php else: ?>
                                <span class="wu-status-off">無停用角色</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <form method="post" action="" id="wu-admin-bar-form">
                <?php
                settings_fields( 'wu_admin_bar_settings' );
                do_settings_sections( 'wu_admin_bar_settings' );
                wp_nonce_field( 'wu_admin_bar_settings-options', 'wu_admin_bar_nonce' );
                submit_button( '儲存設定' );
                ?>
            </form>
        </div>

        <style>
        .wu-status-on     { color:#00a32a; font-weight:700; }
        .wu-status-off    { color:#d63638; font-weight:700; }
        .wu-status-custom { color:#0073aa; font-weight:700; }
        .card             { background:#fff; border:1px solid #ccd0d4; padding:20px; margin:20px 0; border-radius:4px; }
        .wu-status-table  { width:100%; margin-top:10px; border-collapse:collapse; }
        .wu-status-table th,
        .wu-status-table td { padding:8px 12px; border:1px solid #e0e0e0; text-align:left; }
        .wu-status-table th { background:#f5f5f5; }
        .wu-status-table tbody tr:hover { background:#fafafa; }
        </style>

        <script>
        jQuery(document).ready(function($){
            $('#wu-admin-bar-form').on('change input', 'input[type="checkbox"], input[type="text"]', function(){
                clearTimeout(window.wuSaveTimer);
                window.wuSaveTimer = setTimeout(function(){
                    $.post(ajaxurl, $('#wu-admin-bar-form').serialize() + '&action=wu_admin_bar_save', function(res){
                        $('.wu-ajax-notice').remove();
                        var type  = res.success ? 'notice-success' : 'notice-error';
                        var msg   = res.success ? res.data.message : '儲存失敗，請重試。';
                        var $note = $('<div class="notice ' + type + ' is-dismissible wu-ajax-notice"><p>' + msg + '</p></div>');
                        $('.wrap h1').after($note);
                        setTimeout(function(){ $note.fadeOut(400, function(){ $(this).remove(); }); }, 3000);
                    });
                }, 800);
            });
        });
        </script>
        <?php
    }

    // ===== 功能掛載 =====

    private function register_frontend_features(): void {
        if ( get_option( 'wu_remove_wp_logo', false ) ) {
            add_action( 'admin_bar_menu', [ $this, 'remove_wp_logo_from_admin_bar' ], 999 );
        }
        if ( get_option( 'wu_remove_new_content', false ) ) {
            add_action( 'admin_bar_menu', [ $this, 'remove_new_content_from_admin_bar' ], 999 );
        }
        if ( get_option( 'wu_hide_login_logo', false ) ) {
            add_action( 'login_enqueue_scripts', [ $this, 'hide_login_logo' ] );
        }
        if ( get_option( 'wu_disable_login_language_switcher', false ) ) {
            add_filter( 'login_display_language_dropdown', '__return_false' );
        }
        if ( ! is_admin() ) {
            if ( ! empty( get_option( 'wu_custom_frontend_footer_text', '' ) ) ) {
                add_action( 'wp_footer', [ $this, 'custom_frontend_footer_text' ] );
            }
            if ( get_option( 'wu_enable_copy_protection', false ) ) {
                add_action( 'wp_head',   [ $this, 'output_copy_protection_head' ],   1 );
                add_action( 'wp_footer', [ $this, 'output_copy_protection_footer' ], 999 );
            }
        }
    }

    private function register_admin_features(): void {
        if ( ! is_admin() ) return;

        if ( get_option( 'wu_disable_all_dashboard_widgets', false ) ) {
            add_action( 'wp_dashboard_setup', [ $this, 'disable_dashboard_widgets' ], 999 );
        }
        if ( get_option( 'wu_remove_admin_footer_text', false ) ) {
            add_filter( 'admin_footer_text', '__return_empty_string' );
            add_filter( 'update_footer',     '__return_empty_string', 11 );
        } elseif ( ! empty( get_option( 'wu_custom_admin_footer_text', '' ) ) ) {
            add_filter( 'admin_footer_text', [ $this, 'custom_admin_footer_text' ] );
            add_filter( 'update_footer',     '__return_empty_string', 11 );
        }
        if ( get_option( 'wu_hide_tools_menu', false ) ) {
            add_action( 'admin_menu', [ $this, 'hide_tools_menu' ], 999 );
        }

        $needs_hide_settings = get_option( 'wu_hide_wordpress_address', false )
                            || get_option( 'wu_hide_site_address', false )
                            || get_option( 'wu_hide_writing_settings', false )
                            || get_option( 'wu_hide_privacy_settings', false );
        if ( $needs_hide_settings ) {
            add_action( 'admin_head', [ $this, 'hide_admin_settings' ] );
        }

        // ✅ 修正：hide_wumetax_toolkit_menu 不在裡面呼叫 update_option，只做 remove_menu_page
        if ( get_option( 'wu_hide_wumetax_toolkit', false ) ) {
            add_action( 'admin_menu', [ $this, 'hide_wumetax_toolkit_menu' ], 999 );
        }

        if ( get_option( 'wu_hide_admin_updates', false ) ) {
            add_action( 'admin_notices',              [ $this, 'remove_update_nag_hook' ] );
            add_action( 'network_admin_notices',      [ $this, 'remove_update_nag_hook' ] );
            add_action( 'wp_before_admin_bar_render', [ $this, 'remove_updates_admin_bar_node' ] );
            add_action( 'admin_head',                 [ $this, 'hide_update_notices_css' ] );
        }

        $this->apply_user_role_restrictions();
    }

    // ===== 功能實作 =====

    public function hide_login_logo(): void {
        echo '<style>.login h1 a { display:none !important; }</style>';
    }

    public function disable_dashboard_widgets(): void {
        global $wp_meta_boxes;
        remove_action( 'welcome_panel', 'wp_welcome_panel' );

        $keep = [ 'wu_email_tracker_dashboard', 'wu_unified_dashboard' ];

        if ( isset( $wp_meta_boxes['dashboard'] ) ) {
            foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
                foreach ( $priorities as $data ) {
                    foreach ( array_keys( $data ) as $widget_id ) {
                        if ( ! in_array( $widget_id, $keep, true ) ) {
                            remove_meta_box( $widget_id, 'dashboard', $context );
                        }
                    }
                }
            }
        }
        remove_action( 'admin_notices', 'wp_dashboard_php_nag_notice' );
    }

    public function remove_update_nag_hook(): void {
        remove_action( 'admin_notices',         'update_nag', 3 );
        remove_action( 'network_admin_notices', 'update_nag', 3 );
    }

    public function remove_updates_admin_bar_node(): void {
        global $wp_admin_bar;
        if ( $wp_admin_bar ) {
            $wp_admin_bar->remove_menu( 'updates' );
        }
    }

    public function hide_update_notices_css(): void {
        echo '<style>
        .update-nag, #update-nag, .update-message,
        .notice.notice-warning.notice-alt.inline.update-message,
        #wp-admin-bar-updates { display:none !important; }
        </style>';
    }

    public function output_copy_protection_head(): void {
        $message = get_option( 'wu_copy_protection_message', '此網站已啟用內容保護，禁止複製與右鍵操作。' );
        ?>
        <style>
        body, body * {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }
        input, textarea { user-select: text !important; -webkit-user-select: text !important; }
        </style>
        <script>var wuProtectionMessage = <?php echo wp_json_encode( $message ); ?>;</script>
        <?php
    }

    public function output_copy_protection_footer(): void {
        ?>
        <script>
        (function() {
            'use strict';
            function block(e) {
                if (e) { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); }
                alert(wuProtectionMessage);
                return false;
            }
            ['contextmenu','selectstart','copy','cut','dragstart'].forEach(function(evt){
                document.addEventListener(evt, block, true);
            });
            document.addEventListener('keydown', function(e) {
                var c = e.ctrlKey || e.metaKey;
                var k = e.keyCode;
                if (c && [65,67,88,83,86,85,80].indexOf(k) !== -1) { block(e); return false; }
                if (k === 123) { block(e); return false; }
                if (c && e.shiftKey && [73,74,67].indexOf(k) !== -1) { block(e); return false; }
            }, true);
            document.addEventListener('mousedown', function(e) {
                if (e.button === 2) { block(e); return false; }
            }, true);
        })();
        </script>
        <?php
    }

    private function apply_user_role_restrictions(): void {
        $disabled_roles = (array) get_option( 'wu_disabled_user_roles', [] );
        if ( empty( $disabled_roles ) ) return;

        add_filter( 'editable_roles', function( array $roles ) use ( $disabled_roles ): array {
            foreach ( array_keys( $disabled_roles ) as $role_key ) {
                unset( $roles[ $role_key ] );
            }
            return $roles;
        } );

        $role_keys_json = wp_json_encode( array_keys( $disabled_roles ) );
        $js = <<<JS
        (function($){
            var roles = {$role_keys_json};
            roles.forEach(function(r){
                $('select[name="role"], select[name="new_role"]').find('option[value="' + r + '"]').remove();
            });
        })(jQuery);
        JS;

        $output_js = function() use ( $js ) {
            echo '<script>' . $js . '</script>';
        };
        add_action( 'admin_head-users.php',     $output_js );
        add_action( 'admin_head-user-edit.php', $output_js );
    }

    public function custom_admin_footer_text(): string {
        return esc_html( get_option( 'wu_custom_admin_footer_text', '' ) );
    }

    public function hide_tools_menu(): void {
        remove_menu_page( 'tools.php' );
    }

    public function hide_admin_settings(): void {
        $hide_wp_addr   = (bool) get_option( 'wu_hide_wordpress_address', false );
        $hide_site_addr = (bool) get_option( 'wu_hide_site_address', false );
        $hide_writing   = (bool) get_option( 'wu_hide_writing_settings', false );
        $hide_privacy   = (bool) get_option( 'wu_hide_privacy_settings', false );

        echo '<style>';
        if ( $hide_wp_addr )   echo '.form-table #siteurl { display:none!important; } .form-table #siteurl + * { display:none!important; }';
        if ( $hide_site_addr ) echo '.form-table #home    { display:none!important; } .form-table #home + *    { display:none!important; }';
        echo '</style>';

        if ( $hide_wp_addr || $hide_site_addr || $hide_writing || $hide_privacy ) {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ( $hide_wp_addr ): ?>
                var wpUrl = document.getElementById('siteurl');
                if (wpUrl) wpUrl.closest('tr') && (wpUrl.closest('tr').style.display = 'none');
                <?php endif; ?>
                <?php if ( $hide_site_addr ): ?>
                var siteUrl = document.getElementById('home');
                if (siteUrl) siteUrl.closest('tr') && (siteUrl.closest('tr').style.display = 'none');
                <?php endif; ?>
                <?php if ( $hide_writing ): ?>
                document.querySelectorAll('#menu-settings a[href="options-writing.php"]').forEach(function(el){
                    var li = el.closest('li');
                    if (li) li.style.display = 'none';
                });
                <?php endif; ?>
                <?php if ( $hide_privacy ): ?>
                document.querySelectorAll('#menu-settings a[href="options-privacy.php"]').forEach(function(el){
                    var li = el.closest('li');
                    if (li) li.style.display = 'none';
                });
                <?php endif; ?>
            });
            </script>
            <?php
        }
    }

    public function custom_frontend_footer_text(): void {
        $text = get_option( 'wu_custom_frontend_footer_text', '' );
        if ( $text ) {
            echo '<div style="text-align:center;padding:20px;margin-top:30px;border-top:1px solid #eee;">'
               . esc_html( $text )
               . '</div>';
        }
    }

    // ✅ 修正：只做 remove_menu_page，不再呼叫 update_option（儲存邏輯移至 save_settings_from_post）
    public function hide_wumetax_toolkit_menu(): void {
        $saved_id   = (int) get_option( 'wu_hide_wumetax_toolkit_admin_id', 0 );
        $current_id = get_current_user_id();

        if ( $saved_id && $current_id !== $saved_id ) {
            remove_menu_page( 'wumetax-toolkit' );
        }
    }

    public function remove_wp_logo_from_admin_bar( WP_Admin_Bar $wp_admin_bar ): void {
        foreach ( [ 'wp-logo', 'wp-logo-external', 'about', 'wporg', 'documentation', 'support-forums', 'feedback' ] as $node ) {
            $wp_admin_bar->remove_node( $node );
        }
    }

    public function remove_new_content_from_admin_bar( WP_Admin_Bar $wp_admin_bar ): void {
        foreach ( [ 'new-content', 'new-post', 'new-media', 'new-page', 'new-user' ] as $node ) {
            $wp_admin_bar->remove_node( $node );
        }
    }
}

new WU_Admin_Bar_Cleaner();
