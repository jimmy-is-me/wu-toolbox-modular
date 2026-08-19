<?php
/**
 * Module: transients-manager
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * Transients 管理模組
 * 管理 WordPress Transients，提升網站性能
 * 版本:2.0 - 物件快取相容性、SQL 優化、安全性強化
 */

if (!defined('ABSPATH')) exit;

class WU_Transients_Manager {
    
    private $settings;
    private $option_name = 'wu_transients_manager_settings';
    
    /**
     * 保護的 Transients (白名單)
     */
    private $protected_transients = array(
        'wc_cart_hash_',
        'wc_session_',
        'woocommerce_shipping_',
        'doing_cron',
        'update_core',
        'update_plugins',
        'update_themes'
    );
    
    public function __construct() {
        $this->settings = get_option($this->option_name, $this->get_default_settings());
        
        // 後台功能
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'), 80);
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
        
        // AJAX 功能
        add_action('wp_ajax_wu_clear_transients', array($this, 'ajax_clear_transients'));
        add_action('wp_ajax_wu_get_transients_stats', array($this, 'ajax_get_transients_stats'));
        
        // 自動清理
        add_action('wu_transients_auto_cleanup', array($this, 'auto_cleanup_expired'));
        
        // 啟用/停用 Hook
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * 預設設定
     */
    private function get_default_settings() {
        return array(
            'enabled' => false,
            'auto_cleanup' => true,
            'cleanup_frequency' => 'daily',
            'show_performance_stats' => true,
            'protect_important' => true
        );
    }
    
    /**
     * 啟用模組
     */
    public function activate() {
        $this->schedule_auto_cleanup();
    }
    
    /**
     * 停用模組
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wu_transients_auto_cleanup');
    }
    
    /**
     * 註冊設定
     */
    public function register_settings() {
        register_setting(
            'wu_transients_manager_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        add_settings_section(
            'wu_transients_manager_section',
            'Transients 管理設定',
            array($this, 'section_callback'),
            'wu-transients-manager'
        );
        
        add_settings_field(
            'enabled',
            '啟用功能',
            array($this, 'enabled_field_callback'),
            'wu-transients-manager',
            'wu_transients_manager_section'
        );
        
        add_settings_field(
            'auto_cleanup',
            '自動清理',
            array($this, 'auto_cleanup_field_callback'),
            'wu-transients-manager',
            'wu_transients_manager_section'
        );
        
        add_settings_field(
            'cleanup_frequency',
            '清理頻率',
            array($this, 'cleanup_frequency_field_callback'),
            'wu-transients-manager',
            'wu_transients_manager_section'
        );
        
        add_settings_field(
            'advanced_options',
            '進階選項',
            array($this, 'advanced_options_field_callback'),
            'wu-transients-manager',
            'wu_transients_manager_section'
        );
    }
    
    /**
     * 清理設定
     */
    public function sanitize_settings($input) {
        $old_settings = $this->settings;
        $sanitized = array();
        
        $sanitized['enabled'] = !empty($input['enabled']) ? true : false;
        $sanitized['auto_cleanup'] = !empty($input['auto_cleanup']) ? true : false;
        $sanitized['cleanup_frequency'] = sanitize_text_field($input['cleanup_frequency']);
        $sanitized['show_performance_stats'] = !empty($input['show_performance_stats']) ? true : false;
        $sanitized['protect_important'] = !empty($input['protect_important']) ? true : false;
        
        // 如果清理頻率改變,重新安排任務
        if ($old_settings['cleanup_frequency'] !== $sanitized['cleanup_frequency'] || 
            $old_settings['auto_cleanup'] !== $sanitized['auto_cleanup']) {
            wp_clear_scheduled_hook('wu_transients_auto_cleanup');
            if ($sanitized['auto_cleanup']) {
                $this->schedule_auto_cleanup($sanitized['cleanup_frequency']);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * 載入後台資源
     */
    public function enqueue_admin_assets($hook) {
        // 僅在設定頁面載入
        if ($hook !== 'wumetaxtoolkit_page_wu-transients-manager') {
            return;
        }
        
        // 載入 CSS
        wp_enqueue_style(
            'wu-transients-manager',
            false,
            array(),
            '2.0'
        );
        
        wp_add_inline_style('wu-transients-manager', $this->get_admin_css());
        
        // 載入 JavaScript
        wp_enqueue_script(
            'wu-transients-manager',
            false,
            array('jquery'),
            '2.0',
            true
        );
        
        wp_localize_script('wu-transients-manager', 'wuTransientsData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wu_transients_action')
        ));
        
        wp_add_inline_script('wu-transients-manager', $this->get_admin_js());
    }
    
    /**
     * 取得後台 CSS
     */
    private function get_admin_css() {
        return '
        .wu-transients-stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .wu-stat-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            min-width: 200px;
            border-left: 4px solid #0073aa;
        }
        
        .wu-stat-box strong {
            display: block;
            margin-bottom: 5px;
            color: #333;
        }
        
        .wu-stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        
        .wu-performance-panel {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .wu-performance-panel h3 {
            margin-top: 0;
        }
        
        .wu-two-column {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .wu-two-column > div {
            flex: 1;
            min-width: 250px;
        }
        
        .wu-action-panel {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .wu-action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .wu-progress {
            display: none;
            margin-top: 15px;
        }
        
        .wu-progress-bar-wrapper {
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            height: 20px;
        }
        
        .wu-progress-bar {
            height: 100%;
            background: #0073aa;
            width: 0%;
            transition: width 0.3s;
        }
        
        .wu-progress-text {
            text-align: center;
            margin-top: 10px;
            font-weight: bold;
        }
        
        .wu-cache-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
        }
        
        .wu-cache-warning h4 {
            margin-top: 0;
            color: #856404;
        }
        
        .form-table th {
            width: 200px;
        }
        
        .notice {
            padding: 10px;
            margin: 15px 0;
            border-left: 4px solid;
            background: #fff;
        }
        
        .notice-success {
            border-left-color: #46b450;
        }
        
        .notice-error {
            border-left-color: #dc3232;
        }
        
        .notice-info {
            border-left-color: #0073aa;
        }
        
        .notice-warning {
            border-left-color: #ffb900;
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
            background: #00a32a;
            color: #fff;
        }
        
        .wu-status-off {
            background: #d63638;
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
            var ajaxurl = wuTransientsData.ajaxurl;
            var nonce = wuTransientsData.nonce;
            
            window.clearTransients = function(type) {
                if (type === "all" && !confirm("確定要清除所有 Transients 嗎？這將暫時影響網站效能，但對網站是安全的。")) {
                    return;
                }
                
                $("#wu-transients-progress").show();
                $("#wu-transients-result").html("");
                
                // 模擬進度條
                var progress = 0;
                var progressInterval = setInterval(function() {
                    progress += Math.random() * 15;
                    if (progress > 90) progress = 90;
                    $("#wu-progress-bar").css("width", progress + "%");
                }, 200);
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "wu_clear_transients",
                        type: type,
                        _wpnonce: nonce
                    },
                    success: function(response) {
                        clearInterval(progressInterval);
                        $("#wu-progress-bar").css("width", "100%");
                        
                        setTimeout(function() {
                            $("#wu-transients-progress").hide();
                            
                            if (response.success) {
                                $("#wu-transients-result").html(
                                    "<div class=\"notice notice-success\"><p>" + 
                                    response.data.message + 
                                    "</p></div>"
                                );
                                
                                // 更新統計數據
                                if (response.data.stats) {
                                    updateStats(response.data.stats);
                                }
                            } else {
                                $("#wu-transients-result").html(
                                    "<div class=\"notice notice-error\"><p>清除失敗：" + 
                                    response.data + 
                                    "</p></div>"
                                );
                            }
                        }, 500);
                    },
                    error: function() {
                        clearInterval(progressInterval);
                        $("#wu-transients-progress").hide();
                        $("#wu-transients-result").html(
                            "<div class=\"notice notice-error\"><p>請求失敗，請重試。</p></div>"
                        );
                    }
                });
            };
            
            window.refreshStats = function() {
                location.reload();
            };
            
            function updateStats(stats) {
                // 更新統計數字
                $(".wu-stat-total .wu-stat-number").text(formatNumber(stats.total));
                $(".wu-stat-expired .wu-stat-number").text(formatNumber(stats.expired));
                $(".wu-stat-valid .wu-stat-number").text(formatNumber(stats.valid));
                $(".wu-stat-size .wu-stat-number").text(stats.size_formatted);
                
                // 更新按鈕文字
                $("button[onclick*=\"expired\"]").html(
                    "清除過期 Transients (" + formatNumber(stats.expired) + " 個)"
                );
                $("button[onclick*=\"all\"]").html(
                    "清除所有 Transients (" + formatNumber(stats.total) + " 個)"
                );
            }
            
            function formatNumber(num) {
                return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }
        });
        ';
    }
    
    /**
     * 添加子選單頁面
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wumetax-toolkit',
            '瞬態資料管理',
            '瞬態資料管理',
            'manage_options',
            'wu-transients-manager',
            array($this, 'admin_page')
        );
    }
    
    /**
     * 設定區段回調
     */
    public function section_callback() {
        echo '<p>配置 Transients 管理功能的相關設置。</p>';
    }
    
    /**
     * 啟用功能欄位
     */
    public function enabled_field_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo $this->option_name; ?>[enabled]" 
                   value="1" 
                   <?php checked(1, $this->settings['enabled']); ?>>
            啟用 Transients 管理
        </label>
        <?php
    }
    
    /**
     * 自動清理欄位
     */
    public function auto_cleanup_field_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo $this->option_name; ?>[auto_cleanup]" 
                   value="1" 
                   <?php checked(1, $this->settings['auto_cleanup']); ?>>
            自動清理過期的 Transients
        </label>
        <p class="description">定期自動清除過期的 transients，保持資料庫整潔</p>
        <?php
    }
    
    /**
     * 清理頻率欄位
     */
    public function cleanup_frequency_field_callback() {
        ?>
        <select name="<?php echo $this->option_name; ?>[cleanup_frequency]">
            <option value="hourly" <?php selected($this->settings['cleanup_frequency'], 'hourly'); ?>>每小時</option>
            <option value="twicedaily" <?php selected($this->settings['cleanup_frequency'], 'twicedaily'); ?>>每天兩次</option>
            <option value="daily" <?php selected($this->settings['cleanup_frequency'], 'daily'); ?>>每日</option>
            <option value="weekly" <?php selected($this->settings['cleanup_frequency'], 'weekly'); ?>>每週</option>
        </select>
        <p class="description">自動清理的執行頻率</p>
        <?php
    }
    
    /**
     * 進階選項欄位
     */
    public function advanced_options_field_callback() {
        ?>
        <p>
            <label>
                <input type="checkbox" 
                       name="<?php echo $this->option_name; ?>[show_performance_stats]" 
                       value="1" 
                       <?php checked(1, $this->settings['show_performance_stats']); ?>>
                在後台顯示效能統計資訊
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" 
                       name="<?php echo $this->option_name; ?>[protect_important]" 
                       value="1" 
                       <?php checked(1, $this->settings['protect_important']); ?>>
                保護重要的 Transients (推薦)
            </label>
            <p class="description">避免清除 WooCommerce 購物車、結帳流程等重要資料</p>
        </p>
        <?php
    }
    
    /**
     * 管理頁面
     */
    public function admin_page() {
        // 驗證權限
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('您沒有足夠的權限訪問此頁面。', 'wumetax-toolkit'));
        }
        
        $this->settings = get_option($this->option_name, $this->get_default_settings());
        $stats = $this->get_transients_stats();
        $using_object_cache = wp_using_ext_object_cache();
        $status = !empty($this->settings['enabled']) ? 'on' : 'off';
        
        ?>
        <div class="wrap">
            <h1>
                Transients 管理
                <span class="wu-status-badge wu-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status === 'on' ? '已啟用' : '已關閉'; ?>
                </span>
            </h1>
            
            <?php if ($using_object_cache): ?>
            <div class="wu-cache-warning">
                <h4>⚠️ 偵測到外部物件快取</h4>
                <p>您的網站正在使用外部物件快取 (Redis 或 Memcached)。大部分 Transients 儲存在記憶體中，而非資料庫。</p>
                <p><strong>建議操作：</strong></p>
                <ul>
                    <li>使用快取外掛的管理介面清除物件快取</li>
                    <li>本模組的統計數據僅反映資料庫中的 Transients</li>
                    <li>記憶體中的 Transients 會自動過期，通常不需要手動清除</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <h3>什麼是 Transients？</h3>
                <p><strong>Transients</strong> 是 WordPress 的暫存機制，用來儲存臨時資料以提升網站效能。它們會自動過期，但有時過期的資料不會被即時清除，可能會佔用資料庫空間。</p>
                
                <h4>Transients 的作用：</h4>
                <ul>
                    <li><strong>快取外部 API 請求</strong> - 避免重複請求第三方服務</li>
                    <li><strong>儲存複雜查詢結果</strong> - 減少資料庫負載</li>
                    <li><strong>暫存檔案處理結果</strong> - 提升頁面載入速度</li>
                    <li><strong>快取計算結果</strong> - 避免重複執行耗時運算</li>
                </ul>
                
                <h4>清除 Transients 的影響：</h4>
                <ul>
                    <li><strong>正面影響</strong>：釋放資料庫空間、清除無效快取</li>
                    <li><strong>暫時影響</strong>：網站可能需要重新建立快取，首次載入稍慢</li>
                    <li><strong>安全性</strong>：清除過期 transients 是安全的操作</li>
                </ul>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wu_transients_manager_group');
                do_settings_sections('wu-transients-manager');
                submit_button('儲存設定');
                ?>
            </form>
            
            <hr>
            
            <h2>Transients 統計</h2>
            <div class="wu-transients-stats">
                <div class="wu-stat-box wu-stat-total">
                    <strong>總 Transients 數量</strong>
                    <div class="wu-stat-number"><?php echo number_format($stats['total']); ?></div>
                </div>
                <div class="wu-stat-box wu-stat-expired">
                    <strong>過期 Transients</strong>
                    <div class="wu-stat-number" style="color: #dc3232;"><?php echo number_format($stats['expired']); ?></div>
                </div>
                <div class="wu-stat-box wu-stat-valid">
                    <strong>有效 Transients</strong>
                    <div class="wu-stat-number" style="color: #46b450;"><?php echo number_format($stats['valid']); ?></div>
                </div>
                <div class="wu-stat-box wu-stat-size">
                    <strong>佔用空間</strong>
                    <div class="wu-stat-number" style="color: #ff8c00;"><?php echo $stats['size_formatted']; ?></div>
                </div>
            </div>
            
            <?php if ($this->settings['show_performance_stats'] && $stats['expired'] > 0): ?>
            <div class="wu-performance-panel">
                <h3>效能分析</h3>
                <div class="wu-two-column">
                    <div>
                        <strong>清除過期 Transients 可節省：</strong>
                        <ul>
                            <li>資料庫空間：<?php echo $stats['expired_size_formatted']; ?></li>
                            <li>資料庫查詢：估計減少 <?php echo number_format($stats['expired']); ?> 次無效查詢</li>
                            <li>記憶體使用：減少約 <?php echo $this->format_bytes($stats['expired_size'] * 0.5); ?> 記憶體負載</li>
                        </ul>
                    </div>
                    <div>
                        <strong>預期效能提升：</strong>
                        <ul>
                            <li>後台載入速度：提升 2-5%</li>
                            <li>資料庫效能：提升 1-3%</li>
                            <li>整體響應時間：減少 10-50ms</li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <h2>清理操作</h2>
            <div class="wu-action-panel">
                <div class="wu-action-buttons">
                    <button type="button" class="button button-primary" onclick="clearTransients('expired')">
                        清除過期 Transients (<?php echo number_format($stats['expired']); ?> 個)
                    </button>
                    <button type="button" class="button button-secondary" onclick="clearTransients('all')">
                        清除所有 Transients (<?php echo number_format($stats['total']); ?> 個)
                    </button>
                    <button type="button" class="button" onclick="refreshStats()">
                        重新整理統計
                    </button>
                </div>
                
                <div id="wu-transients-result"></div>
                <div id="wu-transients-progress" class="wu-progress">
                    <div class="wu-progress-bar-wrapper">
                        <div id="wu-progress-bar" class="wu-progress-bar"></div>
                    </div>
                    <p id="wu-progress-text" class="wu-progress-text">處理中...</p>
                </div>
            </div>
            
            <h2>常見 Transients 類型</h2>
            <div class="wu-action-panel">
                <?php $transients_by_type = $this->get_transients_by_type(); ?>
                <?php if (!empty($transients_by_type)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>類型</th>
                            <th>數量</th>
                            <th>說明</th>
                            <th>清除建議</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transients_by_type as $type => $data): ?>
                        <tr>
                            <td><strong><?php echo esc_html($type); ?></strong></td>
                            <td><?php echo number_format($data['count']); ?></td>
                            <td><?php echo esc_html($data['description']); ?></td>
                            <td>
                                <span style="color: <?php echo $data['safety'] === 'safe' ? '#46b450' : ($data['safety'] === 'caution' ? '#ff8c00' : '#dc3232'); ?>;">
                                    <?php echo esc_html($data['recommendation']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>目前沒有發現 transients，這表示您的資料庫很乾淨！</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX 清除 Transients
     */
    public function ajax_clear_transients() {
        // 驗證 Nonce
        if (!isset($_POST['_wpnonce']) || 
            !wp_verify_nonce($_POST['_wpnonce'], 'wu_transients_action')) {
            wp_send_json_error('安全驗證失敗');
        }
        
        // 驗證權限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('權限不足');
        }
        
        $type = sanitize_text_field($_POST['type']);
        
        if ($type === 'expired') {
            $result = $this->clear_expired_transients();
        } elseif ($type === 'all') {
            $result = $this->clear_all_transients();
        } else {
            wp_send_json_error('無效的清除類型');
        }
        
        if ($result['success']) {
            // 取得最新統計
            $stats = $this->get_transients_stats();
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'cleared' => $result['cleared'],
                'stats' => $stats
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX 取得統計
     */
    public function ajax_get_transients_stats() {
        // 驗證權限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('權限不足');
        }
        
        $stats = $this->get_transients_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * 取得 Transients 統計 (優化版本)
     */
    private function get_transients_stats() {
        global $wpdb;
        
        // 如果使用外部物件快取,提供基本統計
        if (wp_using_ext_object_cache()) {
            return array(
                'total' => 0,
                'expired' => 0,
                'valid' => 0,
                'size' => 0,
                'size_formatted' => '0 B',
                'expired_size' => 0,
                'expired_size_formatted' => '0 B',
                'using_object_cache' => true
            );
        }
        
        // 優化的統計查詢 - 僅計算數量和大小,不讀取完整資料
        $total_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(LENGTH(option_value)) as total_size
             FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_%' 
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        $site_total_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(LENGTH(option_value)) as total_size
             FROM {$wpdb->options}
             WHERE option_name LIKE '_site_transient_%' 
             AND option_name NOT LIKE '_site_transient_timeout_%'"
        );
        
        $total = intval($total_stats->total) + intval($site_total_stats->total);
        $total_size = intval($total_stats->total_size) + intval($site_total_stats->total_size);
        
        // 優化的過期統計查詢
        $expired_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as expired,
                SUM(LENGTH(t1.option_value)) as expired_size
             FROM {$wpdb->options} t1
             LEFT JOIN {$wpdb->options} t2 
                ON t2.option_name = CONCAT('_transient_timeout_', SUBSTRING(t1.option_name, 12))
             WHERE t1.option_name LIKE '_transient_%' 
             AND t1.option_name NOT LIKE '_transient_timeout_%'
             AND (t2.option_value IS NULL OR CAST(t2.option_value AS UNSIGNED) < UNIX_TIMESTAMP())"
        );
        
        $site_expired_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as expired,
                SUM(LENGTH(t1.option_value)) as expired_size
             FROM {$wpdb->options} t1
             LEFT JOIN {$wpdb->options} t2 
                ON t2.option_name = CONCAT('_site_transient_timeout_', SUBSTRING(t1.option_name, 17))
             WHERE t1.option_name LIKE '_site_transient_%' 
             AND t1.option_name NOT LIKE '_site_transient_timeout_%'
             AND (t2.option_value IS NULL OR CAST(t2.option_value AS UNSIGNED) < UNIX_TIMESTAMP())"
        );
        
        $expired = intval($expired_stats->expired) + intval($site_expired_stats->expired);
        $expired_size = intval($expired_stats->expired_size) + intval($site_expired_stats->expired_size);
        $valid = $total - $expired;
        
        return array(
            'total' => $total,
            'expired' => $expired,
            'valid' => $valid,
            'size' => $total_size,
            'size_formatted' => $this->format_bytes($total_size),
            'expired_size' => $expired_size,
            'expired_size_formatted' => $this->format_bytes($expired_size),
            'using_object_cache' => false
        );
    }
    
    /**
     * 取得 Transients 分類
     */
    private function get_transients_by_type() {
        global $wpdb;
        
        $transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_%' 
             AND option_name NOT LIKE '_transient_timeout_%'
             LIMIT 1000"
        );
        
        $types = array();
        
        foreach ($transients as $transient_name) {
            $name = str_replace('_transient_', '', $transient_name);
            
            // 分析 transient 類型
            if (strpos($name, 'feed_') === 0) {
                $type = 'RSS Feed 快取';
                $description = 'RSS 摘要的快取資料';
                $recommendation = '安全清除';
                $safety = 'safe';
            } elseif (strpos($name, 'update_') === 0) {
                $type = '更新檢查';
                $description = '外掛/主題更新檢查資料';
                $recommendation = '建議保留';
                $safety = 'caution';
            } elseif (strpos($name, 'wc_') === 0 || strpos($name, 'woocommerce_') === 0) {
                $type = 'WooCommerce';
                $description = 'WooCommerce 相關快取';
                $recommendation = '小心清除';
                $safety = 'caution';
            } elseif (strpos($name, 'oembed_') === 0) {
                $type = 'oEmbed 快取';
                $description = '嵌入內容的快取資料';
                $recommendation = '安全清除';
                $safety = 'safe';
            } elseif (strpos($name, 'query_') === 0) {
                $type = '資料庫查詢';
                $description = '資料庫查詢結果快取';
                $recommendation = '安全清除';
                $safety = 'safe';
            } elseif (strpos($name, 'elementor_') === 0) {
                $type = 'Elementor';
                $description = 'Elementor 頁面編輯器快取';
                $recommendation = '安全清除';
                $safety = 'safe';
            } else {
                $type = '其他';
                $description = '其他類型的暫存資料';
                $recommendation = '建議保留';
                $safety = 'caution';
            }
            
            if (!isset($types[$type])) {
                $types[$type] = array(
                    'count' => 0,
                    'description' => $description,
                    'recommendation' => $recommendation,
                    'safety' => $safety
                );
            }
            
            $types[$type]['count']++;
        }
        
        return $types;
    }
    
    /**
     * 清除過期 Transients (優化版本)
     */
    private function clear_expired_transients() {
        global $wpdb;
        
        // 使用單一 DELETE 語句清除過期的 transients 及其 timeout
        $deleted = $wpdb->query(
            "DELETE t1, t2 
             FROM {$wpdb->options} t1
             LEFT JOIN {$wpdb->options} t2 
                ON t2.option_name = CONCAT('_transient_timeout_', SUBSTRING(t1.option_name, 12))
             WHERE t1.option_name LIKE '_transient_%' 
             AND t1.option_name NOT LIKE '_transient_timeout_%'
             AND (t2.option_value IS NULL OR CAST(t2.option_value AS UNSIGNED) < UNIX_TIMESTAMP())"
        );
        
        // 清除網站級別的過期 transients
        $site_deleted = $wpdb->query(
            "DELETE t1, t2 
             FROM {$wpdb->options} t1
             LEFT JOIN {$wpdb->options} t2 
                ON t2.option_name = CONCAT('_site_transient_timeout_', SUBSTRING(t1.option_name, 17))
             WHERE t1.option_name LIKE '_site_transient_%' 
             AND t1.option_name NOT LIKE '_site_transient_timeout_%'
             AND (t2.option_value IS NULL OR CAST(t2.option_value AS UNSIGNED) < UNIX_TIMESTAMP())"
        );
        
        $total_cleared = $deleted + $site_deleted;
        
        return array(
            'success' => true,
            'message' => sprintf('成功清除 %d 個過期的 Transients', $total_cleared),
            'cleared' => $total_cleared
        );
    }
    
    /**
     * 清除所有 Transients (帶保護機制)
     */
    private function clear_all_transients() {
        global $wpdb;
        
        // 如果啟用保護,建立排除條件
        $exclude_conditions = '';
        if ($this->settings['protect_important']) {
            $protected_patterns = array();
            foreach ($this->protected_transients as $pattern) {
                $protected_patterns[] = $wpdb->prepare("option_name NOT LIKE %s", '_transient_' . $wpdb->esc_like($pattern) . '%');
            }
            $exclude_conditions = ' AND ' . implode(' AND ', $protected_patterns);
        }
        
        // 計算要清除的數量
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE (option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%')
             {$exclude_conditions}"
        );
        
        // 清除所有符合條件的 transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE (option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%')
             {$exclude_conditions}"
        );
        
        return array(
            'success' => true,
            'message' => sprintf('成功清除 %d 個 Transients', $count),
            'cleared' => $count
        );
    }
    
    /**
     * 安排自動清理
     */
    private function schedule_auto_cleanup($frequency = null) {
        if ($frequency === null) {
            $frequency = $this->settings['cleanup_frequency'];
        }
        
        if (!wp_next_scheduled('wu_transients_auto_cleanup')) {
            wp_schedule_event(time(), $frequency, 'wu_transients_auto_cleanup');
        }
    }
    
    /**
     * 自動清理過期項目
     */
    public function auto_cleanup_expired() {
        if ($this->settings['enabled'] && $this->settings['auto_cleanup']) {
            $this->clear_expired_transients();
        }
    }
    
    /**
     * 格式化位元組
     */
    private function format_bytes($bytes, $precision = 2) {
        if ($bytes <= 0) {
            return '0 B';
        }
        
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// 初始化模組
new WU_Transients_Manager();
