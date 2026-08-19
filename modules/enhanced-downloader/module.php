<?php
/**
 * Module: enhanced-downloader
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * 增強下載器模組
 * 功能：在外掛程式和主題管理頁面添加下載按鈕
 */

if (!defined('ABSPATH')) exit;

class WU_Enhanced_Downloader {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 40);
        add_action('admin_init', array($this, 'admin_init'));
        
        // 如果啟用了下載功能，則添加下載按鈕
        if (get_option('wu_enable_plugin_downloader', false)) {
            $this->enable_plugin_downloader();
        }
        
        if (get_option('wu_enable_theme_downloader', false)) {
            $this->enable_theme_downloader();
        }
        
        // 處理下載請求
        add_action('admin_post_wu_download_plugin', array($this, 'download_plugin'));
        add_action('admin_post_wu_download_theme', array($this, 'download_theme'));
    }
    
    /**
     * 添加管理頁面
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wumetax-toolkit',
            '檔案下載功能',
            '檔案下載功能',
            'manage_options',
            'wu-enhanced-downloader',
            array($this, 'admin_page')
        );
    }
    
    /**
     * 初始化設定
     */
    public function admin_init() {
        register_setting('wu_downloader_settings', 'wu_enable_plugin_downloader');
        register_setting('wu_downloader_settings', 'wu_enable_theme_downloader');
        
        add_settings_section(
            'wu_downloader_section',
            '增強下載器設定',
            array($this, 'settings_section_callback'),
            'wu_downloader_settings'
        );
        
        add_settings_field(
            'wu_enable_plugin_downloader',
            '外掛程式下載器',
            array($this, 'plugin_downloader_callback'),
            'wu_downloader_settings',
            'wu_downloader_section'
        );
        
        add_settings_field(
            'wu_enable_theme_downloader',
            '主題下載器',
            array($this, 'theme_downloader_callback'),
            'wu_downloader_settings',
            'wu_downloader_section'
        );
    }
    
    /**
     * 設定區域說明
     */
    public function settings_section_callback() {
        echo '<p>增強下載器功能可以在外掛程式和主題管理頁面添加下載按鈕，讓您輕鬆備份外掛程式和主題。</p>';
        echo '<p><strong>建議：</strong>啟用此功能可以方便地創建外掛程式和主題的備份檔案。</p>';
    }
    
    /**
     * 外掛程式下載器選項回調
     */
    public function plugin_downloader_callback() {
        $value = get_option('wu_enable_plugin_downloader', false);
        echo '<input type="checkbox" id="wu_enable_plugin_downloader" name="wu_enable_plugin_downloader" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_enable_plugin_downloader">在外掛程式管理頁面添加下載按鈕</label>';
        echo '<p class="description">在每個外掛程式旁邊添加下載按鈕，可以將外掛程式打包為 ZIP 檔案下載。</p>';
    }
    
    /**
     * 主題下載器選項回調
     */
    public function theme_downloader_callback() {
        $value = get_option('wu_enable_theme_downloader', false);
        echo '<input type="checkbox" id="wu_enable_theme_downloader" name="wu_enable_theme_downloader" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_enable_theme_downloader">在下列清單直接下載</label>';
        echo '<p class="description">在下方清單中每個主題旁邊添加下載按鈕，可以將主題打包為 ZIP 檔案下載。</p>';
    }
    
    /**
     * 管理頁面
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('wu_downloader_settings-options');
            
            // 處理表單提交
            update_option('wu_enable_plugin_downloader', isset($_POST['wu_enable_plugin_downloader']) ? 1 : 0);
            update_option('wu_enable_theme_downloader', isset($_POST['wu_enable_theme_downloader']) ? 1 : 0);
            
            echo '<div class="notice notice-success"><p>設定已儲存！</p></div>';
        }
        
        $plugin_count = count(get_plugins());
        $theme_count = count(wp_get_themes());
        $theme_downloader_enabled = get_option('wu_enable_theme_downloader', false);
        ?>
        <div class="wrap">
            <h1>增強下載器設定</h1>
            
            <div class="card">
                <h2>當前狀態</h2>
                <p><strong>已安裝外掛程式：</strong> <?php echo $plugin_count; ?> 個</p>
                <p><strong>已安裝主題：</strong> <?php echo $theme_count; ?> 個</p>
                <p><strong>外掛程式下載器：</strong> 
                    <span class="<?php echo get_option('wu_enable_plugin_downloader', false) ? 'wu-status-enabled' : 'wu-status-disabled'; ?>">
                        <?php echo get_option('wu_enable_plugin_downloader', false) ? '已啟用' : '已禁用'; ?>
                    </span>
                </p>
                <p><strong>主題下載器：</strong> 
                    <span class="<?php echo $theme_downloader_enabled ? 'wu-status-enabled' : 'wu-status-disabled'; ?>">
                        <?php echo $theme_downloader_enabled ? '已啟用' : '已禁用'; ?>
                    </span>
                </p>
            </div>
            
            <form method="post" action="">
                <?php
                settings_fields('wu_downloader_settings');
                do_settings_sections('wu_downloader_settings');
                wp_nonce_field('wu_downloader_settings-options');
                submit_button();
                ?>
            </form>
            
            <?php 
            // 如果主題下載器已啟用，顯示可供下載的主題清單
            if ($theme_downloader_enabled) {
                $this->display_downloadable_themes();
            }
            ?>
            
            <div class="card">
                <h2>功能說明</h2>
                <h3>什麼是增強下載器？</h3>
                <ul>
                    <li>在 WordPress 管理界面的外掛程式和主題頁面添加下載按鈕</li>
                    <li>可以將任何已安裝的外掛程式或主題打包為 ZIP 檔案</li>
                    <li>方便創建備份或遷移到其他網站</li>
                </ul>
                
                <h3>使用場景</h3>
                <ul>
                    <li><strong>網站備份：</strong>定期備份重要的外掛程式和主題</li>
                    <li><strong>網站遷移：</strong>將自定義外掛程式和主題遷移到新網站</li>
                    <li><strong>版本控制：</strong>保存特定版本的外掛程式和主題</li>
                    <li><strong>開發測試：</strong>在不同環境間傳輸開發版本</li>
                </ul>
                
                <h3>技術特點</h3>
                <ul>
                    <li><strong>安全性：</strong>使用 WordPress nonce 驗證確保安全</li>
                    <li><strong>效能優化：</strong>使用 PHP ZipArchive 進行高效壓縮</li>
                    <li><strong>錯誤處理：</strong>完整的錯誤檢測和用戶反饋</li>
                    <li><strong>權限控制：</strong>僅管理員可以使用下載功能</li>
                </ul>
                
                <h3>使用方法</h3>
                <ol>
                    <li>啟用所需的下載器功能</li>
                    <li>前往「外掛程式」或「外觀 > 主題」頁面</li>
                    <li>在每個項目旁邊找到「下載」按鈕</li>
                    <li>點擊下載按鈕即可獲得 ZIP 檔案</li>
                </ol>
                
                <h3>注意事項</h3>
                <ul>
                    <li>下載功能需要伺服器支援 ZipArchive 擴展</li>
                    <li>大型外掛程式或主題可能需要較長的處理時間</li>
                    <li>確保有足夠的磁碟空間用於臨時檔案</li>
                    <li>下載的檔案包含完整的原始碼，請妥善保管</li>
                </ul>
            </div>
        </div>
        
        <style>
        .wu-status-enabled { color: #00a32a; font-weight: bold; }
        .wu-status-disabled { color: #d63638; font-weight: bold; }
        .card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; }
        .card h2 { margin-top: 0; }
        .card h3 { color: #23282d; }
        .card ul, .card ol { margin-left: 20px; }
        .wu-theme-list { margin: 20px 0; }
        .wu-theme-item { 
            display: flex; 
            align-items: center; 
            padding: 15px; 
            border: 1px solid #ddd; 
            margin-bottom: 10px; 
            background: #fff;
            border-radius: 5px;
        }
        .wu-theme-screenshot { 
            width: 100px; 
            height: 75px; 
            margin-right: 15px; 
            border: 1px solid #ddd;
            overflow: hidden;
            border-radius: 3px;
        }
        .wu-theme-screenshot img { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
        }
        .wu-theme-info { 
            flex: 1; 
        }
        .wu-theme-name { 
            font-size: 16px; 
            font-weight: bold; 
            margin: 0 0 5px 0; 
        }
        .wu-theme-description { 
            color: #666; 
            font-size: 13px; 
            margin: 0 0 5px 0; 
            line-height: 1.4;
        }
        .wu-theme-meta { 
            font-size: 12px; 
            color: #888; 
        }
        .wu-theme-actions { 
            margin-left: 15px; 
        }
        .wu-current-theme { 
            background: #f0f8ff; 
            border-color: #0073aa; 
        }
        .wu-current-badge {
            background: #0073aa;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 10px;
        }
        .wu-no-screenshot {
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 12px;
        }
        </style>
        <?php
    }
    
    /**
     * 顯示可供下載的主題清單
     */
    private function display_downloadable_themes() {
        $themes = wp_get_themes();
        $current_theme = get_stylesheet();
        
        if (empty($themes)) {
            return;
        }
        ?>
        <div class="card wu-theme-list">
            <h2>可供下載的佈景主題清單</h2>
            <p class="description">以下是目前已安裝的所有佈景主題，您可以點擊下載按鈕來備份任何主題。</p>
            
            <?php foreach ($themes as $theme_slug => $theme): ?>
            <div class="wu-theme-item <?php echo ($theme_slug === $current_theme) ? 'wu-current-theme' : ''; ?>">
                <div class="wu-theme-screenshot">
                    <?php if ($theme->get_screenshot()): ?>
                        <img src="<?php echo esc_url($theme->get_screenshot()); ?>" alt="<?php echo esc_attr($theme->get('Name')); ?>" />
                    <?php else: ?>
                        <div class="wu-no-screenshot">無預覽圖</div>
                    <?php endif; ?>
                </div>
                
                <div class="wu-theme-info">
                    <div class="wu-theme-name">
                        <?php echo esc_html($theme->get('Name')); ?>
                        <?php if ($theme_slug === $current_theme): ?>
                            <span class="wu-current-badge">使用中</span>
                        <?php endif; ?>
                    </div>
                    <div class="wu-theme-description">
                        <?php echo esc_html(wp_trim_words($theme->get('Description'), 20)); ?>
                    </div>
                    <div class="wu-theme-meta">
                        <strong>版本：</strong><?php echo esc_html($theme->get('Version')); ?> |
                        <strong>作者：</strong><?php echo wp_kses_post($theme->get('Author')); ?> |
                        <strong>資料夾：</strong><?php echo esc_html($theme_slug); ?>
                    </div>
                </div>
                
                <div class="wu-theme-actions">
                    <?php
                    $download_url = wp_nonce_url(
                        admin_url('admin-post.php?action=wu_download_theme&theme=' . urlencode($theme_slug)),
                        'wu_download_theme_' . $theme_slug
                    );
                    ?>
                    <a href="<?php echo esc_url($download_url); ?>" 
                       class="button button-primary"
                       onclick="return confirm('確定要下載主題「<?php echo esc_js($theme->get('Name')); ?>」嗎？');"
                       title="下載 <?php echo esc_attr($theme->get('Name')); ?>">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 5px;"></span>
                        下載主題
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <p class="description" style="margin-top: 15px;">
                <strong>提示：</strong>下載的檔案將會是完整的主題資料夾壓縮包，包含所有主題檔案。
                您也可以直接在「外觀 > 主題」頁面中點擊各主題的下載按鈕。
            </p>
        </div>
        <?php
    }
    
    /**
     * 啟用外掛程式下載器
     */
    private function enable_plugin_downloader() {
        add_filter('plugin_action_links', array($this, 'add_plugin_download_link'), 10, 2);
        add_action('admin_head-plugins.php', array($this, 'add_download_styles'));
    }
    
    /**
     * 啟用主題下載器
     */
    private function enable_theme_downloader() {
        add_filter('theme_action_links', array($this, 'add_theme_download_link'), 10, 2);
        add_action('admin_head-themes.php', array($this, 'add_download_styles'));
    }
    
    /**
     * 添加外掛程式下載連結
     */
    public function add_plugin_download_link($actions, $plugin_file) {
        if (current_user_can('manage_options')) {
            $download_url = wp_nonce_url(
                admin_url('admin-post.php?action=wu_download_plugin&plugin=' . urlencode($plugin_file)),
                'wu_download_plugin_' . $plugin_file
            );
            
            $actions['wu_download'] = '<a href="' . esc_url($download_url) . '" class="wu-download-link" title="下載此外掛程式">下載</a>';
        }
        
        return $actions;
    }
    
    /**
     * 添加主題下載連結
     */
    public function add_theme_download_link($actions, $theme) {
        if (current_user_can('manage_options')) {
            $theme_slug = $theme->get_stylesheet();
            $download_url = wp_nonce_url(
                admin_url('admin-post.php?action=wu_download_theme&theme=' . urlencode($theme_slug)),
                'wu_download_theme_' . $theme_slug
            );
            
            $actions['wu_download'] = '<a href="' . esc_url($download_url) . '" class="wu-download-link" title="下載此主題">下載</a>';
        }
        
        return $actions;
    }
    
    /**
     * 添加下載按鈕樣式
     */
    public function add_download_styles() {
        echo '<style>
        .wu-download-link {
            color: #0073aa !important;
            text-decoration: none;
        }
        .wu-download-link:hover {
            color: #005a87 !important;
        }
        .wu-download-link:before {
            content: "\f316";
            font-family: dashicons;
            margin-right: 5px;
        }
        </style>';
    }
    
    /**
     * 下載外掛程式
     */
    public function download_plugin() {
        if (!current_user_can('manage_options')) {
            wp_die('您沒有權限執行此操作。');
        }
        
        $plugin_file = isset($_GET['plugin']) ? sanitize_text_field($_GET['plugin']) : '';
        
        if (empty($plugin_file)) {
            wp_die('無效的外掛程式參數。');
        }
        
        // 驗證 nonce
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wu_download_plugin_' . $plugin_file)) {
            wp_die('安全驗證失敗。');
        }
        
        // 獲取外掛程式資訊
        $plugins = get_plugins();
        if (!isset($plugins[$plugin_file])) {
            wp_die('找不到指定的外掛程式。');
        }
        
        $plugin_data = $plugins[$plugin_file];
        $plugin_slug = dirname($plugin_file);
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
        
        if (!is_dir($plugin_path)) {
            wp_die('外掛程式目錄不存在。');
        }
        
        // 創建 ZIP 檔案
        $zip_filename = $plugin_slug . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
        
        if ($this->create_zip($plugin_path, $zip_path)) {
            $this->download_file($zip_path, $zip_filename);
        } else {
            wp_die('無法創建 ZIP 檔案。請檢查伺服器是否支援 ZipArchive。');
        }
    }
    
    /**
     * 下載主題
     */
    public function download_theme() {
        if (!current_user_can('manage_options')) {
            wp_die('您沒有權限執行此操作。');
        }
        
        $theme_slug = isset($_GET['theme']) ? sanitize_text_field($_GET['theme']) : '';
        
        if (empty($theme_slug)) {
            wp_die('無效的主題參數。');
        }
        
        // 驗證 nonce
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wu_download_theme_' . $theme_slug)) {
            wp_die('安全驗證失敗。');
        }
        
        // 獲取主題資訊
        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            wp_die('找不到指定的主題。');
        }
        
        $theme_path = $theme->get_stylesheet_directory();
        
        if (!is_dir($theme_path)) {
            wp_die('主題目錄不存在。');
        }
        
        // 創建 ZIP 檔案
        $zip_filename = $theme_slug . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
        
        if ($this->create_zip($theme_path, $zip_path)) {
            $this->download_file($zip_path, $zip_filename);
        } else {
            wp_die('無法創建 ZIP 檔案。請檢查伺服器是否支援 ZipArchive。');
        }
    }
    
    /**
     * 創建 ZIP 檔案
     */
    private function create_zip($source_path, $zip_path) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return false;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($source_path) + 1);
                
                // 跳過某些不必要的檔案
                if ($this->should_skip_file($relative_path)) {
                    continue;
                }
                
                $zip->addFile($file_path, $relative_path);
            }
        }
        
        $result = $zip->close();
        return $result;
    }
    
    /**
     * 檢查是否應該跳過檔案
     */
    private function should_skip_file($file_path) {
        $skip_patterns = array(
            '.DS_Store',
            'Thumbs.db',
            '.git/',
            '.svn/',
            'node_modules/',
            '.tmp',
            '.log'
        );
        
        foreach ($skip_patterns as $pattern) {
            if (strpos($file_path, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 下載檔案
     */
    private function download_file($file_path, $filename) {
        if (!file_exists($file_path)) {
            wp_die('檔案不存在。');
        }
        
        // 清理輸出緩衝區
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 設定下載標頭
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, no-transform, no-store, must-revalidate');
        
        // 輸出檔案
        readfile($file_path);
        
        // 刪除臨時檔案
        unlink($file_path);
        
        exit;
    }
}

// 初始化模組
new WU_Enhanced_Downloader();
