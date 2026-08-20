<?php
/**
 * Module: audit-logger
 * Loaded only when enabled in WU Toolbox Modular.
 */

if (!defined('ABSPATH')) exit;

class WU_Audit_Logger {
    private $options;
    private $option_name = 'wu_audit_logger_options';
    private $table_name;
    private $cron_hook = 'wu_audit_logger_purge_event';
    private $table_ready = null;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wu_audit_logs';

        add_action('admin_menu', array($this, 'add_submenu_page'), 25);
        add_action('admin_init', array($this, 'init_settings'));

        // 延遲載入選項以優化效能
        $this->options = get_option($this->option_name, array(
            'enabled' => false,
            'keep_days' => 7,
        ));

        add_action('init', array($this, 'maybe_setup'));
        add_action($this->cron_hook, array($this, 'purge_old_logs'));

        // 只在啟用時註冊 hooks，避免影響效能
        if (!empty($this->options['enabled'])) {
            add_action('wp_loaded', array($this, 'register_hooks'));
        }
    }

    public function maybe_setup() {
        if (!empty($this->options['enabled'])) {
            $this->maybe_create_table();
            $this->maybe_schedule_cron();
        }
    }

    private function maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            log_time DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            object_type VARCHAR(50) NULL,
            object_id BIGINT UNSIGNED NULL,
            object_name VARCHAR(191) NULL,
            ip_address VARCHAR(64) NULL,
            PRIMARY KEY  (id),
            KEY log_time_idx (log_time),
            KEY action_idx (action),
            KEY object_type_idx (object_type),
            KEY user_idx (user_id),
            KEY user_time_idx (user_id, log_time)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private function ensure_table() {
        global $wpdb;

        if ($this->table_ready !== null) {
            return $this->table_ready;
        }

        $this->maybe_create_table();
        $this->table_ready = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)) === $this->table_name);

        return $this->table_ready;
    }

    private function maybe_schedule_cron() {
        if (!wp_next_scheduled($this->cron_hook)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $this->cron_hook);
        }
    }

    public function purge_old_logs() {
        if (!$this->ensure_table()) {
            return;
        }

        $days = isset($this->options['keep_days']) ? intval($this->options['keep_days']) : 7;
        $days = in_array($days, array(3,7,14,31), true) ? $days : 7;
        global $wpdb;
        $threshold = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE log_time < %s", $threshold));
    }

    // 手動清空所有紀錄
    public function clear_all_logs() {
        if (!$this->ensure_table()) {
            return;
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    public function add_submenu_page() {
        add_submenu_page(
            'wu-toolbox-modular',
            '操作日誌',
            '操作日誌',
            'manage_options',
            'wu-audit-logger',
            array($this, 'settings_page')
        );
    }

    public function init_settings() {
        register_setting('wu_audit_logger_group', $this->option_name, array($this, 'sanitize_options'));

        add_settings_section('wu_audit_logger_section', '設定', '__return_false', 'wu-audit-logger');

        add_settings_field('enabled', '啟用功能', array($this, 'enabled_field'), 'wu-audit-logger', 'wu_audit_logger_section');
        add_settings_field('keep_days', '保留天數', array($this, 'keep_days_field'), 'wu-audit-logger', 'wu_audit_logger_section');
    }

    public function sanitize_options($input) {
        $sanitized = array();
        $sanitized['enabled'] = !empty($input['enabled']);
        $days = isset($input['keep_days']) ? intval($input['keep_days']) : 7;
        $sanitized['keep_days'] = in_array($days, array(3,7,14,31), true) ? $days : 7;
        return $sanitized;
    }

    public function enabled_field() {
        $enabled = !empty($this->options['enabled']);
        echo '<label><input type="checkbox" name="' . esc_attr($this->option_name) . '[enabled]" value="1" ' . checked($enabled, true, false) . '> 啟用追蹤</label>';
    }

    public function keep_days_field() {
        $current = isset($this->options['keep_days']) ? intval($this->options['keep_days']) : 7;
        $options = array(3,7,14,31);
        echo '<select name="' . esc_attr($this->option_name) . '[keep_days]">';
        foreach ($options as $d) {
            echo '<option value="' . intval($d) . '" ' . selected($current, $d, false) . '>' . intval($d) . ' 天</option>';
        }
        echo '</select>';
    }

    private function get_user_id() {
        $user_id = get_current_user_id();
        return $user_id ? intval($user_id) : null;
    }

    private function get_ip_address() {
        // 優化 IP 獲取，減少不必要的處理
        static $ip_cache = null;
        if ($ip_cache !== null) return $ip_cache;
        
        $keys = array('HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR');
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$k]));
                $parts = explode(',', $raw);
                $ip_cache = trim($parts[0]);
                return $ip_cache;
            }
        }
        $ip_cache = null;
        return $ip_cache;
    }

    private function insert_log($action, $object_type = null, $object_id = null, $object_name = null, $user_id = null) {
        if (!$this->ensure_table()) {
            return;
        }

        global $wpdb;
        
        // 優化：批次插入以提高效能
        $data = array(
            'log_time' => current_time('mysql', true),
            'user_id' => is_null($user_id) ? $this->get_user_id() : $user_id,
            'action' => sanitize_text_field($action),
            'object_type' => is_null($object_type) ? null : sanitize_text_field($object_type),
            'object_id' => is_null($object_id) ? null : intval($object_id),
            'object_name' => is_null($object_name) ? null : (is_string($object_name) ? mb_substr($object_name, 0, 191) : null),
            'ip_address' => $this->get_ip_address(),
        );
        
        $format = array('%s','%d','%s','%s','%d','%s','%s');
        $wpdb->insert($this->table_name, $data, $format);
    }

    public function register_hooks() {
        // 優化：使用較少的 hook 並減少處理
        
        // Posts & Pages
        add_action('save_post', function($post_id, $post, $update){
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
            $this->insert_log($update ? 'post_updated' : 'post_created', $post->post_type, $post_id, $post->post_title);
        }, 10, 3);
        
        add_action('before_delete_post', function($post_id){
            $post = get_post($post_id);
            if ($post && !wp_is_post_revision($post_id)) {
                $this->insert_log('post_deleted', $post->post_type, $post_id, $post->post_title);
            }
        });

        // Media
        add_action('add_attachment', function($post_id){
            $post = get_post($post_id);
            $this->insert_log('media_uploaded', 'attachment', $post_id, $post ? $post->post_title : null);
        });
        
        add_action('delete_attachment', function($post_id){
            $post = get_post($post_id);
            $this->insert_log('media_deleted', 'attachment', $post_id, $post ? $post->post_title : null);
        });

        // Plugins
        add_action('activated_plugin', function($plugin){
            $this->insert_log('plugin_activated', 'plugin', null, $plugin);
        });
        
        add_action('deactivated_plugin', function($plugin){
            $this->insert_log('plugin_deactivated', 'plugin', null, $plugin);
        });

        // Users
        add_action('user_register', function($user_id){
            $user = get_user_by('id', $user_id);
            $this->insert_log('user_created', 'user', $user_id, $user ? $user->user_login : null);
        });
        
        add_action('profile_update', function($user_id){
            $user = get_user_by('id', $user_id);
            $this->insert_log('user_updated', 'user', $user_id, $user ? $user->user_login : null);
        });

        // Logins
        add_action('wp_login', function($user_login, $user){
            $this->insert_log('user_login', 'user', $user->ID, $user_login, $user->ID);
        }, 10, 2);
        
        add_action('wp_login_failed', function($username){
            $this->insert_log('user_login_failed', 'user', null, $username);
        });
    }

    public function settings_page() {
        if (!$this->ensure_table()) {
            echo '<div class="notice notice-error"><p>無法建立操作日誌資料表，請確認資料庫帳號具備 CREATE TABLE 權限。</p></div>';
            return;
        }

        // 處理清空紀錄
        if (isset($_POST['clear_logs']) && check_admin_referer('wu_audit_logger_clear','wu_audit_logger_clear_nonce')) {
            $this->clear_all_logs();
            echo '<div class="updated"><p>所有紀錄已清空 ✅</p></div>';
        }

        // 處理設定儲存
        if (isset($_POST['submit']) && check_admin_referer('wu_audit_logger_save','wu_audit_logger_nonce')) {
            $raw = isset($_POST[$this->option_name]) ? (array) $_POST[$this->option_name] : array();
            $save = $this->sanitize_options($raw);
            update_option($this->option_name, $save);
            $this->options = $save;
            
            if (!empty($save['enabled'])) {
                $this->maybe_create_table();
                $this->maybe_schedule_cron();
            } else {
                $timestamp = wp_next_scheduled($this->cron_hook);
                if ($timestamp) wp_unschedule_event($timestamp, $this->cron_hook);
            }
            echo '<div class="updated"><p>設定已儲存 ✅</p></div>';
        }

        $current = $this->options;
        ?>
        <div class="wrap wutm-module-wrap wutm-audit-logger">
            <h1>操作日誌</h1>
            <p class="wutm-module-subtitle">記錄重要後台活動，提供可篩選、可清理的操作紀錄。</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('wu_audit_logger_save','wu_audit_logger_nonce'); ?>
                <?php settings_fields('wu_audit_logger_group'); ?>
                <?php do_settings_sections('wu-audit-logger'); ?>
                <?php submit_button('儲存設定'); ?>
            </form>

            <hr>

            <form method="post" action="" style="display:inline-block;">
                <?php wp_nonce_field('wu_audit_logger_clear','wu_audit_logger_clear_nonce'); ?>
                <input type="submit" name="clear_logs" class="button button-secondary" value="清空所有紀錄" onclick="return confirm('確定要清空所有紀錄嗎？此動作無法復原。');">
            </form>

            <h2 style="margin-top:30px;">紀錄查詢</h2>
            <?php $this->render_logs_table(); ?>
        </div>
        <?php
    }

    private function render_logs_table() {
        global $wpdb;
        
        // 獲取篩選參數
        $selected_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
        $selected_action = isset($_GET['filter_action']) ? sanitize_text_field($_GET['filter_action']) : '';
        $selected_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        $selected_limit = isset($_GET['filter_limit']) ? sanitize_text_field($_GET['filter_limit']) : 'unlimited';
        
        // 獲取所有有紀錄的使用者
        $users_with_logs = $wpdb->get_results("
            SELECT DISTINCT user_id, 
                   (SELECT user_login FROM {$wpdb->users} WHERE ID = l.user_id) as user_login
            FROM {$this->table_name} l 
            WHERE user_id IS NOT NULL 
            ORDER BY user_login
        ");

        // 獲取所有動作類型
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$this->table_name} ORDER BY action");
        
        // 獲取所有物件類型
        $object_types = $wpdb->get_col("SELECT DISTINCT object_type FROM {$this->table_name} WHERE object_type IS NOT NULL ORDER BY object_type");

        // 篩選表單
        echo '<form method="get" action="" style="margin-bottom: 20px;">';
        echo '<input type="hidden" name="page" value="wu-audit-logger">';
        echo '<div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">';
        
        // 使用者篩選
        echo '<div>';
        echo '<label>篩選使用者: ';
        echo '<select name="filter_user" onchange="this.form.submit()" style="min-width: 120px;">';
        echo '<option value="0">所有使用者</option>';
        foreach ($users_with_logs as $user) {
            if ($user->user_login) {
                $selected = selected($selected_user, $user->user_id, false);
                echo '<option value="' . intval($user->user_id) . '" ' . $selected . '>' . esc_html($user->user_login) . '</option>';
            }
        }
        echo '</select>';
        echo '</label>';
        echo '</div>';

        // 動作篩選
        echo '<div>';
        echo '<label>篩選動作: ';
        echo '<select name="filter_action" onchange="this.form.submit()" style="min-width: 120px;">';
        echo '<option value="">所有動作</option>';
        foreach ($actions as $action) {
            $selected_attr = selected($selected_action, $action, false);
            echo '<option value="' . esc_attr($action) . '" ' . $selected_attr . '>' . esc_html($action) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '</div>';

        // 類型篩選
        echo '<div>';
        echo '<label>篩選類型: ';
        echo '<select name="filter_type" onchange="this.form.submit()" style="min-width: 120px;">';
        echo '<option value="">所有類型</option>';
        foreach ($object_types as $type) {
            $selected_attr = selected($selected_type, $type, false);
            echo '<option value="' . esc_attr($type) . '" ' . $selected_attr . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '</div>';

        // 顯示數量篩選
        echo '<div>';
        echo '<label>顯示數量: ';
        echo '<select name="filter_limit" onchange="this.form.submit()" style="min-width: 100px;">';
        $limit_options = array(
            '50' => '50 筆',
            '100' => '100 筆', 
            '150' => '150 筆',
            '200' => '200 筆',
            'unlimited' => '不限制'
        );
        foreach ($limit_options as $value => $label) {
            $selected_attr = selected($selected_limit, $value, false);
            echo '<option value="' . esc_attr($value) . '" ' . $selected_attr . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '</div>';

        // 清除篩選按鈕
        if ($selected_user || $selected_action || $selected_type || $selected_limit !== 'unlimited') {
            echo '<div>';
            echo '<a href="?page=wu-audit-logger" class="button">清除篩選</a>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</form>';

        // 準備查詢條件
        $where_conditions = array();
        $where_values = array();
        
        if ($selected_user) {
            $where_conditions[] = 'user_id = %d';
            $where_values[] = $selected_user;
        }
        
        if ($selected_action) {
            $where_conditions[] = 'action = %s';
            $where_values[] = $selected_action;
        }
        
        if ($selected_type) {
            $where_conditions[] = 'object_type = %s';
            $where_values[] = $selected_type;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // 準備 LIMIT 子句
        $limit_clause = '';
        if ($selected_limit !== 'unlimited') {
            $limit = intval($selected_limit);
            $limit_clause = " LIMIT {$limit}";
        }

        // 執行查詢
        $sql = "SELECT * FROM {$this->table_name}{$where_clause} ORDER BY id DESC{$limit_clause}";
        
        if (!empty($where_values)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $where_values));
        } else {
            $rows = $wpdb->get_results($sql);
        }
        
        // 獲取總記錄數（用於顯示統計資訊）
        $count_sql = "SELECT COUNT(*) FROM {$this->table_name}{$where_clause}";
        if (!empty($where_values)) {
            $total_count = $wpdb->get_var($wpdb->prepare($count_sql, $where_values));
        } else {
            $total_count = $wpdb->get_var($count_sql);
        }

        if (empty($rows)) {
            echo '<p>目前沒有符合條件的紀錄。</p>';
            return;
        }

        // 顯示統計資訊
        $showing_count = count($rows);
        if ($selected_limit !== 'unlimited') {
            echo '<p><strong>顯示 ' . $showing_count . ' 筆紀錄（共 ' . $total_count . ' 筆）</strong></p>';
        } else {
            echo '<p><strong>顯示全部 ' . $total_count . ' 筆紀錄</strong></p>';
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>時間</th><th>使用者</th><th>動作</th><th>類型</th><th>目標</th><th>IP</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($rows as $r) {
            $user_display = $r->user_id ? esc_html(get_the_author_meta('user_login', $r->user_id)) : '-';
            
            echo '<tr>';
            echo '<td>' . esc_html(get_date_from_gmt($r->log_time, 'Y-m-d H:i:s')) . '</td>';
            echo '<td>' . $user_display . '</td>';
            echo '<td>' . esc_html($r->action) . '</td>';
            echo '<td>' . esc_html($r->object_type ?: '-') . '</td>';
            echo '<td>' . esc_html(trim(($r->object_name ? $r->object_name . ' ' : '') . ($r->object_id ? "(#{$r->object_id})" : ''))) . '</td>';
            echo '<td>' . esc_html($r->ip_address ?: '-') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

new WU_Audit_Logger();
