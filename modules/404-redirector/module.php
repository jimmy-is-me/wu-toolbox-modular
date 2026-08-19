<?php
/**
 * Module: 404-redirector
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 404 錯誤重新導向模組
 * 檔案名稱：common-404-redirector.php（前台+後台都載入）
 * 功能：將 404 錯誤透明地重新導向到網站主頁或自訂頁面
 */

if (!defined('ABSPATH')) exit;

class WU_404_Redirector {
    
    /**
     * 選項前綴
     */
    private $option_prefix = 'wu_404_redirect_';
    
    /**
     * 設定群組名稱
     */
    private $settings_group = 'wu_404_redirect_settings';
    
    /**
     * 是否已發送重定向標記（防止重複重定向）
     */
    private static $redirect_sent = false;
    
    public function __construct() {
        // 後台：載入管理介面
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'), 20);
            add_action('admin_init', array($this, 'admin_init'));
        }
        
        // 前台：如果啟用了 404 重新導向，則執行相關動作
        if (!is_admin() && $this->get_option('enabled', false)) {
            add_action('template_redirect', array($this, 'handle_404_redirect'), 1);
        }
    }
    
    /**
     * 取得選項值（帶預設值，避免 PHP notice）
     * 
     * @param string $key 選項鍵名
     * @param mixed $default 預設值
     * @return mixed
     */
    private function get_option($key, $default = '') {
        return get_option($this->option_prefix . $key, $default);
    }
    
    /**
     * 更新選項值
     * 
     * @param string $key 選項鍵名
     * @param mixed $value 選項值
     * @return bool
     */
    private function update_option($key, $value) {
        return update_option($this->option_prefix . $key, $value);
    }
    
    // ========================================
    // 後台管理介面相關方法
    // ========================================
    
    /**
     * 添加管理子選單頁面
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wumetax-toolkit',
            '404重新導向',
            '404重新導向',
            'manage_options',
            'wu-404-redirector',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * 初始化設定 API
     */
    public function admin_init() {
        // 註冊設定
        register_setting($this->settings_group, $this->option_prefix . 'enabled', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => array($this, 'sanitize_boolean')
        ));
        
        register_setting($this->settings_group, $this->option_prefix . 'type', array(
            'type' => 'string',
            'default' => 'homepage',
            'sanitize_callback' => array($this, 'sanitize_redirect_type')
        ));
        
        register_setting($this->settings_group, $this->option_prefix . 'custom_url', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'esc_url_raw'
        ));
        
        register_setting($this->settings_group, $this->option_prefix . 'status', array(
            'type' => 'integer',
            'default' => 301,
            'sanitize_callback' => array($this, 'sanitize_redirect_status')
        ));
        
        register_setting($this->settings_group, $this->option_prefix . 'log_limit', array(
            'type' => 'integer',
            'default' => 20,
            'sanitize_callback' => array($this, 'sanitize_log_limit')
        ));
        
        // 添加設定區塊
        add_settings_section(
            'wu_404_redirect_main_section',
            '404 錯誤重新導向設定',
            array($this, 'render_settings_section'),
            $this->settings_group
        );
        
        // 添加設定欄位
        add_settings_field(
            'wu_404_redirect_enabled',
            '啟用 404 重新導向',
            array($this, 'render_enable_field'),
            $this->settings_group,
            'wu_404_redirect_main_section'
        );
        
        add_settings_field(
            'wu_404_redirect_type',
            '重新導向目標',
            array($this, 'render_type_field'),
            $this->settings_group,
            'wu_404_redirect_main_section'
        );
        
        add_settings_field(
            'wu_404_redirect_custom_url',
            '自訂 URL',
            array($this, 'render_custom_url_field'),
            $this->settings_group,
            'wu_404_redirect_main_section'
        );
        
        add_settings_field(
            'wu_404_redirect_status',
            '重新導向類型',
            array($this, 'render_status_field'),
            $this->settings_group,
            'wu_404_redirect_main_section'
        );
        
        add_settings_field(
            'wu_404_redirect_log_limit',
            '日誌保留數量',
            array($this, 'render_log_limit_field'),
            $this->settings_group,
            'wu_404_redirect_main_section'
        );
    }
    
    /**
     * 清理布林值
     */
    public function sanitize_boolean($value) {
        return (bool) $value;
    }
    
    /**
     * 清理重新導向類型
     */
    public function sanitize_redirect_type($value) {
        $allowed = array('homepage', 'custom');
        return in_array($value, $allowed) ? $value : 'homepage';
    }
    
    /**
     * 清理重新導向狀態碼
     */
    public function sanitize_redirect_status($value) {
        $value = absint($value);
        return in_array($value, array(301, 302)) ? $value : 301;
    }
    
    /**
     * 清理日誌限制數量
     */
    public function sanitize_log_limit($value) {
        $value = absint($value);
        return ($value >= 0 && $value <= 100) ? $value : 20;
    }
    
    /**
     * 渲染設定區塊說明
     */
    public function render_settings_section() {
        echo '<p class="wu-404-description">404 錯誤重新導向功能可以自動將訪客從不存在的頁面重新導向到指定頁面，改善用戶體驗並減少跳出率。</p>';
        echo '<p class="wu-404-description"><strong>建議：</strong>啟用此功能可以避免訪客看到令人困惑的 404 錯誤頁面。</p>';
    }
    
    /**
     * 渲染啟用欄位
     */
    public function render_enable_field() {
        $value = $this->get_option('enabled', false);
        ?>
        <label for="wu_404_redirect_enabled">
            <input type="checkbox" 
                   id="wu_404_redirect_enabled" 
                   name="<?php echo esc_attr($this->option_prefix . 'enabled'); ?>" 
                   value="1" 
                   <?php checked(1, $value); ?> />
            啟用 404 錯誤自動重新導向
        </label>
        <p class="description">當檢測到 404 錯誤時，自動將訪客重新導向到指定頁面。</p>
        <?php
    }
    
    /**
     * 渲染重新導向類型欄位
     */
    public function render_type_field() {
        $value = $this->get_option('type', 'homepage');
        ?>
        <select id="wu_404_redirect_type" 
                name="<?php echo esc_attr($this->option_prefix . 'type'); ?>" 
                class="wu-404-redirect-type-select">
            <option value="homepage" <?php selected('homepage', $value); ?>>網站首頁</option>
            <option value="custom" <?php selected('custom', $value); ?>>自訂 URL</option>
        </select>
        <p class="description">選擇 404 錯誤的重新導向目標。</p>
        <?php
    }
    
    /**
     * 渲染自訂 URL 欄位
     */
    public function render_custom_url_field() {
        $value = $this->get_option('custom_url', '');
        $is_invalid = !empty($value) && !filter_var($value, FILTER_VALIDATE_URL);
        ?>
        <input type="url" 
               id="wu_404_redirect_custom_url" 
               name="<?php echo esc_attr($this->option_prefix . 'custom_url'); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text wu-404-custom-url-field" 
               placeholder="https://example.com/page" />
        <?php if ($is_invalid): ?>
        <p class="description wu-404-error">
            <strong>⚠️ 警告：</strong>目前設定的自訂 URL 格式無效，系統將自動使用首頁作為重新導向目標。請修正 URL 格式。
        </p>
        <?php endif; ?>
        <p class="description">當選擇自訂 URL 時，請輸入完整的 URL 地址（包含 http:// 或 https://）。</p>
        <?php
    }
    
    /**
     * 渲染重新導向狀態碼欄位
     */
    public function render_status_field() {
        $value = $this->get_option('status', 301);
        ?>
        <select id="wu_404_redirect_status" 
                name="<?php echo esc_attr($this->option_prefix . 'status'); ?>">
            <option value="301" <?php selected(301, $value); ?>>301 - 永久重新導向（推薦）</option>
            <option value="302" <?php selected(302, $value); ?>>302 - 臨時重新導向</option>
        </select>
        <p class="description">
            <strong>301</strong> 適用於永久性變更，會轉移 SEO 權重；<strong>302</strong> 適用於臨時性變更，不轉移權重。
        </p>
        <?php
    }
    
    /**
     * 渲染日誌限制欄位
     */
    public function render_log_limit_field() {
        $value = $this->get_option('log_limit', 20);
        ?>
        <input type="number" 
               id="wu_404_redirect_log_limit" 
               name="<?php echo esc_attr($this->option_prefix . 'log_limit'); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               min="0" 
               max="100" 
               class="small-text" />
        <p class="description">
            設定保留的 404 錯誤日誌數量（0-100 條）。設為 0 則不記錄日誌。<br>
            較少的日誌數量可減少資料庫寫入次數，提升網站效能。
        </p>
        <?php
    }
    
    /**
     * 渲染管理頁面
     */
    public function render_admin_page() {
        // 檢查權限
        if (!current_user_can('manage_options')) {
            wp_die(__('您沒有權限訪問此頁面。'));
        }
        
        $redirect_enabled = $this->get_option('enabled', false);
        $redirect_type = $this->get_option('type', 'homepage');
        $redirect_status = $this->get_option('status', 301);
        $custom_url = $this->get_option('custom_url', '');
        $log_limit = $this->get_option('log_limit', 20);
        
        // 獲取重新導向目標 URL
        $redirect_url = $this->get_redirect_url();
        
        ?>
        <div class="wrap wu-404-redirector-wrap">
            <h1>404 錯誤重新導向設定</h1>
            
            <div class="wu-404-status-card">
                <h2>當前狀態</h2>
                <p>
                    <strong>404 重新導向：</strong> 
                    <span class="wu-404-status-badge <?php echo $redirect_enabled ? 'wu-404-enabled' : 'wu-404-disabled'; ?>">
                        <?php echo $redirect_enabled ? '✓ 已啟用' : '✗ 已停用'; ?>
                    </span>
                </p>
                <?php if ($redirect_enabled && !empty($redirect_url)): ?>
                <p>
                    <strong>重新導向目標：</strong> 
                    <a href="<?php echo esc_url($redirect_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($redirect_url); ?>
                    </a>
                </p>
                <p>
                    <strong>重新導向類型：</strong> 
                    <code><?php echo esc_html($redirect_status); ?></code>
                    <?php echo $redirect_status === 301 ? '(永久重新導向)' : '(臨時重新導向)'; ?>
                </p>
                <?php elseif ($redirect_enabled && empty($redirect_url)): ?>
                <p class="wu-404-warning">
                    ⚠️ <strong>警告：</strong>重新導向已啟用但目標 URL 無效，功能將無法正常運作。
                </p>
                <?php endif; ?>
            </div>
            
            <form method="post" action="options.php" class="wu-404-settings-form">
                <?php
                settings_fields($this->settings_group);
                do_settings_sections($this->settings_group);
                submit_button('儲存設定');
                ?>
            </form>
            
            <?php if ($log_limit > 0): ?>
            <div class="wu-404-log-card">
                <h2>近期 404 錯誤記錄（最多 <?php echo esc_html($log_limit); ?> 條）</h2>
                <?php $this->render_error_log(); ?>
            </div>
            <?php endif; ?>
            
            <div class="wu-404-info-card">
                <h2>功能說明</h2>
                
                <h3>什麼是 404 錯誤？</h3>
                <ul>
                    <li>404 錯誤表示請求的頁面或資源不存在</li>
                    <li>常見原因：連結錯誤、頁面已刪除、URL 拼寫錯誤</li>
                    <li>預設會顯示 404 錯誤頁面</li>
                </ul>
                
                <h3>為什麼要重新導向？</h3>
                <ul>
                    <li><strong>改善用戶體驗：</strong>避免訪客看到令人困惑的錯誤頁面</li>
                    <li><strong>減少跳出率：</strong>將訪客引導到有用的內容</li>
                    <li><strong>SEO 優化：</strong>減少 404 錯誤對搜尋引擎排名的負面影響</li>
                    <li><strong>保持流量：</strong>將迷路的訪客重新導向到主要頁面</li>
                </ul>
                
                <h3>301 vs 302 重新導向</h3>
                <table class="wu-404-comparison-table">
                    <thead>
                        <tr>
                            <th>類型</th>
                            <th>用途</th>
                            <th>SEO 權重</th>
                            <th>適用場景</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>301</strong></td>
                            <td>永久重新導向</td>
                            <td>✓ 轉移權重</td>
                            <td>頁面永久移除、網站改版</td>
                        </tr>
                        <tr>
                            <td><strong>302</strong></td>
                            <td>臨時重新導向</td>
                            <td>✗ 保留原權重</td>
                            <td>頁面維護、A/B 測試</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>注意事項</h3>
                <ul>
                    <li>⚠️ 自訂 URL 必須是有效的完整網址，否則將自動使用首頁</li>
                    <li>⚠️ 請勿將目標 URL 設為會產生 404 的頁面，避免無限重新導向</li>
                    <li>⚠️ 與「隱藏登入頁面」功能相容，不會影響登入相關頁面</li>
                    <li>💡 建議定期檢查 404 日誌，修復常見的連結問題</li>
                    <li>💡 對於重要的已刪除頁面，建議使用專門的重新導向外掛設定個別規則</li>
                </ul>
            </div>
        </div>
        
        <style>
        .wu-404-redirector-wrap { max-width: 1200px; }
        .wu-404-status-card,
        .wu-404-log-card,
        .wu-404-info-card { 
            background: #fff; 
            border: 1px solid #ccd0d4; 
            border-left: 4px solid #2271b1;
            padding: 20px; 
            margin: 20px 0;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .wu-404-status-card h2,
        .wu-404-log-card h2,
        .wu-404-info-card h2 { margin-top: 0; color: #1d2327; }
        .wu-404-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 13px;
        }
        .wu-404-enabled { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .wu-404-disabled { 
            background: #f8d7da; 
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .wu-404-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            margin: 10px 0;
        }
        .wu-404-error { color: #d63638; font-weight: 600; }
        .wu-404-description { color: #50575e; }
        .wu-404-info-card h3 { 
            color: #1d2327; 
            margin-top: 20px;
            font-size: 16px;
        }
        .wu-404-info-card ul { 
            margin-left: 20px;
            line-height: 1.8;
        }
        .wu-404-comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .wu-404-comparison-table th,
        .wu-404-comparison-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .wu-404-comparison-table th {
            background: #f0f0f1;
            font-weight: 600;
        }
        .wu-404-comparison-table tbody tr:hover {
            background: #f6f7f7;
        }
        .wu-404-log-table {
            width: 100%;
            border-collapse: collapse;
        }
        .wu-404-log-table th,
        .wu-404-log-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .wu-404-log-table th {
            background: #f0f0f1;
            font-weight: 600;
        }
        .wu-404-log-table tr:hover {
            background: #f6f7f7;
        }
        .wu-404-log-empty {
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
        </style>
        
        <script>
        (function($) {
            'use strict';
            
            $(document).ready(function() {
                var $typeSelect = $('#wu_404_redirect_type');
                var $customUrlRow = $('#wu_404_redirect_custom_url').closest('tr');
                
                function toggleCustomUrlField() {
                    if ($typeSelect.val() === 'custom') {
                        $customUrlRow.show();
                    } else {
                        $customUrlRow.hide();
                    }
                }
                
                // 初始化顯示狀態
                toggleCustomUrlField();
                
                // 監聽變更事件
                $typeSelect.on('change', toggleCustomUrlField);
            });
        })(jQuery);
        </script>
        <?php
    }
    
    /**
     * 渲染錯誤日誌
     */
    private function render_error_log() {
        $log_entries = get_option('wu_404_log', array());
        
        if (empty($log_entries)) {
            echo '<p class="wu-404-log-empty">目前沒有 404 錯誤記錄。</p>';
            return;
        }
        
        echo '<table class="wu-404-log-table">';
        echo '<thead><tr>';
        echo '<th>時間</th>';
        echo '<th>請求 URL</th>';
        echo '<th>來源 IP</th>';
        echo '<th>來源頁面</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($log_entries as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html($entry['time']) . '</td>';
            echo '<td><code>' . esc_html($entry['url']) . '</code></td>';
            echo '<td>' . esc_html($entry['ip']) . '</td>';
            echo '<td>' . (empty($entry['referer']) ? '-' : '<a href="' . esc_url($entry['referer']) . '" target="_blank" rel="noopener">' . esc_html($entry['referer']) . '</a>') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // ========================================
    // 前台 404 重新導向執行邏輯
    // ========================================
    
    /**
     * 處理 404 重新導向
     */
    public function handle_404_redirect() {
        // 防止重複重定向
        if (self::$redirect_sent) {
            return;
        }
        
        // 增強的後台和特殊環境檢查
        if ($this->should_skip_redirect()) {
            return;
        }
        
        // 檢查是否為 404 頁面
        if (!is_404()) {
            return;
        }
        
        // 檢查 headers 是否已發送（避免 "headers already sent" 錯誤）
        if (headers_sent()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WU_404_Redirector: 無法執行重新導向，headers 已發送');
            }
            return;
        }
        
        // 取得重新導向 URL
        $redirect_url = $this->get_redirect_url();
        
        if (empty($redirect_url)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WU_404_Redirector: 重新導向 URL 無效');
            }
            return;
        }
        
        // 防止無限重新導向：檢查目標 URL 是否與當前 URL 相同
        $current_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $current_url = home_url($current_uri);
        if (untrailingslashit($redirect_url) === untrailingslashit($current_url)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WU_404_Redirector: 偵測到循環重新導向，已阻止');
            }
            return;
        }
        
        // 記錄 404 錯誤（如果啟用）
        $log_limit = $this->get_option('log_limit', 20);
        if ($log_limit > 0) {
            $this->log_404_error($log_limit);
        }
        
        // 標記為已發送
        self::$redirect_sent = true;
        
        // 執行重新導向
        $redirect_status = $this->get_option('status', 301);
        wp_redirect($redirect_url, $redirect_status);
        exit;
    }
    
    /**
     * 檢查是否應該跳過重新導向（包含後台、AJAX、CRON 和登入頁面）
     * 
     * @return bool 如果應該跳過重新導向則返回 true
     */
    private function should_skip_redirect() {
        // 檢查後台環境
        if (is_admin()) {
            return true;
        }
        
        // 檢查 AJAX 請求
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }
        
        // 檢查 CRON 任務
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        
        // 檢查 REST API 請求
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        
        // 檢查 XML-RPC 請求
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }
        
        // 安全取得 REQUEST_URI
        $current_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (empty($current_uri)) {
            return false;
        }
        
        // 移除查詢字串，只檢查路徑
        $current_path = strtok($current_uri, '?');
        
        // 檢查是否為後台相關路徑
        if (strpos($current_path, '/wp-admin/') !== false || 
            strpos($current_path, '/wp-login.php') !== false) {
            return true;
        }
        
        // 檢查是否與隱藏登入頁面功能衝突
        if ($this->is_hide_login_conflict()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 取得重新導向 URL
     * 
     * @return string 重新導向的目標 URL，無效時返回空字串
     */
    private function get_redirect_url() {
        $redirect_type = $this->get_option('type', 'homepage');
        $redirect_url = '';
        
        if ($redirect_type === 'homepage') {
            $redirect_url = home_url('/');
        } elseif ($redirect_type === 'custom') {
            $custom_url = $this->get_option('custom_url', '');
            
            // 驗證自訂 URL
            if (!empty($custom_url) && filter_var($custom_url, FILTER_VALIDATE_URL)) {
                $redirect_url = $custom_url;
            } else {
                // 自訂 URL 無效，回退到首頁
                $redirect_url = home_url('/');
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WU_404_Redirector: 自訂 URL 無效，已回退到首頁 - ' . $custom_url);
                }
            }
        }
        
        return $redirect_url;
    }
    
    /**
     * 檢查是否與隱藏登入頁面功能衝突
     * 使用更精確的路徑比對，避免誤判
     * 
     * @return bool
     */
    private function is_hide_login_conflict() {
        // 獲取隱藏登入頁面的設定
        $hide_login_options = get_option('wu_hide_login_page_options', array());
        
        // 如果隱藏登入頁面功能未啟用，沒有衝突
        if (empty($hide_login_options['enabled'])) {
            return false;
        }
        
        // 安全取得 REQUEST_URI（避免 undefined index notice）
        $current_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (empty($current_uri)) {
            return false;
        }
        
        // 移除查詢字串，只比對路徑
        $current_path = strtok($current_uri, '?');
        $current_path = untrailingslashit($current_path);
        
        // 取得自訂登入 slug
        $custom_slug = isset($hide_login_options['custom_slug']) ? $hide_login_options['custom_slug'] : 'loginwu';
        
        // 定義登入相關的精確路徑（使用精確匹配，避免誤判）
        $login_paths = array(
            '/wp-login.php',
            '/wp-admin',
            '/' . $custom_slug,
        );
        
        // 精確比對路徑
        foreach ($login_paths as $login_path) {
            // 使用 === 進行精確比對，避免 strpos 的寬鬆匹配
            if ($current_path === $login_path) {
                return true;
            }
            
            // 檢查是否以 /wp-admin/ 開頭（但排除 admin-ajax.php）
            if ($login_path === '/wp-admin' && 
                strpos($current_path, '/wp-admin/') === 0 && 
                strpos($current_path, 'admin-ajax.php') === false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 記錄 404 錯誤
     * 
     * @param int $limit 日誌保留數量上限
     */
    private function log_404_error($limit = 20) {
        // 安全取得 $_SERVER 變數（避免 undefined index notice）
        $requested_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        
        // 如果沒有請求 URL，不記錄
        if (empty($requested_url)) {
            return;
        }
        
        // 取得現有日誌
        $log_entries = get_option('wu_404_log', array());
        
        // 建立新的日誌條目
        $new_entry = array(
            'url' => $requested_url,
            'time' => current_time('mysql'),
            'ip' => $ip_address,
            'user_agent' => $user_agent,
            'referer' => $referer
        );
        
        // 添加到陣列開頭
        array_unshift($log_entries, $new_entry);
        
        // 限制日誌數量
        if (count($log_entries) > $limit) {
            $log_entries = array_slice($log_entries, 0, $limit);
        }
        
        // 更新日誌（使用 autoload = no 減少資料庫查詢負擔）
        update_option('wu_404_log', $log_entries, false);
    }
}

// 初始化模組
new WU_404_Redirector();
