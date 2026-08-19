<?php
/**
 * Module: hide-login-page
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * Hide Login Page Module
 * 隱藏WordPress後台登入位置工具
 */
if (!defined('ABSPATH')) exit;

class WU_Hide_Login_Page {
    
    private $options;
    private $option_name = 'wu_hide_login_page_options';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'), 50);
        add_action('admin_init', array($this, 'init_settings'));
        add_action('init', array($this, 'init_hide_login'));
        add_action('wp_loaded', array($this, 'redirect_login_pages'));
        add_filter('site_url', array($this, 'modify_login_url'), 10, 4);
        add_filter('wp_redirect', array($this, 'modify_redirect_url'), 10, 2);
        add_filter('network_site_url', array($this, 'modify_login_url'), 10, 3);
        
        // 載入選項
        $this->options = get_option($this->option_name, array(
            'enabled' => false,
            'custom_slug' => 'loginwu',
            'redirect_url' => home_url()
        ));
    }
    
    /**
     * 添加子選單頁面
     */
    public function add_submenu_page() {
        add_submenu_page(
            'wumetax-toolkit',
            '變更登入網址',
            '變更登入網址',
            'manage_options',
            'wu-hide-login-page',
            array($this, 'settings_page')
        );
    }
    
    /**
     * 初始化設置
     */
    public function init_settings() {
        register_setting(
            'wu_hide_login_page_group',
            $this->option_name,
            array($this, 'sanitize_options')
        );
        
        add_settings_section(
            'wu_hide_login_page_section',
            '隱藏登入頁面設置',
            array($this, 'section_callback'),
            'wu-hide-login-page'
        );
        
        add_settings_field(
            'enabled',
            '啟用功能',
            array($this, 'enabled_callback'),
            'wu-hide-login-page',
            'wu_hide_login_page_section'
        );
        
        add_settings_field(
            'custom_slug',
            '自定義登入網址',
            array($this, 'custom_slug_callback'),
            'wu-hide-login-page',
            'wu_hide_login_page_section'
        );
        
        add_settings_field(
            'redirect_url',
            '重定向網址',
            array($this, 'redirect_url_callback'),
            'wu-hide-login-page',
            'wu_hide_login_page_section'
        );
    }
    
    /**
     * 設置頁面HTML
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>隱藏登入頁面設置</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wu_hide_login_page_group');
                do_settings_sections('wu-hide-login-page');
                submit_button();
                ?>
            </form>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>功能說明</h2>
                <p><strong>此功能的作用：</strong></p>
                <ul>
                    <li>隱藏WordPress預設的後台登入位置</li>
                    <li>將登入頁面改為自定義網址（預設：/loginwu）</li>
                    <li>訪客無法使用 /wp-admin 或 /wp-login.php 登入</li>
                    <li>已登入的管理員不受影響</li>
                </ul>
                
                <p><strong>注意事項：</strong></p>
                <ul>
                    <li>啟用後，請記住新的登入網址</li>
                    <li>建議將新登入網址加入書籤</li>
                    <li>如果忘記新網址，可以通過資料庫或FTP停用此功能</li>
                </ul>
                
                <?php if (!empty($this->options['enabled'])): ?>
                <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin-top: 15px;">
                    <h3 style="margin-top: 0; color: #155724;">功能已啟用</h3>
                    <p><strong>新的登入網址：</strong> <code><?php echo home_url('/' . $this->options['custom_slug'] . '/'); ?></code></p>
                    <p><strong>請將此網址加入書籤！</strong></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .wu-termius-input {
            background-color: #1e1e1e !important;
            color: #00ff41 !important;
            border: 1px solid #333 !important;
            border-radius: 4px !important;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace !important;
            font-size: 14px !important;
            padding: 12px 16px !important;
            line-height: 1.4 !important;
            transition: all 0.2s ease !important;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3) !important;
        }
        
        .wu-termius-input:focus {
            background-color: #2a2a2a !important;
            border-color: #00ff41 !important;
            box-shadow: 0 0 10px rgba(0, 255, 65, 0.3), inset 0 1px 3px rgba(0, 0, 0, 0.3) !important;
            outline: none !important;
        }
        
        .wu-termius-input::placeholder {
            color: #666 !important;
        }
        
        .wu-termius-input::selection {
            background-color: #00ff41 !important;
            color: #1e1e1e !important;
        }
        </style>
        
        <?php
    }
    
    /**
     * 設置區段回調
     */
    public function section_callback() {
        echo '<p>配置隱藏登入頁面功能的相關設置。</p>';
    }
    
    /**
     * 啟用功能回調
     */
    public function enabled_callback() {
        $enabled = !empty($this->options['enabled']) ? $this->options['enabled'] : false;
        ?>
        <label>
            <input type="checkbox" name="<?php echo $this->option_name; ?>[enabled]" value="1" <?php checked(1, $enabled); ?> />
            啟用隱藏登入頁面功能
        </label>
        <?php
    }
    
    /**
     * 自定義網址回調
     */
    public function custom_slug_callback() {
        $custom_slug = !empty($this->options['custom_slug']) ? $this->options['custom_slug'] : 'loginwu';
        ?>
        <input type="text" name="<?php echo $this->option_name; ?>[custom_slug]" value="<?php echo esc_attr($custom_slug); ?>" class="regular-text wu-termius-input" />
        <p class="description">自定義登入頁面的網址結尾，預設為 "loginwu"。新的登入網址將是：<?php echo home_url('/'); ?><strong><?php echo esc_html($custom_slug); ?></strong>/</p>
        <?php
    }
    
    /**
     * 重定向網址回調 - 修改為下拉式選擇器
     */
    public function redirect_url_callback() {
        $redirect_url = !empty($this->options['redirect_url']) ? $this->options['redirect_url'] : home_url();
        
        // 獲取所有已發布的頁面
        $pages = get_pages(array(
            'post_status' => 'publish',
            'sort_column' => 'menu_order, post_title'
        ));
        
        ?>
        <select name="<?php echo $this->option_name; ?>[redirect_url]" class="regular-text">
            <option value="<?php echo esc_url(home_url()); ?>" <?php selected($redirect_url, home_url()); ?>>
                首頁
            </option>
            <?php foreach ($pages as $page): 
                $page_url = get_permalink($page->ID);
            ?>
            <option value="<?php echo esc_url($page_url); ?>" <?php selected($redirect_url, $page_url); ?>>
                <?php echo esc_html($page->post_title); ?>
            </option>
            <?php endforeach; ?>
            
            <?php 
            // 如果當前設置的URL不在上述選項中，添加自定義選項
            $found_match = false;
            if ($redirect_url === home_url()) {
                $found_match = true;
            } else {
                foreach ($pages as $page) {
                    if ($redirect_url === get_permalink($page->ID)) {
                        $found_match = true;
                        break;
                    }
                }
            }
            
            if (!$found_match && !empty($redirect_url)): ?>
            <option value="<?php echo esc_url($redirect_url); ?>" selected>
                自定義網址：<?php echo esc_url($redirect_url); ?>
            </option>
            <?php endif; ?>
        </select>
        
        <p class="description">選擇當訪客嘗試訪問被隱藏的登入頁面時要重定向的頁面。</p>
        
        <div style="margin-top: 10px;">
            <label>
                <input type="checkbox" id="wu_custom_redirect_toggle" /> 
                使用自定義網址
            </label>
            <br>
            <input type="url" id="wu_custom_redirect_url" placeholder="輸入自定義網址" class="regular-text" style="margin-top: 5px; display: none;" />
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $select = $('select[name="<?php echo $this->option_name; ?>[redirect_url]"]');
            var $toggle = $('#wu_custom_redirect_toggle');
            var $customInput = $('#wu_custom_redirect_url');
            
            // 切換自定義輸入
            $toggle.on('change', function() {
                if ($(this).is(':checked')) {
                    $customInput.show();
                    $select.hide();
                } else {
                    $customInput.hide();
                    $select.show();
                }
            });
            
            // 當輸入自定義網址時，更新選擇器
            $customInput.on('blur', function() {
                var customUrl = $(this).val();
                if (customUrl) {
                    // 檢查是否已存在該選項
                    var exists = false;
                    $select.find('option').each(function() {
                        if ($(this).val() === customUrl) {
                            exists = true;
                            return false;
                        }
                    });
                    
                    // 如果不存在，添加新選項
                    if (!exists) {
                        $select.append($('<option>', {
                            value: customUrl,
                            text: '自定義網址：' + customUrl,
                            selected: true
                        }));
                    } else {
                        $select.val(customUrl);
                    }
                }
                
                // 隱藏自定義輸入，顯示選擇器
                $toggle.prop('checked', false);
                $customInput.hide();
                $select.show();
            });
        });
        </script>
        <?php
    }
    
    /**
     * 清理選項
     */
    public function sanitize_options($input) {
        $sanitized = array();
        
        $sanitized['enabled'] = !empty($input['enabled']) ? true : false;
        $sanitized['custom_slug'] = !empty($input['custom_slug']) ? sanitize_text_field($input['custom_slug']) : 'loginwu';
        $sanitized['redirect_url'] = !empty($input['redirect_url']) ? esc_url_raw($input['redirect_url']) : home_url();
        
        // 確保custom_slug不為空
        if (empty($sanitized['custom_slug'])) {
            $sanitized['custom_slug'] = 'loginwu';
        }
        
        // 確保redirect_url不為空
        if (empty($sanitized['redirect_url'])) {
            $sanitized['redirect_url'] = home_url();
        }
        
        return $sanitized;
    }
    
    /**
     * 初始化隱藏登入功能
     */
    public function init_hide_login() {
        if (empty($this->options['enabled'])) {
            return;
        }
        
        // 添加自定義登入頁面
        add_action('init', array($this, 'add_custom_login_endpoint'));
    }
    
    /**
     * 添加自定義登入端點
     */
    public function add_custom_login_endpoint() {
        add_rewrite_rule(
            '^' . $this->options['custom_slug'] . '/?$',
            'index.php?pagename=wp-login',
            'top'
        );
        
        add_rewrite_rule(
            '^' . $this->options['custom_slug'] . '/wp-admin/?$',
            'index.php?pagename=wp-login&redirect_to=' . admin_url(),
            'top'
        );
        
        // 刷新重寫規則（僅在管理員頁面）
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wu-hide-login-page') {
            flush_rewrite_rules();
        }
    }
    
    /**
     * 重定向登入頁面
     */
    public function redirect_login_pages() {
        if (empty($this->options['enabled'])) {
            return;
        }
        
        // 如果用戶已登入，不執行重定向
        if (is_user_logged_in()) {
            return;
        }

        // 防護：避免影響 AJAX / Cron / REST 等特殊請求
        if ( ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) || ( defined('DOING_AJAX') && DOING_AJAX ) ) {
            return;
        }
        if ( function_exists('wp_is_json_request') && wp_is_json_request() ) {
            return;
        }
        if ( defined('XMLRPC_REQUEST') && XMLRPC_REQUEST ) {
            return;
        }
        // 額外保險：明確排除 admin-ajax / wp-cron / async-upload
        $req_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($req_uri, 'admin-ajax.php') !== false || strpos($req_uri, 'wp-cron.php') !== false || strpos($req_uri, 'async-upload.php') !== false ) {
            return;
        }
        // CORS 預檢請求
        if ( isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS' ) {
            return;
        }

        // 檢查是否與404重定向功能衝突
        if ($this->is_404_redirector_handling()) {
            return; // 讓404重定向功能處理
        }
        
        $current_url = $_SERVER['REQUEST_URI'];
        
        // 檢查是否訪問被隱藏的登入頁面
        if (strpos($current_url, '/wp-login.php') !== false || 
            strpos($current_url, '/wp-admin') !== false) {
            
            // 允許訪問自定義登入頁面
            if (strpos($current_url, '/' . $this->options['custom_slug']) !== false) {
                return;
            }
            
            // 重定向到設定的網址
            wp_redirect($this->options['redirect_url']);
            exit;
        }
    }
    
    /**
     * 檢查是否404重定向功能正在處理
     */
    private function is_404_redirector_handling() {
        // 檢查404重定向是否啟用
        $redirect_404_enabled = get_option('wu_enable_404_redirect', false);
        
        if (!$redirect_404_enabled) {
            return false;
        }
        
        // 如果是404頁面且404重定向啟用，則不執行隱藏登入重定向
        // 避免重定向衝突
        if (is_404()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 修改登入URL
     */
    public function modify_login_url($url, $path = '', $scheme = null, $blog_id = null) {
        if (empty($this->options['enabled'])) {
            return $url;
        }
        
        if ($path === 'wp-login.php') {
            return home_url('/' . $this->options['custom_slug'] . '/');
        }
        
        return $url;
    }
    
    /**
     * 修改重定向URL
     */
    public function modify_redirect_url($location, $status) {
        if (empty($this->options['enabled'])) {
            return $location;
        }
        
        // 如果重定向到wp-login.php，改為自定義登入頁面
        if (strpos($location, 'wp-login.php') !== false) {
            $location = str_replace('wp-login.php', $this->options['custom_slug'], $location);
        }
        
        return $location;
    }
}

// 初始化類別
new WU_Hide_Login_Page();

// 添加重寫標籤
function wu_hide_login_query_vars($vars) {
    $vars[] = 'pagename';
    return $vars;
}
add_filter('query_vars', 'wu_hide_login_query_vars');

// 處理自定義登入頁面 - 完整修復版本
function wu_hide_login_template_redirect() {
    $options = get_option('wu_hide_login_page_options', array());
    
    if (empty($options['enabled'])) {
        return;
    }
    
    $custom_slug = !empty($options['custom_slug']) ? $options['custom_slug'] : 'loginwu';
    
    if (strpos($_SERVER['REQUEST_URI'], '/' . $custom_slug) !== false) {
        // 完整的變數初始化
        global $error, $interim_login, $action, $user_login, $user_email, 
               $redirect_to, $rememberme, $user_id, $errors;
        
        // WordPress 登入頁面需要的所有變數
        $error = '';
        $errors = new WP_Error();
        $interim_login = isset($_REQUEST['interim-login']);
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
        $user_login = isset($_POST['log']) ? wp_unslash($_POST['log']) : '';
        $user_email = isset($_POST['user_email']) ? wp_unslash($_POST['user_email']) : '';
        $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url();
        $rememberme = !empty($_POST['rememberme']);
        $user_id = 0;
        
        // 模擬正常的 wp-login.php 請求
        $_SERVER['REQUEST_URI'] = str_replace('/' . $custom_slug, '/wp-login.php', $_SERVER['REQUEST_URI']);
        
        // 載入WordPress登入頁面
        require_once(ABSPATH . 'wp-login.php');
        exit;
    }
}
add_action('template_redirect', 'wu_hide_login_template_redirect');
