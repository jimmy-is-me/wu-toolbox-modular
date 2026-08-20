<?php
/**
 * Module: moving-mode
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 維護模式模組
 * 功能:網站維護模式、管理員登入、即時預覽
 * 版本:2.1 - 登入錯誤處理、雙重驗證、快取連動優化
 */

if (!defined('ABSPATH')) exit;

/**
 * 主類別 - 維護模式管理
 */
class WU_Moving_Mode {
    
    /**
     * 設定組名稱
     */
    private $option_group = 'wu_moving_mode_group';
    
    /**
     * Nonce action 名稱
     */
    private $nonce_action = 'wu_moving_mode_save_action';
    
    /**
     * Nonce 欄位名稱
     */
    private $nonce_field = 'wu_moving_mode_nonce';
    
    /**
     * 建構函數
     */
    public function __construct() {
        // 後台功能
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'), 60);
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
        
        // 前台維護模式
        add_action('template_redirect', array($this, 'check_maintenance_mode'), 1);
        
        // 登入失敗處理
        add_action('wp_login_failed', array($this, 'handle_login_failed'));
        add_filter('authenticate', array($this, 'handle_authenticate_error'), 30, 3);
        
        // 快取清理 (監聽所有相關選項更新)
        add_action('update_option_wu_moving_mode_status', array($this, 'clear_cache'));
        add_action('update_option_wu_moving_mode_title', array($this, 'clear_cache'));
        add_action('update_option_wu_moving_mode_message', array($this, 'clear_cache'));
        add_action('update_option_wu_moving_mode_copyright', array($this, 'clear_cache'));
    }
    
    /**
     * 註冊子選單
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '維護模式設定',
            '維護模式設定',
            'wu-moving-mode',
            'wu-moving-mode',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * 註冊設定 (使用 Settings API)
     */
    public function register_settings() {
        // 註冊設定
        register_setting(
            $this->option_group,
            'wu_moving_mode_status',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_status'),
                'default' => 'off'
            )
        );
        
        register_setting(
            $this->option_group,
            'wu_moving_mode_title',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '網站維護中'
            )
        );
        
        register_setting(
            $this->option_group,
            'wu_moving_mode_message',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => '我們正在為您帶來更好的體驗,請稍後再回來'
            )
        );
        
        register_setting(
            $this->option_group,
            'wu_moving_mode_copyright',
            array(
                'type' => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default' => 'Copyright © 2025 <a href="https://wumetax.com/" target="_blank">Wumetax</a> All rights reserved ｜ 網站建置與維護'
            )
        );
    }
    
    /**
     * 清理狀態值
     */
    public function sanitize_status($value) {
        return in_array($value, array('on', 'off')) ? $value : 'off';
    }
    
    /**
     * 載入後台資源
     */
    public function enqueue_admin_assets($hook) {
        // 僅在維護模式設定頁面載入
        if ($hook !== 'wu-toolbox-modular_page_wu-moving-mode') {
            return;
        }
        
        // 載入 WordPress 編輯器
        wp_enqueue_editor();
        
        // 註冊並載入 CSS
        wp_enqueue_style(
            'wu-moving-mode-admin',
            false,
            array(),
            '2.1'
        );
        
        wp_add_inline_style('wu-moving-mode-admin', $this->get_admin_css());
        
        // 註冊並載入 JavaScript
        wp_enqueue_script(
            'wu-moving-mode-admin',
            false,
            array('jquery'),
            '2.1',
            true
        );
        
        wp_add_inline_script('wu-moving-mode-admin', $this->get_admin_js());
    }
    
    /**
     * 取得後台 CSS
     */
    private function get_admin_css() {
        return '
        .wu-moving-settings-wrap {
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
            align-items: flex-start;
        }
        
        .wu-moving-form {
            flex: 1;
            min-width: 300px;
        }
        
        .wu-moving-preview {
            flex: 1;
            max-width: 450px;
        }
        
        .wu-preview-box {
            border: 1px solid #ccc;
            border-radius: 10px;
            padding: 30px;
            background: #ffffff;
            color: #111;
            text-align: center;
        }
        
        .wu-preview-box h3 {
            color: #111;
            font-size: 1.5em;
            margin-top: 0;
        }
        
        .wu-preview-box p {
            color: #555;
            white-space: pre-wrap;
        }
        
        .wu-preview-box button {
            margin: 15px 0;
            padding: 8px 16px;
            background: #111;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .wu-preview-copyright {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            font-size: 0.9em;
            color: #888;
        }
        
        .wu-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .wu-status-on {
            background: #d63638;
            color: #fff;
        }
        
        .wu-status-off {
            background: #00a32a;
            color: #fff;
        }
        ';
    }
    
    /**
     * 取得後台 JavaScript
     */
    private function get_admin_js() {
        return '
        jQuery(document).ready(function($) {
            const titleField = $("#wu_moving_mode_title");
            const messageField = $("#wu_moving_mode_message");
            const previewTitle = $("#preview-title");
            const previewMessage = $("#preview-message");
            const previewCopyright = $("#preview-copyright");
            
            // 標題即時預覽
            titleField.on("input", function() {
                previewTitle.text($(this).val() || "網站維護中");
            });
            
            // 描述即時預覽
            messageField.on("input", function() {
                const text = $(this).val() || "我們正在為您帶來更好的體驗,請稍後再回來";
                previewMessage.html(text.replace(/\\n/g, "<br>"));
            });
            
            // TinyMCE 編輯器預覽設定
            function setupCopyrightPreview() {
                if (typeof tinymce !== "undefined") {
                    const editorId = "wu_moving_mode_copyright";
                    
                    // 等待編輯器初始化
                    if (tinymce.get(editorId)) {
                        const editor = tinymce.get(editorId);
                        
                        editor.on("change keyup", function() {
                            const content = editor.getContent() || 
                                "Copyright © 2025 <a href=\\"https://wumetax.com/\\" target=\\"_blank\\">Wumetax</a> All rights reserved ｜ 網站建置與維護";
                            previewCopyright.html(content);
                        });
                        
                        return true;
                    }
                }
                
                // 備用方案：直接監聽 textarea
                const textarea = $("textarea[name=\'wu_moving_mode_copyright\']");
                if (textarea.length && textarea.is(":visible")) {
                    textarea.on("input", function() {
                        const content = $(this).val() || 
                            "Copyright © 2025 <a href=\\"https://wumetax.com/\\" target=\\"_blank\\">Wumetax</a> All rights reserved ｜ 網站建置與維護";
                        previewCopyright.html(content);
                    });
                    return true;
                }
                
                return false;
            }
            
            // 使用 TinyMCE Setup 鉤子
            if (typeof tinymce !== "undefined") {
                tinymce.on("SetupEditor", function(e) {
                    if (e.editor.id === "wu_moving_mode_copyright") {
                        e.editor.on("init", function() {
                            setupCopyrightPreview();
                        });
                    }
                });
            }
            
            // 延遲檢查
            setTimeout(setupCopyrightPreview, 1000);
            
            // 監聽編輯器模式切換
            $(document).on("click", ".wp-switch-editor", function() {
                setTimeout(setupCopyrightPreview, 300);
            });
        });
        ';
    }
    
    /**
     * 渲染設定頁面
     */
    public function render_settings_page() {
        // 驗證權限
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('您沒有足夠的權限訪問此頁面。', 'wu-toolbox-modular'));
        }
        
        // 處理表單提交
        if (isset($_POST['wu_moving_mode_save'])) {
            // 驗證 Nonce
            if (!isset($_POST[$this->nonce_field]) || 
                !wp_verify_nonce($_POST[$this->nonce_field], $this->nonce_action)) {
                wp_die(esc_html__('安全驗證失敗', 'wu-toolbox-modular'));
            }
            
            // 再次驗證權限
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('權限不足', 'wu-toolbox-modular'));
            }
            
            // 儲存設定
            update_option('wu_moving_mode_status', $this->sanitize_status($_POST['wu_moving_mode_status']));
            update_option('wu_moving_mode_title', sanitize_text_field($_POST['wu_moving_mode_title']));
            update_option('wu_moving_mode_message', sanitize_textarea_field($_POST['wu_moving_mode_message']));
            update_option('wu_moving_mode_copyright', wp_kses_post($_POST['wu_moving_mode_copyright']));
            
            echo '<div class="updated"><p>✅ 維護模式設定已更新並清除快取</p></div>';
        }
        
        // 取得當前設定
        $status = get_option('wu_moving_mode_status', 'off');
        $title = get_option('wu_moving_mode_title', '網站維護中');
        $message = get_option('wu_moving_mode_message', '我們正在為您帶來更好的體驗,請稍後再回來');
        $copyright = get_option('wu_moving_mode_copyright', 'Copyright © 2025 <a href="https://wumetax.com/" target="_blank">Wumetax</a> All rights reserved ｜ 網站建置與維護');
        
        ?>
        <div class="wrap">
            <h1>
                維護模式設定
                <span class="wu-status-badge wu-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status === 'on' ? '已啟用' : '已關閉'; ?>
                </span>
            </h1>
            
            <div class="wu-moving-settings-wrap">
                <!-- 設定表單 -->
                <form method="post" class="wu-moving-form">
                    <?php wp_nonce_field($this->nonce_action, $this->nonce_field); ?>
                    
                    <h2>功能開關</h2>
                    <p>
                        <label>
                            <input type="radio" 
                                   name="wu_moving_mode_status" 
                                   value="on" 
                                   <?php checked($status, 'on'); ?>>
                            <strong>開啟維護模式</strong><br>
                            <span style="color: #666; font-size: 13px;">
                                前台訪客將會看到維護頁,僅管理員可登入並使用網站
                            </span>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" 
                                   name="wu_moving_mode_status" 
                                   value="off" 
                                   <?php checked($status, 'off'); ?>>
                            <strong>關閉維護模式</strong><br>
                            <span style="color: #666; font-size: 13px;">
                                網站將正常顯示給所有訪客
                            </span>
                        </label>
                    </p>
                    
                    <h2>維護頁面內容</h2>
                    <p>
                        <label for="wu_moving_mode_title">標題</label><br>
                        <input type="text" 
                               id="wu_moving_mode_title" 
                               name="wu_moving_mode_title" 
                               value="<?php echo esc_attr($title); ?>" 
                               class="regular-text">
                    </p>
                    
                    <p>
                        <label for="wu_moving_mode_message">描述</label><br>
                        <textarea id="wu_moving_mode_message" 
                                  name="wu_moving_mode_message" 
                                  rows="3" 
                                  class="large-text"><?php echo esc_textarea($message); ?></textarea>
                    </p>
                    
                    <p>
                        <label>版權資訊</label><br>
                        <span style="color: #666; font-size: 13px;">
                            支援 HTML 格式,可使用連結、粗體等標籤
                        </span>
                    </p>
                    <?php
                    wp_editor($copyright, 'wu_moving_mode_copyright', array(
                        'textarea_name' => 'wu_moving_mode_copyright',
                        'media_buttons' => false,
                        'textarea_rows' => 3,
                        'teeny' => true,
                        'quicktags' => false,
                        'tinymce' => array(
                            'toolbar1' => 'bold,italic,link,unlink',
                            'toolbar2' => '',
                            'toolbar3' => '',
                            'plugins' => 'link',
                            'forced_root_block' => false,
                            'force_br_newlines' => true,
                            'force_p_newlines' => false,
                            'convert_newlines_to_brs' => true
                        )
                    ));
                    ?>
                    
                    <p style="margin-top: 20px;">
                        <input type="submit" 
                               name="wu_moving_mode_save" 
                               class="button-primary" 
                               value="儲存設定">
                    </p>
                </form>
                
                <!-- 即時預覽 -->
                <div class="wu-moving-preview">
                    <h2>即時預覽</h2>
                    <div class="wu-preview-box">
                        <h3 id="preview-title"><?php echo esc_html($title); ?></h3>
                        <p id="preview-message"><?php echo nl2br(esc_html($message)); ?></p>
                        <button>管理員登入</button>
                        <div id="preview-copyright" class="wu-preview-copyright">
                            <?php echo $copyright; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 檢查並顯示維護模式
     */
    public function check_maintenance_mode() {
        // 管理員不受限制
        if (current_user_can('manage_options')) {
            return;
        }
        
        // 維護模式未啟用
        if (get_option('wu_moving_mode_status', 'off') !== 'on') {
            return;
        }
        
        // 排除特定請求
        if ($this->is_excluded_request()) {
            return;
        }
        
        // 載入前台維護頁
        $this->render_maintenance_page();
    }
    
    /**
     * 檢查是否為排除的請求
     */
    private function is_excluded_request() {
        // 排除 REST API
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        
        // 排除 AJAX 請求
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }
        
        // 排除 XML-RPC
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }
        
        // 排除 WP-Cron
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        
        // 檢查請求 URI
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $excluded_paths = array(
            '/wp-json/',
            '/wp-admin/admin-ajax.php',
            '/xmlrpc.php'
        );
        
        foreach ($excluded_paths as $path) {
            if (strpos($request_uri, $path) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 處理登入失敗 (wp_login_failed Hook)
     */
    public function handle_login_failed($username) {
        // 檢查是否為維護模式
        if (get_option('wu_moving_mode_status', 'off') !== 'on') {
            return;
        }
        
        // 設定 Cookie 標記登入失敗
        setcookie('wu_login_error', '1', time() + 300, '/');
        
        // 重導向回首頁 (觸發維護頁面)
        wp_redirect(home_url());
        exit;
    }
    
    /**
     * 處理認證錯誤 (authenticate Filter)
     */
    public function handle_authenticate_error($user, $username, $password) {
        // 檢查是否為維護模式
        if (get_option('wu_moving_mode_status', 'off') !== 'on') {
            return $user;
        }
        
        // 如果認證失敗
        if (is_wp_error($user)) {
            // 設定 Cookie 標記登入失敗
            setcookie('wu_login_error', '1', time() + 300, '/');
        }
        
        return $user;
    }
    
    /**
     * 渲染前台維護頁面
     */
    private function render_maintenance_page() {
        // 雙重驗證維護模式狀態
        if (get_option('wu_moving_mode_status', 'off') !== 'on') {
            return;
        }
        
        $title = get_option('wu_moving_mode_title', '網站維護中');
        $message = get_option('wu_moving_mode_message', '我們正在為您帶來更好的體驗,請稍後再回來');
        $copyright = get_option('wu_moving_mode_copyright', 'Copyright © 2025 <a href="https://wumetax.com/" target="_blank">Wumetax</a> All rights reserved ｜ 網站建置與維護');
        
        // 檢查是否有登入錯誤
        $has_login_error = isset($_COOKIE['wu_login_error']) && $_COOKIE['wu_login_error'] === '1';
        
        // 清除錯誤 Cookie
        if ($has_login_error) {
            setcookie('wu_login_error', '', time() - 3600, '/');
        }
        
        // 設定 HTTP 標頭
        nocache_headers();
        status_header(503);
        header('Retry-After: 3600');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // 取得登入 URL (包含重導向參數)
        $login_url = wp_login_url(home_url());
        
        ?>
        <!DOCTYPE html>
        <html lang="zh-Hant">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html($title); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                html, body { height: 100%; }
                body {
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    text-align: center;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    color: #111;
                    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 50%, #f1f3f4 100%);
                }
                h1 {
                    font-size: 2.2em;
                    margin: 0.5em 0;
                    color: #111;
                }
                p {
                    font-size: 1.2em;
                    color: #555;
                    white-space: pre-wrap;
                    max-width: 600px;
                    line-height: 1.6;
                }
                .copyright {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                    font-size: 0.9em;
                    color: #888;
                }
                .copyright a {
                    color: #888;
                    text-decoration: none;
                }
                .copyright a:hover {
                    text-decoration: underline;
                }
                
                /* 登入按鈕 */
                #login-btn {
                    position: fixed;
                    top: 15px;
                    right: 15px;
                    background: #111;
                    color: #fff;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 8px;
                    cursor: pointer;
                    z-index: 9999;
                    font-size: 14px;
                    font-weight: bold;
                    transition: all 0.3s ease;
                }
                #login-btn:hover {
                    background: #333;
                    transform: translateY(-2px);
                }
                
                /* 登入彈窗 */
                #login-box {
                    display: none;
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(20px);
                    -webkit-backdrop-filter: blur(20px);
                    border-radius: 16px;
                    padding: 30px;
                    z-index: 10000;
                    width: 90%;
                    max-width: 380px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    text-align: center;
                    color: #111;
                }
                #login-box h2 {
                    margin-top: 0;
                    font-weight: 600;
                    color: #111;
                    font-size: 1.4em;
                }
                #login-box label {
                    display: block;
                    margin-top: 15px;
                    font-size: 0.9em;
                    color: #333;
                    text-align: left;
                    font-weight: 500;
                }
                #login-box input[type=text],
                #login-box input[type=password] {
                    width: 100%;
                    padding: 12px;
                    margin-top: 5px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    background: #f7f7f7;
                    color: #111;
                    text-align: left;
                    font-size: 14px;
                    transition: all 0.3s ease;
                }
                #login-box input:focus {
                    outline: none;
                    border-color: #111;
                    background: #fff;
                    box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
                }
                #login-box button[type=submit] {
                    width: 100%;
                    padding: 12px;
                    margin-top: 20px;
                    background: #111;
                    color: #fff;
                    border: none;
                    border-radius: 8px;
                    font-size: 16px;
                    cursor: pointer;
                    font-weight: bold;
                    transition: all 0.3s ease;
                }
                #login-box button[type=submit]:hover {
                    background: #333;
                    transform: translateY(-2px);
                }
                #close-login {
                    position: absolute;
                    top: 15px;
                    right: 20px;
                    cursor: pointer;
                    font-size: 20px;
                    color: #aaa;
                    transition: color 0.3s ease;
                }
                #close-login:hover {
                    color: #111;
                }
                
                /* 錯誤訊息 */
                .login-error {
                    background: #fff3cd;
                    border: 1px solid #ffc107;
                    border-radius: 8px;
                    padding: 12px;
                    margin-bottom: 15px;
                    color: #856404;
                    font-size: 14px;
                    text-align: center;
                    display: none;
                }
                .login-error.show {
                    display: block;
                }
                
                /* 背景模糊 */
                #backdrop {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    backdrop-filter: blur(5px);
                    -webkit-backdrop-filter: blur(5px);
                    z-index: 9998;
                }
                
                /* 響應式設計 */
                @media (max-width: 768px) {
                    h1 { font-size: 1.8em; }
                    p { font-size: 1em; padding: 0 20px; }
                    #login-box { padding: 25px; }
                }
            </style>
        </head>
        <body>
            <button id="login-btn">管理員登入</button>
            
            <h1><?php echo esc_html($title); ?></h1>
            <p><?php echo nl2br(esc_html($message)); ?></p>
            <div class="copyright"><?php echo $copyright; ?></div>
            
            <div id="backdrop"></div>
            <div id="login-box">
                <span id="close-login">✖</span>
                <h2>管理員登入</h2>
                
                <div class="login-error <?php echo $has_login_error ? 'show' : ''; ?>" id="login-error">
                    ⚠️ 帳號或密碼錯誤,請重新輸入
                </div>
                
                <form id="login-form" action="<?php echo esc_url($login_url); ?>" method="post">
                    <label for="user_login">帳號 / 電子郵件</label>
                    <input type="text" name="log" id="user_login" required autocomplete="username">
                    
                    <label for="user_pass">密碼</label>
                    <input type="password" name="pwd" id="user_pass" required autocomplete="current-password">
                    
                    <button type="submit">登入</button>
                </form>
            </div>
            
            <script>
            (function() {
                const loginBtn = document.getElementById("login-btn");
                const loginBox = document.getElementById("login-box");
                const backdrop = document.getElementById("backdrop");
                const closeBtn = document.getElementById("close-login");
                const userField = document.getElementById("user_login");
                const passField = document.getElementById("user_pass");
                const loginForm = document.getElementById("login-form");
                const loginError = document.getElementById("login-error");
                const hasLoginError = <?php echo $has_login_error ? 'true' : 'false'; ?>;
                
                // 開啟登入視窗
                function openLogin() {
                    backdrop.style.display = "block";
                    loginBox.style.display = "block";
                    userField.focus();
                }
                
                // 關閉登入視窗
                function closeLogin() {
                    backdrop.style.display = "none";
                    loginBox.style.display = "none";
                    loginError.classList.remove("show");
                }
                
                // 事件監聽
                loginBtn.addEventListener("click", openLogin);
                closeBtn.addEventListener("click", closeLogin);
                backdrop.addEventListener("click", closeLogin);
                
                // 如果有登入錯誤,自動開啟登入視窗
                if (hasLoginError) {
                    openLogin();
                }
                
                // ESC 鍵關閉
                document.addEventListener("keydown", function(e) {
                    if (e.key === "Escape" && loginBox.style.display === "block") {
                        closeLogin();
                    }
                });
                
                // 表單驗證
                loginForm.addEventListener("submit", function(e) {
                    if (userField.value.trim() === "" || passField.value.trim() === "") {
                        e.preventDefault();
                        loginError.textContent = "⚠️ 請輸入帳號與密碼!";
                        loginError.classList.add("show");
                        return false;
                    }
                });
                
                // Enter 鍵提交
                [userField, passField].forEach(function(field) {
                    field.addEventListener("keypress", function(e) {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            loginForm.querySelector("button[type=submit]").click();
                        }
                    });
                });
                
                // 輸入時隱藏錯誤訊息
                [userField, passField].forEach(function(field) {
                    field.addEventListener("input", function() {
                        loginError.classList.remove("show");
                    });
                });
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * 清除快取
     */
    public function clear_cache() {
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        
        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        
        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
        }
        
        // WordPress 內建快取
        wp_cache_flush();
    }
}

// 初始化模組
new WU_Moving_Mode();
