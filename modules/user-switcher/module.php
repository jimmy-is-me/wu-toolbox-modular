<?php
/**
 * Module: user-switcher
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * 使用者切換模組
 * 幫助管理員快速輕鬆地在 WordPress 使用者帳戶之間切換
 */

if (!defined('ABSPATH')) exit;

class WU_User_Switcher {
    
    private $settings;
    private $original_user_id;
    
    public function __construct() {
        $this->settings = get_option('wu_user_switcher_settings', $this->get_default_settings());
        
        add_action('admin_menu', array($this, 'add_admin_menu'), 90);
        add_filter('user_row_actions', array($this, 'add_switch_link'), 10, 2);
        add_action('admin_action_wu_switch_to_user', array($this, 'switch_to_user'));
        add_action('admin_action_wu_switch_back', array($this, 'switch_back'));
        add_action('wp_ajax_wu_switch_user', array($this, 'ajax_switch_user'));
        
        // 管理欄整合
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // 權限控制
        add_filter('user_has_cap', array($this, 'filter_user_capabilities'), 10, 4);
        add_filter('map_meta_cap', array($this, 'filter_meta_capabilities'), 10, 4);
        
        // WooCommerce 相容性
        add_action('wu_user_switched', array($this, 'handle_woocommerce_session'));
        
        // 安全措施
        add_action('wp_login', array($this, 'clear_switch_cookie'), 10, 2);
        add_action('wp_logout', array($this, 'clear_switch_cookie'));
    }
    
    private function get_default_settings() {
        return array(
            'enabled' => false,
            'allow_switch_back' => true,
            'show_in_admin_bar' => true,
            'allowed_roles' => array('administrator'),
            'restricted_users' => array(),
            'clear_wc_session' => true,
            'log_switches' => true,
            'session_timeout' => 3600 // 1 hour
        );
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'wumetax-toolkit',
            '使用者切換器',
            '使用者切換器',
            'manage_options',
            'wu-user-switcher',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $this->settings = get_option('wu_user_switcher_settings', $this->get_default_settings());
        $recent_switches = $this->get_recent_switches();
        ?>
        <div class="wrap">
            <h1>使用者切換設定</h1>
            
            <div class="notice notice-info">
                <h3>功能說明</h3>
                <p><strong>使用者切換功能</strong>讓管理員可以快速在不同用戶帳戶之間切換，無需重新登入，方便測試和支援。</p>
                
                <h4>主要功能：</h4>
                <ul>
                    <li><strong>即時切換</strong>：在用戶列表中點擊即可切換到該用戶</li>
                    <li><strong>快速返回</strong>：一鍵返回到原始管理員帳戶</li>
                    <li><strong>管理欄整合</strong>：在管理欄顯示切換選項</li>
                    <li><strong>權限保留</strong>：嚴格控制切換權限</li>
                </ul>
                
                <h4>安全措施：</h4>
                <ul>
                    <li>只有授權用戶可以進行切換</li>
                    <li>防止切換到受限制的用戶</li>
                    <li>自動記錄所有切換操作</li>
                    <li>會話超時自動清除</li>
                </ul>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wu_user_switcher_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用功能</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($this->settings['enabled']); ?>>
                                啟用使用者切換功能
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">允許返回原用戶</th>
                        <td>
                            <label>
                                <input type="checkbox" name="allow_switch_back" value="1" <?php checked($this->settings['allow_switch_back']); ?>>
                                顯示「返回原用戶」選項
                            </label>
                            <p class="description">允許用戶快速返回到原始帳戶</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">管理欄顯示</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_in_admin_bar" value="1" <?php checked($this->settings['show_in_admin_bar']); ?>>
                                在管理欄中顯示切換選項
                            </label>
                            <p class="description">在前台和後台管理欄中顯示用戶切換功能</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">清除 WooCommerce 會話</th>
                        <td>
                            <label>
                                <input type="checkbox" name="clear_wc_session" value="1" <?php checked($this->settings['clear_wc_session']); ?>>
                                切換時清除 WooCommerce 會話資料
                            </label>
                            <p class="description">避免購物車和會話資料衝突</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">記錄切換操作</th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_switches" value="1" <?php checked($this->settings['log_switches']); ?>>
                                記錄所有用戶切換操作
                            </label>
                            <p class="description">為安全和審計目的記錄切換日誌</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">會話超時時間（秒）</th>
                        <td>
                            <input type="number" name="session_timeout" value="<?php echo esc_attr($this->settings['session_timeout']); ?>" min="300" max="86400" class="regular-text">
                            <p class="description">切換會話的超時時間（預設：3600秒 = 1小時）</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">允許切換的角色</th>
                        <td>
                            <?php $roles = wp_roles()->get_names(); ?>
                            <?php foreach ($roles as $role_key => $role_name): ?>
                            <label style="display: block; margin: 5px 0;">
                                <input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr($role_key); ?>" 
                                       <?php checked(in_array($role_key, $this->settings['allowed_roles'])); ?>>
                                <?php echo esc_html($role_name); ?> (<?php echo esc_html($role_key); ?>)
                            </label>
                            <?php endforeach; ?>
                            <p class="description">只有這些角色的用戶可以執行切換操作</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">受限制的用戶 ID</th>
                        <td>
                            <textarea name="restricted_users" rows="5" cols="50" placeholder="每行一個用戶ID，例如：&#10;1&#10;2&#10;3"><?php echo esc_textarea(implode("\n", $this->settings['restricted_users'])); ?></textarea>
                            <p class="description">這些用戶無法被切換（一行一個ID）</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('儲存設定'); ?>
            </form>
            
            <hr>
            
            <h2>統計資訊</h2>
            <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; min-width: 200px;">
                    <strong>今日切換次數：</strong><br>
                    <span style="font-size: 24px; color: #0073aa;"><?php echo $this->get_switch_count('day'); ?></span>
                </div>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; min-width: 200px;">
                    <strong>本週切換次數：</strong><br>
                    <span style="font-size: 24px; color: #46b450;"><?php echo $this->get_switch_count('week'); ?></span>
                </div>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; min-width: 200px;">
                    <strong>總切換次數：</strong><br>
                    <span style="font-size: 24px; color: #ff8c00;"><?php echo $this->get_switch_count('total'); ?></span>
                </div>
            </div>
            
            <?php if ($this->is_switched_user()): ?>
            <div class="notice notice-warning">
                <h3>目前切換狀態</h3>
                <p>您目前已切換到用戶：<strong><?php echo esc_html(wp_get_current_user()->display_name); ?></strong></p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?action=wu_switch_back'), 'wu_switch_back'); ?>" class="button button-primary">
                        返回原用戶
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <h2>最近的切換記錄</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <?php if (!empty($recent_switches)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>時間</th>
                            <th>操作用戶</th>
                            <th>目標用戶</th>
                            <th>操作類型</th>
                            <th>IP 位址</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_switches as $switch): ?>
                        <tr>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($switch['timestamp']))); ?></td>
                            <td>
                                <?php
                                $operator = get_user_by('ID', $switch['operator_id']);
                                echo $operator ? esc_html($operator->display_name) : '未知用戶';
                                ?>
                            </td>
                            <td>
                                <?php
                                $target = get_user_by('ID', $switch['target_id']);
                                echo $target ? esc_html($target->display_name) : '未知用戶';
                                ?>
                            </td>
                            <td>
                                <span style="color: <?php echo $switch['action'] === 'switch_to' ? '#0073aa' : '#46b450'; ?>;">
                                    <?php echo $switch['action'] === 'switch_to' ? '切換到' : '返回'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($switch['ip_address']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>暫無切換記錄</p>
                <?php endif; ?>
            </div>
            
            <h2>使用說明</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <h3>如何使用用戶切換功能：</h3>
                <ol>
                    <li><strong>在用戶列表中切換</strong>：前往「用戶」→「所有用戶」，點擊用戶旁邊的「切換到此用戶」連結</li>
                    <li><strong>透過管理欄切換</strong>：在管理欄中找到用戶切換選項</li>
                    <li><strong>返回原用戶</strong>：切換後可透過管理欄或用戶列表的「返回原用戶」連結</li>
                    <li><strong>查看切換狀態</strong>：管理欄會顯示目前的切換狀態</li>
                </ol>
                
                <h3>權限說明：</h3>
                <div style="display: flex; gap: 30px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h4>可以切換的用戶：</h4>
                        <ul>
                            <li>擁有允許角色的用戶</li>
                            <li>未被限制的用戶</li>
                            <li>具有適當權限的管理員</li>
                        </ul>
                    </div>
                    <div style="flex: 1;">
                        <h4>安全限制：</h4>
                        <ul>
                            <li>無法切換到受限制的用戶</li>
                            <li>無法切換到自己的帳戶</li>
                            <li>會話會自動超時</li>
                            <li>所有操作都會被記錄</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .form-table th {
            width: 200px;
        }
        .wp-list-table {
            margin-top: 10px;
        }
        .notice {
            padding: 10px;
            margin: 15px 0;
            border-left: 4px solid;
            background: #fff;
        }
        .notice-info {
            border-left-color: #0073aa;
        }
        .notice-warning {
            border-left-color: #ff8c00;
        }
        </style>
        <?php
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_user_switcher_settings')) {
            wp_die('安全驗證失敗');
        }
        
        $allowed_roles = isset($_POST['allowed_roles']) ? array_map('sanitize_text_field', $_POST['allowed_roles']) : array();
        $restricted_users = array_filter(array_map('intval', explode("\n", $_POST['restricted_users'])));
        
        $settings = array(
            'enabled' => isset($_POST['enabled']),
            'allow_switch_back' => isset($_POST['allow_switch_back']),
            'show_in_admin_bar' => isset($_POST['show_in_admin_bar']),
            'allowed_roles' => $allowed_roles,
            'restricted_users' => $restricted_users,
            'clear_wc_session' => isset($_POST['clear_wc_session']),
            'log_switches' => isset($_POST['log_switches']),
            'session_timeout' => intval($_POST['session_timeout'])
        );
        
        update_option('wu_user_switcher_settings', $settings);
        $this->settings = $settings;
        
        echo '<div class="notice notice-success"><p>設定已儲存！</p></div>';
    }
    
    public function add_switch_link($actions, $user) {
        if (!$this->settings['enabled']) {
            return $actions;
        }
        
        if (!$this->user_can_switch()) {
            return $actions;
        }
        
        if (!$this->can_switch_to_user($user->ID)) {
            return $actions;
        }
        
        $switch_url = wp_nonce_url(
            admin_url('admin.php?action=wu_switch_to_user&user=' . $user->ID),
            'wu_switch_to_user_' . $user->ID
        );
        
        $actions['switch_to_user'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($switch_url),
            esc_attr__('切換到此用戶'),
            __('切換到此用戶')
        );
        
        return $actions;
    }
    
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!$this->settings['enabled'] || !$this->settings['show_in_admin_bar']) {
            return;
        }
        
        if (!$this->user_can_switch()) {
            return;
        }
        
        // 如果目前是切換狀態，顯示返回選項
        if ($this->is_switched_user()) {
            $original_user = $this->get_original_user();
            if ($original_user && $this->settings['allow_switch_back']) {
                $wp_admin_bar->add_node(array(
                    'id' => 'wu-switch-back',
                    'title' => sprintf('返回 %s', $original_user->display_name),
                    'href' => wp_nonce_url(admin_url('admin.php?action=wu_switch_back'), 'wu_switch_back'),
                    'meta' => array(
                        'class' => 'wu-switch-back'
                    )
                ));
            }
        }
        
        // 顯示目前用戶狀態
        $current_user = wp_get_current_user();
        $title = $this->is_switched_user() ? 
            sprintf('已切換到: %s', $current_user->display_name) : 
            sprintf('當前用戶: %s', $current_user->display_name);
        
        $wp_admin_bar->add_node(array(
            'id' => 'wu-user-status',
            'title' => $title,
            'meta' => array(
                'class' => $this->is_switched_user() ? 'wu-switched-user' : 'wu-normal-user'
            )
        ));
    }
    
    public function enqueue_scripts() {
        if (!$this->settings['enabled'] || !$this->settings['show_in_admin_bar']) {
            return;
        }
        
        ?>
        <style>
        #wp-admin-bar-wu-switch-back a {
            background: #ff8c00 !important;
            color: #fff !important;
        }
        #wp-admin-bar-wu-switch-back:hover a {
            background: #e67e00 !important;
        }
        #wp-admin-bar-wu-user-status.wu-switched-user a {
            background: #0073aa !important;
            color: #fff !important;
        }
        </style>
        <?php
    }
    
    public function switch_to_user() {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wu_switch_to_user_' . $_GET['user'])) {
            wp_die('安全驗證失敗');
        }
        
        if (!$this->user_can_switch()) {
            wp_die('權限不足');
        }
        
        $user_id = intval($_GET['user']);
        
        if (!$this->can_switch_to_user($user_id)) {
            wp_die('無法切換到此用戶');
        }
        
        $this->perform_switch($user_id);
        
        // 重定向到適當的頁面
        $redirect_url = is_admin() ? admin_url() : home_url();
        wp_redirect($redirect_url);
        exit;
    }
    
    public function switch_back() {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wu_switch_back')) {
            wp_die('安全驗證失敗');
        }
        
        if (!$this->is_switched_user()) {
            wp_die('您目前不在切換狀態');
        }
        
        $original_user = $this->get_original_user();
        if (!$original_user) {
            wp_die('無法找到原始用戶');
        }
        
        $this->perform_switch_back($original_user->ID);
        
        $redirect_url = is_admin() ? admin_url() : home_url();
        wp_redirect($redirect_url);
        exit;
    }
    
    public function ajax_switch_user() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_switch_user')) {
            wp_die('安全驗證失敗');
        }
        
        if (!$this->user_can_switch()) {
            wp_die('權限不足');
        }
        
        $user_id = intval($_POST['user_id']);
        
        if (!$this->can_switch_to_user($user_id)) {
            wp_send_json_error('無法切換到此用戶');
        }
        
        $this->perform_switch($user_id);
        
        wp_send_json_success(array(
            'message' => '切換成功',
            'redirect_url' => admin_url()
        ));
    }
    
    private function perform_switch($user_id) {
        $target_user = get_user_by('ID', $user_id);
        if (!$target_user) {
            return false;
        }
        
        // 保存原始用戶資訊
        if (!$this->is_switched_user()) {
            $this->set_original_user(wp_get_current_user());
        }
        
        // 清除 WooCommerce 會話
        if ($this->settings['clear_wc_session']) {
            $this->clear_woocommerce_session();
        }
        
        // 設定新的用戶會話
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // 記錄切換操作
        if ($this->settings['log_switches']) {
            $this->log_switch_action('switch_to', $user_id);
        }
        
        // 觸發切換事件
        do_action('wu_user_switched', $user_id, $this->get_original_user_id());
        
        return true;
    }
    
    private function perform_switch_back($original_user_id) {
        // 清除 WooCommerce 會話
        if ($this->settings['clear_wc_session']) {
            $this->clear_woocommerce_session();
        }
        
        // 返回原始用戶
        wp_set_current_user($original_user_id);
        wp_set_auth_cookie($original_user_id);
        
        // 記錄返回操作
        if ($this->settings['log_switches']) {
            $this->log_switch_action('switch_back', $original_user_id);
        }
        
        // 清除切換資訊
        $this->clear_switch_cookie();
        
        // 觸發返回事件
        do_action('wu_user_switched_back', $original_user_id);
        
        return true;
    }
    
    private function user_can_switch() {
        $current_user = wp_get_current_user();
        
        if (!$current_user || !$current_user->exists()) {
            return false;
        }
        
        // 檢查角色權限
        $user_roles = $current_user->roles;
        $allowed_roles = $this->settings['allowed_roles'];
        
        return !empty(array_intersect($user_roles, $allowed_roles));
    }
    
    private function can_switch_to_user($user_id) {
        $current_user_id = get_current_user_id();
        
        // 不能切換到自己
        if ($user_id === $current_user_id) {
            return false;
        }
        
        // 檢查是否在受限制列表中
        if (in_array($user_id, $this->settings['restricted_users'])) {
            return false;
        }
        
        // 檢查目標用戶是否存在
        $target_user = get_user_by('ID', $user_id);
        if (!$target_user) {
            return false;
        }
        
        return true;
    }
    
    private function is_switched_user() {
        return isset($_COOKIE['wu_switched_user']) && $this->get_original_user_id();
    }
    
    private function get_original_user() {
        $original_user_id = $this->get_original_user_id();
        return $original_user_id ? get_user_by('ID', $original_user_id) : null;
    }
    
    private function get_original_user_id() {
        if (isset($_COOKIE['wu_switched_user'])) {
            $data = json_decode(base64_decode($_COOKIE['wu_switched_user']), true);
            if ($data && isset($data['original_user_id']) && $data['expires'] > time()) {
                return intval($data['original_user_id']);
            }
        }
        return null;
    }
    
    private function set_original_user($user) {
        $data = array(
            'original_user_id' => $user->ID,
            'expires' => time() + $this->settings['session_timeout']
        );
        
        $cookie_value = base64_encode(json_encode($data));
        setcookie('wu_switched_user', $cookie_value, $data['expires'], COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
    
    public function clear_switch_cookie($user_login = null, $user = null) {
        if (isset($_COOKIE['wu_switched_user'])) {
            setcookie('wu_switched_user', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            unset($_COOKIE['wu_switched_user']);
        }
    }
    
    public function handle_woocommerce_session() {
        if ($this->settings['clear_wc_session']) {
            $this->clear_woocommerce_session();
        }
    }
    
    private function clear_woocommerce_session() {
        if (class_exists('WooCommerce')) {
            if (function_exists('WC') && WC()->session) {
                WC()->session->destroy_session();
            }
            
            // 清除購物車
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
        }
    }
    
    public function filter_user_capabilities($allcaps, $cap, $args, $user) {
        // 防止切換到受限制的用戶
        if (isset($args[0]) && $args[0] === 'wu_switch_to_user') {
            if (isset($args[2]) && in_array($args[2], $this->settings['restricted_users'])) {
                return array();
            }
        }
        
        return $allcaps;
    }
    
    public function filter_meta_capabilities($caps, $cap, $user_id, $args) {
        switch ($cap) {
            case 'wu_switch_to_user':
                if (!$this->user_can_switch()) {
                    $caps[] = 'do_not_allow';
                }
                break;
        }
        
        return $caps;
    }
    
    private function log_switch_action($action, $target_user_id) {
        $log_data = get_option('wu_user_switcher_log', array());
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'operator_id' => $this->get_original_user_id() ?: get_current_user_id(),
            'target_id' => $target_user_id,
            'action' => $action,
            'ip_address' => $this->get_client_ip()
        );
        
        array_unshift($log_data, $log_entry);
        
        // 只保留最近100筆記錄
        if (count($log_data) > 100) {
            $log_data = array_slice($log_data, 0, 100);
        }
        
        update_option('wu_user_switcher_log', $log_data);
    }
    
    private function get_recent_switches($limit = 20) {
        $log_data = get_option('wu_user_switcher_log', array());
        return array_slice($log_data, 0, $limit);
    }
    
    private function get_switch_count($period = 'total') {
        $log_data = get_option('wu_user_switcher_log', array());
        
        if ($period === 'total') {
            return count($log_data);
        }
        
        $count = 0;
        $current_time = current_time('timestamp');
        
        foreach ($log_data as $entry) {
            $entry_time = strtotime($entry['timestamp']);
            
            switch ($period) {
                case 'day':
                    if ($entry_time >= strtotime('-1 day', $current_time)) {
                        $count++;
                    }
                    break;
                case 'week':
                    if ($entry_time >= strtotime('-1 week', $current_time)) {
                        $count++;
                    }
                    break;
                case 'month':
                    if ($entry_time >= strtotime('-1 month', $current_time)) {
                        $count++;
                    }
                    break;
            }
        }
        
        return $count;
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
}

// 初始化模組
new WU_User_Switcher();
