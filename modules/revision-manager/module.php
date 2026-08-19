<?php
/**
 * Module: revision-manager
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 修訂版本管理模組
 * 功能：限制文章修訂版本數量，防止資料庫膨脹
 */

if (!defined('ABSPATH')) exit;

class WU_Revision_Manager {
    
    private $option_name = 'wu_revision_manager_settings';
    private $settings;
    
    public function __construct() {
        $this->settings = get_option($this->option_name, array(
            'enabled' => false,
            'max_revisions' => 5,
            'post_types' => array('post', 'page')
        ));
        
        $this->init_hooks();
    }
    
    /**
     * 初始化鉤子
     */
    private function init_hooks() {
        // 管理員頁面
        add_action('admin_menu', array($this, 'add_admin_menu'), 65);
        add_action('admin_init', array($this, 'init_settings'));
        
        // 修訂版本限制
        if ($this->settings['enabled']) {
            add_filter('wp_revisions_to_keep', array($this, 'limit_revisions'), 10, 2);
        }
        
        // AJAX 處理
        add_action('wp_ajax_wu_remove_all_revisions', array($this, 'ajax_remove_all_revisions'));
        add_action('wp_ajax_wu_get_revision_stats', array($this, 'ajax_get_revision_stats'));
    }
    
    /**
     * 添加管理員選單
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '修訂版本管理',
            '修訂版本管理',
            'manage_options',
            'wu-revision-manager',
            array($this, 'admin_page')
        );
    }
    
    /**
     * 初始化設定
     */
    public function init_settings() {
        register_setting(
            'wu_revision_manager_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        add_settings_section(
            'wu_revision_manager_section',
            '修訂版本管理設定',
            array($this, 'section_callback'),
            'wu_revision_manager'
        );
        
        add_settings_field(
            'enabled',
            '啟用修訂版本限制',
            array($this, 'enabled_callback'),
            'wu_revision_manager',
            'wu_revision_manager_section'
        );
        
        add_settings_field(
            'max_revisions',
            '最大修訂版本數量',
            array($this, 'max_revisions_callback'),
            'wu_revision_manager',
            'wu_revision_manager_section'
        );
        
        add_settings_field(
            'post_types',
            '適用的文章類型',
            array($this, 'post_types_callback'),
            'wu_revision_manager',
            'wu_revision_manager_section'
        );
    }
    
    /**
     * 設定區段說明
     */
    public function section_callback() {
        echo '<p><strong>注意：</strong>修訂版本儲存在資料庫中，如果儲存過多，可能會導致資料庫膨脹。這種膨脹可能會導致查詢速度變慢，從而對效能產生顯著影響。</p>';
    }
    
    /**
     * 啟用設定欄位
     */
    public function enabled_callback() {
        $enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : false;
        echo '<input type="checkbox" id="enabled" name="' . $this->option_name . '[enabled]" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<label for="enabled">啟用修訂版本數量限制</label>';
    }
    
    /**
     * 最大修訂版本數量欄位
     */
    public function max_revisions_callback() {
        $max_revisions = isset($this->settings['max_revisions']) ? $this->settings['max_revisions'] : 5;
        echo '<input type="number" id="max_revisions" name="' . $this->option_name . '[max_revisions]" value="' . esc_attr($max_revisions) . '" min="1" max="100" />';
        echo '<p class="description">設定每個文章/頁面保留的最大修訂版本數量（建議：5-10個）</p>';
    }
    
    /**
     * 文章類型欄位
     */
    public function post_types_callback() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $selected_types = isset($this->settings['post_types']) ? $this->settings['post_types'] : array('post', 'page');
        
        foreach ($post_types as $post_type) {
            $checked = in_array($post_type->name, $selected_types) ? 'checked' : '';
            echo '<label style="display: block; margin: 5px 0;">';
            echo '<input type="checkbox" name="' . $this->option_name . '[post_types][]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' />';
            echo ' ' . esc_html($post_type->label);
            echo '</label>';
        }
    }
    
    /**
     * 清理設定
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['enabled'] = isset($input['enabled']) ? true : false;
        $sanitized['max_revisions'] = absint($input['max_revisions']);
        $sanitized['max_revisions'] = max(1, min(100, $sanitized['max_revisions']));
        
        if (isset($input['post_types']) && is_array($input['post_types'])) {
            $sanitized['post_types'] = array_map('sanitize_text_field', $input['post_types']);
        } else {
            $sanitized['post_types'] = array('post', 'page');
        }
        
        return $sanitized;
    }
    
    /**
     * 限制修訂版本數量
     */
    public function limit_revisions($num, $post) {
        if (!$this->settings['enabled']) {
            return $num;
        }
        
        if (!in_array($post->post_type, $this->settings['post_types'])) {
            return $num;
        }
        
        return $this->settings['max_revisions'];
    }
    
    /**
     * 管理員頁面
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->settings = get_option($this->option_name, array());
        }
        
        $revision_stats = $this->get_revision_stats();
        ?>
        <div class="wrap">
            <h1>修訂版本管理</h1>
            
            <div class="wu-revision-manager-container">
                <!-- 設定表單 -->
                <div class="wu-settings-section">
                    <h2>基本設定</h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('wu_revision_manager_group');
                        do_settings_sections('wu_revision_manager');
                        submit_button('儲存設定');
                        ?>
                    </form>
                </div>
                
                <!-- 統計資訊 -->
                <div class="wu-stats-section">
                    <h2>修訂版本統計</h2>
                    <div class="wu-stats-grid">
                        <div class="wu-stat-box">
                            <h3>總修訂版本數</h3>
                            <p class="wu-stat-number"><?php echo number_format($revision_stats['total_revisions']); ?></p>
                        </div>
                        <div class="wu-stat-box">
                            <h3>受影響的文章</h3>
                            <p class="wu-stat-number"><?php echo number_format($revision_stats['affected_posts']); ?></p>
                        </div>
                        <div class="wu-stat-box">
                            <h3>資料庫大小影響</h3>
                            <p class="wu-stat-number"><?php echo size_format($revision_stats['estimated_size']); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- 操作按鈕 -->
                <div class="wu-actions-section">
                    <h2>批量操作</h2>
                    <div class="wu-action-buttons">
                        <button type="button" class="button button-primary" id="wu-remove-all-revisions">
                            一鍵移除所有修訂版本
                        </button>
                        <button type="button" class="button button-secondary" id="wu-refresh-stats">
                            重新整理統計
                        </button>
                    </div>
                    <div id="wu-action-result" style="margin-top: 10px;"></div>
                </div>
                
                <!-- 詳細資訊 -->
                <div class="wu-details-section">
                    <h2>詳細資訊</h2>
                    <div class="wu-details-content">
                        <h3>修訂版本說明</h3>
                        <ul>
                            <li><strong>修訂版本</strong>：WordPress 自動儲存的文章修改歷史記錄</li>
                            <li><strong>資料庫影響</strong>：過多的修訂版本會導致資料庫膨脹，影響查詢效能</li>
                            <li><strong>建議設定</strong>：每個文章保留 5-10 個修訂版本即可</li>
                            <li><strong>安全提醒</strong>：移除修訂版本是永久性操作，請謹慎使用</li>
                        </ul>
                        
                        <h3>效能優化建議</h3>
                        <ul>
                            <li>定期清理過多的修訂版本</li>
                            <li>設定合理的修訂版本數量限制</li>
                            <li>監控資料庫大小變化</li>
                            <li>考慮使用資料庫優化工具</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .wu-revision-manager-container {
            max-width: 1200px;
        }
        .wu-settings-section,
        .wu-stats-section,
        .wu-actions-section,
        .wu-details-section {
            background: #fff;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        .wu-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .wu-stat-box {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .wu-stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
            margin: 10px 0;
        }
        .wu-action-buttons {
            margin: 20px 0;
        }
        .wu-action-buttons .button {
            margin-right: 10px;
        }
        .wu-details-content ul {
            margin-left: 20px;
        }
        .wu-details-content li {
            margin: 8px 0;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // 移除所有修訂版本
            $('#wu-remove-all-revisions').on('click', function() {
                if (!confirm('確定要移除所有修訂版本嗎？此操作無法復原！')) {
                    return;
                }
                
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('處理中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wu_remove_all_revisions',
                        nonce: '<?php echo wp_create_nonce('wu_remove_revisions'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wu-action-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            // 重新整理統計
                            $('#wu-refresh-stats').click();
                        } else {
                            $('#wu-action-result').html('<div class="notice notice-error"><p>錯誤：' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#wu-action-result').html('<div class="notice notice-error"><p>請求失敗，請重試</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // 重新整理統計
            $('#wu-refresh-stats').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('更新中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wu_get_revision_stats',
                        nonce: '<?php echo wp_create_nonce('wu_get_stats'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * 獲取修訂版本統計
     */
    private function get_revision_stats() {
        global $wpdb;
        
        $stats = array(
            'total_revisions' => 0,
            'affected_posts' => 0,
            'estimated_size' => 0
        );
        
        // 總修訂版本數
        $stats['total_revisions'] = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'revision'
        ");
        
        // 受影響的文章數
        $stats['affected_posts'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_parent) FROM {$wpdb->posts} 
            WHERE post_type = 'revision'
        ");
        
        // 估算資料庫大小影響（每個修訂版本約 2KB）
        $stats['estimated_size'] = $stats['total_revisions'] * 2048;
        
        return $stats;
    }
    
    /**
     * AJAX：移除所有修訂版本
     */
    public function ajax_remove_all_revisions() {
        // 檢查權限
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }
        
        // 檢查 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wu_remove_revisions')) {
            wp_die('安全檢查失敗');
        }
        
        global $wpdb;
        
        // 開始事務
        $wpdb->query('START TRANSACTION');
        
        try {
            // 移除所有修訂版本
            $deleted_count = $wpdb->query("
                DELETE FROM {$wpdb->posts} 
                WHERE post_type = 'revision'
            ");
            
            if ($deleted_count === false) {
                throw new Exception('資料庫操作失敗');
            }
            
            // 清理修訂版本相關的 meta 資料
            $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL
            ");
            
            // 提交事務
            $wpdb->query('COMMIT');
            
            // 清理快取
            wp_cache_flush();
            
            wp_send_json_success(array(
                'message' => sprintf('成功移除 %d 個修訂版本！', $deleted_count)
            ));
            
        } catch (Exception $e) {
            // 回滾事務
            $wpdb->query('ROLLBACK');
            wp_send_json_error('操作失敗：' . $e->getMessage());
        }
    }
    
    /**
     * AJAX：獲取統計資訊
     */
    public function ajax_get_revision_stats() {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'wu_get_stats')) {
            wp_die('安全檢查失敗');
        }
        
        wp_send_json_success();
    }
}

// 初始化修訂版本管理器
new WU_Revision_Manager();