<?php
/**
 * Module: login-limiter
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 登入嘗試限制模組
 * 防止暴力破解攻擊，限制每個IP的登入嘗試次數
 */

if (!defined('ABSPATH')) exit;

class WU_Login_Limiter {
    
    private $table_name;
    private $settings;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wu_login_attempts';
        $this->settings = get_option('wu_login_limiter_settings', $this->get_default_settings());
        
        add_action('admin_init', array($this, 'init_database'));
        add_action('admin_menu', array($this, 'add_admin_menu'), 55);
        add_action('wp_login_failed', array($this, 'log_failed_attempt'));
        add_action('wp_login', array($this, 'clear_failed_attempts'), 10, 2);
        add_filter('authenticate', array($this, 'check_ip_blocked'), 30, 3);
        add_filter('wp_login_errors', array($this, 'customize_error_message'));
        add_action('login_enqueue_scripts', array($this, 'add_login_page_info'));
        
        // 定期清理過期記錄
        add_action('wp_loaded', array($this, 'schedule_cleanup'));
        add_action('wu_login_limiter_cleanup', array($this, 'cleanup_old_records'));
    }
    
    private function get_default_settings() {
        return array(
            'enabled' => false,
            'max_attempts' => 3,
            'max_locks' => 3,
            'lock_duration' => 1800, // 30分鐘
            'extended_lock_duration' => 86400, // 24小時
            'log_attempts' => true,
            'whitelist' => array(),
            'blacklist' => array()
        );
    }
    
    public function init_database() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            ip varchar(45) NOT NULL,
            username varchar(60) NOT NULL,
            attempt_time datetime DEFAULT CURRENT_TIMESTAMP,
            blocked_until datetime NULL,
            lock_count int(11) DEFAULT 0,
            is_blocked tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY ip (ip),
            KEY blocked_until (blocked_until)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '登入嘗試限制',
            '登入嘗試限制',
            'manage_options',
            'wu-login-limiter',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        if (isset($_POST['clear_logs'])) {
            $this->clear_all_logs();
        }
        
        if (isset($_POST['unblock_ip']) && isset($_POST['ip_to_unblock'])) {
            $this->unblock_ip($_POST['ip_to_unblock']);
        }
        
        $this->settings = get_option('wu_login_limiter_settings', $this->get_default_settings());
        $recent_attempts = $this->get_recent_attempts();
        $blocked_ips = $this->get_blocked_ips();
        ?>
        <div class="wrap">
            <h1>登入嘗試限制設定</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wu_login_limiter_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用功能</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($this->settings['enabled']); ?>>
                                啟用登入嘗試限制
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">最大失敗次數</th>
                        <td>
                            <input type="number" name="max_attempts" value="<?php echo esc_attr($this->settings['max_attempts']); ?>" min="1" max="20">
                            <p class="description">首次阻擋前允許的失敗次數 (預設: 3)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">最大鎖定次數</th>
                        <td>
                            <input type="number" name="max_locks" value="<?php echo esc_attr($this->settings['max_locks']); ?>" min="1" max="10">
                            <p class="description">延長鎖定前的最大鎖定次數 (預設: 3)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">鎖定時間 (秒)</th>
                        <td>
                            <input type="number" name="lock_duration" value="<?php echo esc_attr($this->settings['lock_duration']); ?>" min="300" max="86400">
                            <p class="description">普通鎖定持續時間 (預設: 1800秒 = 30分鐘)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">延長鎖定時間 (秒)</th>
                        <td>
                            <input type="number" name="extended_lock_duration" value="<?php echo esc_attr($this->settings['extended_lock_duration']); ?>" min="3600" max="604800">
                            <p class="description">延長鎖定持續時間 (預設: 86400秒 = 24小時)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">記錄失敗嘗試</th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_attempts" value="1" <?php checked($this->settings['log_attempts']); ?>>
                                記錄所有失敗的登入嘗試
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">IP白名單</th>
                        <td>
                            <textarea name="whitelist" rows="5" cols="50" placeholder="每行一個IP，例如：&#10;192.168.1.1&#10;10.0.0.0/8"><?php echo esc_textarea(implode("\n", $this->settings['whitelist'])); ?></textarea>
                            <p class="description">白名單中的IP不會被阻擋</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">IP黑名單</th>
                        <td>
                            <textarea name="blacklist" rows="5" cols="50" placeholder="每行一個IP，例如：&#10;192.168.1.100&#10;203.0.113.0/24"><?php echo esc_textarea(implode("\n", $this->settings['blacklist'])); ?></textarea>
                            <p class="description">黑名單中的IP會被永久阻擋</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('儲存設定'); ?>
            </form>
            
            <hr>
            
            <h2>統計資訊</h2>
            <div style="display: flex; gap: 20px; margin: 20px 0;">
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                    <strong>總失敗嘗試次數：</strong> <?php echo $this->get_total_attempts(); ?>
                </div>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                    <strong>目前被阻擋的IP：</strong> <?php echo count($blocked_ips); ?>
                </div>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                    <strong>24小時內嘗試：</strong> <?php echo $this->get_attempts_in_period(24); ?>
                </div>
            </div>
            
            <?php if (!empty($blocked_ips)): ?>
            <h3>目前被阻擋的IP</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>IP位址</th>
                        <th>阻擋到期時間</th>
                        <th>鎖定次數</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blocked_ips as $blocked_ip): ?>
                    <tr>
                        <td><?php echo esc_html($blocked_ip->ip); ?></td>
                        <td><?php echo esc_html($blocked_ip->blocked_until); ?></td>
                        <td><?php echo esc_html($blocked_ip->lock_count); ?></td>
                        <td>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('wu_login_limiter_settings'); ?>
                                <input type="hidden" name="ip_to_unblock" value="<?php echo esc_attr($blocked_ip->ip); ?>">
                                <input type="submit" name="unblock_ip" value="解除阻擋" class="button-secondary" onclick="return confirm('確定要解除此IP的阻擋嗎？');">
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <h3>最近的登入嘗試</h3>
            <form method="post" style="margin: 10px 0;">
                <?php wp_nonce_field('wu_login_limiter_settings'); ?>
                <input type="submit" name="clear_logs" value="清除所有日誌" class="button-secondary" onclick="return confirm('確定要清除所有登入嘗試日誌嗎？');">
            </form>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>時間</th>
                        <th>IP位址</th>
                        <th>用戶名</th>
                        <th>狀態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_attempts)): ?>
                    <tr>
                        <td colspan="4">暫無登入嘗試記錄</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recent_attempts as $attempt): ?>
                    <tr>
                        <td><?php echo esc_html($attempt->attempt_time); ?></td>
                        <td><?php echo esc_html($attempt->ip); ?></td>
                        <td><?php echo esc_html($attempt->username); ?></td>
                        <td>
                            <?php if ($attempt->is_blocked): ?>
                                <span style="color: red;">已阻擋</span>
                            <?php else: ?>
                                <span style="color: orange;">失敗</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .form-table th {
            width: 200px;
        }
        .wp-list-table {
            margin-top: 10px;
        }
        </style>
        <?php
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_login_limiter_settings')) {
            wp_die('安全驗證失敗');
        }
        
        $settings = array(
            'enabled' => isset($_POST['enabled']),
            'max_attempts' => intval($_POST['max_attempts']),
            'max_locks' => intval($_POST['max_locks']),
            'lock_duration' => intval($_POST['lock_duration']),
            'extended_lock_duration' => intval($_POST['extended_lock_duration']),
            'log_attempts' => isset($_POST['log_attempts']),
            'whitelist' => array_filter(array_map('trim', explode("\n", $_POST['whitelist']))),
            'blacklist' => array_filter(array_map('trim', explode("\n", $_POST['blacklist'])))
        );
        
        update_option('wu_login_limiter_settings', $settings);
        $this->settings = $settings;
        
        echo '<div class="notice notice-success"><p>設定已儲存！</p></div>';
    }
    
    public function log_failed_attempt($username) {
        if (!$this->settings['enabled'] || !$this->settings['log_attempts']) {
            return;
        }
        
        $ip = $this->get_client_ip();
        
        // 檢查白名單
        if ($this->is_ip_whitelisted($ip)) {
            return;
        }
        
        global $wpdb;
        
        // 記錄失敗嘗試
        $wpdb->insert(
            $this->table_name,
            array(
                'ip' => $ip,
                'username' => $username,
                'attempt_time' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
        
        // 檢查是否需要阻擋
        $this->check_and_block_ip($ip);
    }
    
    private function check_and_block_ip($ip) {
        global $wpdb;
        
        // 獲取最近的失敗次數
        $recent_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE ip = %s AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip
        ));
        
        if ($recent_attempts >= $this->settings['max_attempts']) {
            // 檢查之前的鎖定次數
            $lock_count = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(lock_count) FROM {$this->table_name} WHERE ip = %s",
                $ip
            )) ?: 0;
            
            $lock_count++;
            
            // 確定鎖定時間
            $lock_duration = ($lock_count >= $this->settings['max_locks']) 
                ? $this->settings['extended_lock_duration'] 
                : $this->settings['lock_duration'];
            
            $blocked_until = date('Y-m-d H:i:s', time() + $lock_duration);
            
            // 更新或插入阻擋記錄
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->table_name} (ip, username, attempt_time, blocked_until, lock_count, is_blocked) 
                 VALUES (%s, 'BLOCKED', NOW(), %s, %d, 1)
                 ON DUPLICATE KEY UPDATE blocked_until = %s, lock_count = %d, is_blocked = 1",
                $ip, $blocked_until, $lock_count, $blocked_until, $lock_count
            ));
        }
    }
    
    public function check_ip_blocked($user, $username, $password) {
        if (!$this->settings['enabled']) {
            return $user;
        }
        
        $ip = $this->get_client_ip();
        
        // 檢查黑名單
        if ($this->is_ip_blacklisted($ip)) {
            return new WP_Error('ip_blacklisted', '您的IP位址已被永久阻擋。');
        }
        
        // 檢查白名單
        if ($this->is_ip_whitelisted($ip)) {
            return $user;
        }
        
        // 檢查是否被阻擋
        if ($this->is_ip_currently_blocked($ip)) {
            $remaining_time = $this->get_remaining_block_time($ip);
            return new WP_Error('ip_blocked', sprintf(
                '您的IP位址已被暫時阻擋。請在 %s 後重試。',
                $this->format_time_remaining($remaining_time)
            ));
        }
        
        return $user;
    }
    
    public function clear_failed_attempts($user_login, $user) {
        if (!$this->settings['enabled']) {
            return;
        }
        
        $ip = $this->get_client_ip();
        
        global $wpdb;
        
        // 清除該IP的失敗記錄（但保留阻擋記錄用於統計）
        $wpdb->delete(
            $this->table_name,
            array(
                'ip' => $ip,
                'is_blocked' => 0
            ),
            array('%s', '%d')
        );
    }
    
    public function customize_error_message($errors) {
        if (!$this->settings['enabled']) {
            return $errors;
        }
        
        $ip = $this->get_client_ip();
        
        if ($this->is_ip_currently_blocked($ip)) {
            $remaining_time = $this->get_remaining_block_time($ip);
            $errors->add('ip_blocked', sprintf(
                '<strong>錯誤</strong>：登入嘗試過於頻繁。請等待 %s 後重試。',
                $this->format_time_remaining($remaining_time)
            ));
        } else {
            // 顯示剩餘嘗試次數
            $remaining_attempts = $this->get_remaining_attempts($ip);
            if ($remaining_attempts > 0 && $remaining_attempts < $this->settings['max_attempts']) {
                $errors->add('attempts_warning', sprintf(
                    '<strong>警告</strong>：您還有 %d 次嘗試機會。',
                    $remaining_attempts
                ));
            }
        }
        
        return $errors;
    }
    
    public function add_login_page_info() {
        if (!$this->settings['enabled']) {
            return;
        }
        
        $ip = $this->get_client_ip();
        
        if ($this->is_ip_currently_blocked($ip)) {
            $remaining_time = $this->get_remaining_block_time($ip);
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var loginForm = document.getElementById('loginform');
                if (loginForm) {
                    var notice = document.createElement('div');
                    notice.className = 'message';
                    notice.style.cssText = 'background: #ffebcd; border-left: 4px solid #ff8c00; padding: 12px; margin: 15px 0;';
                    notice.innerHTML = '<strong>注意：</strong>您的IP已被暫時阻擋。剩餘時間：<?php echo $this->format_time_remaining($remaining_time); ?>';
                    loginForm.parentNode.insertBefore(notice, loginForm);
                }
            });
            </script>
            <?php
        } else {
            $remaining_attempts = $this->get_remaining_attempts($ip);
            if ($remaining_attempts < $this->settings['max_attempts']) {
                ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var loginForm = document.getElementById('loginform');
                    if (loginForm) {
                        var notice = document.createElement('div');
                        notice.className = 'message';
                        notice.style.cssText = 'background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0;';
                        notice.innerHTML = '<strong>提醒：</strong>您還有 <?php echo $remaining_attempts; ?> 次登入嘗試機會。';
                        loginForm.parentNode.insertBefore(notice, loginForm);
                    }
                });
                </script>
                <?php
            }
        }
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    private function is_ip_whitelisted($ip) {
        foreach ($this->settings['whitelist'] as $whitelist_ip) {
            if ($this->ip_in_range($ip, $whitelist_ip)) {
                return true;
            }
        }
        return false;
    }
    
    private function is_ip_blacklisted($ip) {
        foreach ($this->settings['blacklist'] as $blacklist_ip) {
            if ($this->ip_in_range($ip, $blacklist_ip)) {
                return true;
            }
        }
        return false;
    }
    
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $mask) = explode('/', $range);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - $mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }
    
    private function is_ip_currently_blocked($ip) {
        global $wpdb;
        
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE ip = %s AND is_blocked = 1 AND blocked_until > NOW()",
            $ip
        ));
        
        return $blocked > 0;
    }
    
    private function get_remaining_block_time($ip) {
        global $wpdb;
        
        $blocked_until = $wpdb->get_var($wpdb->prepare(
            "SELECT blocked_until FROM {$this->table_name} 
             WHERE ip = %s AND is_blocked = 1 AND blocked_until > NOW() 
             ORDER BY blocked_until DESC LIMIT 1",
            $ip
        ));
        
        if ($blocked_until) {
            return strtotime($blocked_until) - time();
        }
        
        return 0;
    }
    
    private function get_remaining_attempts($ip) {
        global $wpdb;
        
        $recent_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE ip = %s AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND is_blocked = 0",
            $ip
        ));
        
        return max(0, $this->settings['max_attempts'] - $recent_attempts);
    }
    
    private function format_time_remaining($seconds) {
        if ($seconds <= 0) return '0秒';
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        $result = array();
        if ($hours > 0) $result[] = $hours . '小時';
        if ($minutes > 0) $result[] = $minutes . '分鐘';
        if ($seconds > 0) $result[] = $seconds . '秒';
        
        return implode('', $result);
    }
    
    private function get_recent_attempts($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             ORDER BY attempt_time DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    private function get_blocked_ips() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT ip, blocked_until, lock_count FROM {$this->table_name} 
             WHERE is_blocked = 1 AND blocked_until > NOW() 
             GROUP BY ip 
             ORDER BY blocked_until DESC"
        );
    }
    
    private function get_total_attempts() {
        global $wpdb;
        
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    private function get_attempts_in_period($hours) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE attempt_time > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hours
        ));
    }
    
    private function clear_all_logs() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_login_limiter_settings')) {
            wp_die('安全驗證失敗');
        }
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        echo '<div class="notice notice-success"><p>所有日誌已清除！</p></div>';
    }
    
    private function unblock_ip($ip) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_login_limiter_settings')) {
            wp_die('安全驗證失敗');
        }
        
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            array(
                'is_blocked' => 0,
                'blocked_until' => null
            ),
            array('ip' => $ip),
            array('%d', '%s'),
            array('%s')
        );
        
        echo '<div class="notice notice-success"><p>IP ' . esc_html($ip) . ' 已解除阻擋！</p></div>';
    }
    
    public function schedule_cleanup() {
        if (!wp_next_scheduled('wu_login_limiter_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wu_login_limiter_cleanup');
        }
    }
    
    public function cleanup_old_records() {
        global $wpdb;
        
        // 清除30天前的記錄
        $wpdb->query(
            "DELETE FROM {$this->table_name} 
             WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY) 
             AND (blocked_until IS NULL OR blocked_until < NOW())"
        );
    }
}

// 初始化模組
new WU_Login_Limiter();