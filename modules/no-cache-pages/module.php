<?php
/**
 * Module: no-cache-pages
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * No Cache Pages Module
 * 無快取頁面管理工具
 */
if (!defined('ABSPATH')) exit;

class WU_No_Cache_Pages {
    
    private $options;
    private $option_name = 'wu_no_cache_pages_options';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'), 50);
        add_action('admin_init', array($this, 'init_settings'));
        add_action('send_headers', array($this, 'apply_no_cache_headers'), 1);
        add_action('template_redirect', array($this, 'apply_no_cache_headers'), 1);
        
        // 載入選項
        $this->options = get_option($this->option_name, array(
            'enabled' => false,
            'pages' => ''
        ));
    }
    
    /**
     * 添加子選單頁面
     */
    public function add_submenu_page() {
        add_submenu_page(
            'wumetax-toolkit',
            '無快取頁面設定',
            '無快取頁面',
            'manage_options',
            'wu-no_cache_pages',
            array($this, 'settings_page')
        );
    }
    
    /**
     * 初始化設置
     */
    public function init_settings() {
        register_setting(
            'wu_no_cache_pages_group',
            $this->option_name,
            array($this, 'sanitize_options')
        );
        
        add_settings_section(
            'wu_no_cache_pages_section',
            '無快取頁面設置',
            array($this, 'section_callback'),
            'wu-no-cache-pages'
        );
        
        add_settings_field(
            'enabled',
            '啟用功能',
            array($this, 'enabled_callback'),
            'wu-no-cache-pages',
            'wu_no_cache_pages_section'
        );
        
        add_settings_field(
            'pages',
            '無快取頁面',
            array($this, 'pages_callback'),
            'wu-no-cache-pages',
            'wu_no_cache_pages_section'
        );
    }
    
    /**
     * 設置頁面HTML
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>無快取頁面設置</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wu_no_cache_pages_group');
                do_settings_sections('wu-no-cache-pages');
                submit_button();
                ?>
            </form>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>功能說明</h2>
                <p><strong>此功能的作用：</strong></p>
                <ul>
                    <li>強制指定頁面完全不快取</li>
                    <li>解決登入後頁面顯示舊內容的問題</li>
                    <li>適用於會員專屬頁面、結帳頁面等動態內容</li>
                </ul>
                
                <p><strong>使用方式：</strong></p>
                <ul>
                    <li>每行輸入一個網址路徑（例如：<code>/dashboard/</code>）</li>
                    <li>支援部分匹配，輸入 <code>/my-account</code> 會匹配所有包含此路徑的頁面</li>
                    <li>建議只對需要即時更新的頁面使用，避免影響網站效能</li>
                </ul>
                
                <p><strong>常見使用場景：</strong></p>
                <ul>
                    <li>Directorist 用戶後台：<code>/dashboard/</code></li>
                    <li>WooCommerce 我的帳戶：<code>/my-account/</code></li>
                    <li>WooCommerce 結帳頁：<code>/checkout/</code></li>
                    <li>BuddyPress/bbPress 會員頁面</li>
                </ul>
                
                <?php if (!empty($this->options['enabled']) && !empty($this->options['pages'])): 
                    $page_list = array_filter(array_map('trim', explode("\n", $this->options['pages'])));
                ?>
                <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin-top: 15px;">
                    <h3 style="margin-top: 0; color: #155724;">功能已啟用</h3>
                    <p><strong>目前設定的無快取頁面：</strong></p>
                    <ul>
                        <?php foreach ($page_list as $page): ?>
                        <li><code><?php echo esc_html($page); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
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
        echo '<p>設定需要完全不快取的頁面，確保內容即時更新。</p>';
    }
    
    /**
     * 啟用功能回調
     */
    public function enabled_callback() {
        $enabled = !empty($this->options['enabled']) ? $this->options['enabled'] : false;
        ?>
        <label>
            <input type="checkbox" name="<?php echo $this->option_name; ?>[enabled]" value="1" <?php checked(1, $enabled); ?> />
            啟用無快取頁面功能
        </label>
        <?php
    }
    
    /**
     * 頁面列表回調
     */
    public function pages_callback() {
        $pages = !empty($this->options['pages']) ? $this->options['pages'] : '';
        ?>
        <textarea 
            name="<?php echo $this->option_name; ?>[pages]" 
            rows="10" 
            cols="50" 
            class="large-text code wu-termius-input"
            placeholder="/dashboard/&#10;/my-account/&#10;/checkout/&#10;/edit-listing/"
        ><?php echo esc_textarea($pages); ?></textarea>
        <p class="description">每行輸入一個網址路徑。例如：<code>/dashboard/</code> 或 <code>/my-account/</code></p>
        <p class="description"><strong>提示：</strong>支援部分匹配，<code>/dashboard</code> 會匹配所有包含此路徑的頁面（如 <code>/dashboard/edit</code>）</p>
        <?php
    }
    
    /**
     * 清理選項
     */
    public function sanitize_options($input) {
        $sanitized = array();
        
        $sanitized['enabled'] = !empty($input['enabled']) ? true : false;
        $sanitized['pages'] = !empty($input['pages']) ? sanitize_textarea_field($input['pages']) : '';
        
        return $sanitized;
    }
    
    /**
     * 應用無快取 Headers
     */
    public function apply_no_cache_headers() {
        // 檢查功能是否啟用
        if (empty($this->options['enabled'])) {
            return;
        }
        
        // 檢查是否有設定頁面
        if (empty($this->options['pages'])) {
            return;
        }
        
        // 取得頁面列表
        $page_list = array_filter(array_map('trim', explode("\n", $this->options['pages'])));
        
        if (empty($page_list)) {
            return;
        }
        
        // 取得當前網址
        $current_url = $_SERVER['REQUEST_URI'];
        
        // 檢查當前網址是否符合任一設定
        foreach ($page_list as $page) {
            if (strpos($current_url, $page) !== false) {
                // 移除所有快取 headers
                header_remove('Cache-Control');
                header_remove('Expires');
                header_remove('Pragma');
                
                // 設定不快取的 headers
                nocache_headers();
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache', true);
                header('Expires: 0', true);
                
                // 找到匹配就停止
                break;
            }
        }
    }
}

// 初始化類別
new WU_No_Cache_Pages();
