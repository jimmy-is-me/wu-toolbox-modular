<?php
/**
 * Module: hide-login-page
 *
 * Changes the public WordPress login URL without altering core files.
 */
defined('ABSPATH') || exit;

final class WU_Hide_Login_Page {
    private const SLUG = 'wu-hide-login-page';
    private const OPTION = 'wu_hide_login_page_options';
    private $options;

    public function __construct() {
        $this->options = wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => false,
            'custom_slug' => 'loginwu',
            'redirect_url' => '',
            'use_custom_redirect' => false,
            'custom_redirect_url' => '',
        ]);

        add_action('admin_menu', [$this, 'add_submenu_page'], 50);
        add_action('admin_init', [$this, 'init_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_filter('query_vars', [$this, 'query_vars']);
        add_action('init', [$this, 'register_login_route'], 20);
        add_action('wp_loaded', [$this, 'redirect_direct_access']);
        add_action('template_redirect', [$this, 'render_custom_login'], 0);

        add_filter('site_url', [$this, 'modify_login_url'], 10, 4);
        add_filter('network_site_url', [$this, 'modify_login_url'], 10, 3);
        add_filter('wp_redirect', [$this, 'modify_redirect_url'], 10, 2);
        add_action('update_option_' . self::OPTION, [$this, 'maybe_flush_rewrite_rules'], 10, 3);
    }

    public function add_submenu_page() {
        add_submenu_page(
            'wu-toolbox-modular',
            '隱藏登入頁面設定',
            '隱藏登入頁',
            'manage_options',
            self::SLUG,
            [$this, 'settings_page']
        );
    }

    public function admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::SLUG) return;

        wp_enqueue_style(
            'wutm-hide-login-page',
            WUTM_URL . 'assets/css/hide-login-page.css',
            ['wutm-admin'],
            WUTM_VERSION
        );
    }

    public function init_settings() {
        register_setting(
            'wu_hide_login_page_group',
            self::OPTION,
            ['sanitize_callback' => [$this, 'sanitize_options']]
        );

        add_settings_section(
            'wu_hide_login_page_section',
            '隱藏登入頁面設定',
            [$this, 'section_callback'],
            self::SLUG
        );
        add_settings_field('enabled', '啟用功能', [$this, 'enabled_callback'], self::SLUG, 'wu_hide_login_page_section');
        add_settings_field('custom_slug', '自訂登入網址', [$this, 'custom_slug_callback'], self::SLUG, 'wu_hide_login_page_section');
        add_settings_field('redirect_url', '重新導向網址', [$this, 'redirect_url_callback'], self::SLUG, 'wu_hide_login_page_section');
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);
        $login_url = home_url('/' . $this->slug() . '/');
        ?>
        <div class="wrap wutm-module-wrap wutm-hide-login-page">
            <h1>隱藏登入頁面設定</h1>
            <p class="wutm-module-subtitle">隱藏預設登入入口，並使用容易記住的自訂登入網址。</p>

            <form method="post" action="options.php">
                <?php
                settings_fields('wu_hide_login_page_group');
                do_settings_sections(self::SLUG);
                submit_button('儲存設定');
                ?>
            </form>

            <section class="wutm-hide-login-info">
                <h2>功能說明</h2>
                <ul>
                    <li>啟用後，未登入訪客開啟 <code>wp-login.php</code> 或 <code>wp-admin</code> 會被導向指定頁面。</li>
                    <li>登入、登出、忘記密碼及重新設定密碼連結會自動改用新的登入網址。</li>
                    <li>AJAX、REST API、Cron、XML-RPC 與媒體上傳請求不受影響。</li>
                </ul>
                <?php if (!empty($this->options['enabled'])) : ?>
                    <div class="wutm-hide-login-active">
                        <strong>目前登入網址</strong>
                        <a href="<?php echo esc_url($login_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($login_url); ?></a>
                        <span>請先加入書籤，再登出測試。</span>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function section_callback() {
        echo '<p>網址結尾只需輸入英文字母、數字或連字號。儲存後系統只在設定改變時更新網址規則。</p>';
    }

    public function enabled_callback() {
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked(!empty($this->options['enabled'])); ?>>
            啟用隱藏登入頁面功能
        </label>
        <?php
    }

    public function custom_slug_callback() {
        $slug = $this->slug();
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION); ?>[custom_slug]"
            value="<?php echo esc_attr($slug); ?>" class="regular-text wutm-login-input"
            autocomplete="off" spellcheck="false">
        <p class="description">新的登入網址：<code><?php echo esc_html(home_url('/' . $slug . '/')); ?></code></p>
        <?php
    }

    public function redirect_url_callback() {
        $redirect = $this->redirect_url();
        $pages = get_pages([
            'post_status' => 'publish',
            'sort_column' => 'menu_order,post_title',
            'number' => 200,
        ]);
        $use_custom = !empty($this->options['use_custom_redirect']);
        ?>
        <div class="wutm-login-redirect-options">
            <select name="<?php echo esc_attr(self::OPTION); ?>[redirect_url]" class="regular-text">
                <option value="<?php echo esc_url(home_url('/')); ?>" <?php selected($redirect, home_url('/')); ?>>首頁</option>
                <?php foreach ($pages as $page) :
                    $page_url = get_permalink($page);
                    if (!$page_url) continue;
                    ?>
                    <option value="<?php echo esc_url($page_url); ?>" <?php selected($redirect, $page_url); ?>>
                        <?php echo esc_html($page->post_title ?: ('頁面 #' . $page->ID)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="wutm-login-custom-toggle">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[use_custom_redirect]"
                    value="1" <?php checked($use_custom); ?>>
                使用站內自訂網址
            </label>

            <input type="url" name="<?php echo esc_attr(self::OPTION); ?>[custom_redirect_url]"
                value="<?php echo esc_attr($this->options['custom_redirect_url']); ?>"
                class="regular-text wutm-login-custom-url"
                placeholder="<?php echo esc_attr(home_url('/')); ?>"
                <?php echo $use_custom ? '' : 'hidden'; ?>>
        </div>
        <p class="description">訪客嘗試開啟被隱藏的登入頁面時，會前往這個站內網址。</p>
        <script>
        document.addEventListener('DOMContentLoaded',function(){var toggle=document.querySelector('input[name="<?php echo esc_js(self::OPTION); ?>[use_custom_redirect]"]'),field=document.querySelector('.wutm-login-custom-url'),select=document.querySelector('.wutm-login-redirect-options select');if(!toggle||!field||!select)return;function sync(){field.hidden=!toggle.checked;select.disabled=toggle.checked}toggle.addEventListener('change',sync);sync()});
        </script>
        <?php
    }

    public function sanitize_options($input) {
        $input = is_array($input) ? $input : [];
        $reserved = ['wp-admin', 'wp-login', 'wp-login.php', 'wp-json', 'xmlrpc.php'];
        $slug = isset($input['custom_slug']) ? sanitize_title(wp_unslash($input['custom_slug'])) : '';
        if ($slug === '' || in_array($slug, $reserved, true)) $slug = 'loginwu';

        $use_custom = !empty($input['use_custom_redirect']);
        $selected = isset($input['redirect_url']) ? esc_url_raw(wp_unslash($input['redirect_url'])) : '';
        $custom = isset($input['custom_redirect_url']) ? esc_url_raw(wp_unslash($input['custom_redirect_url'])) : '';
        $candidate = $use_custom ? $custom : $selected;
        $redirect = wp_validate_redirect($candidate, home_url('/'));

        return [
            'enabled' => !empty($input['enabled']),
            'custom_slug' => $slug,
            'redirect_url' => $redirect,
            'use_custom_redirect' => $use_custom,
            'custom_redirect_url' => $custom,
        ];
    }

    public function query_vars($vars) {
        $vars[] = 'wutm_hidden_login';
        return $vars;
    }

    public function register_login_route() {
        if (empty($this->options['enabled'])) return;
        add_rewrite_tag('%wutm_hidden_login%', '1');
        add_rewrite_rule(
            '^' . preg_quote($this->slug(), '/') . '/?$',
            'index.php?wutm_hidden_login=1',
            'top'
        );
    }

    public function maybe_flush_rewrite_rules($old_value, $new_value) {
        $old = wp_parse_args((array) $old_value, ['enabled' => false, 'custom_slug' => 'loginwu']);
        $new = wp_parse_args((array) $new_value, ['enabled' => false, 'custom_slug' => 'loginwu']);
        if ((bool) $old['enabled'] !== (bool) $new['enabled']
            || sanitize_title($old['custom_slug']) !== sanitize_title($new['custom_slug'])) {
            flush_rewrite_rules(false);
        }
    }

    public function redirect_direct_access() {
        if (empty($this->options['enabled']) || is_user_logged_in()) return;
        if ($this->is_system_request()) return;

        $path = $this->request_path();
        if ($path === $this->custom_login_path()) return;

        $core_login = untrailingslashit((string) wp_parse_url(home_url('/wp-login.php'), PHP_URL_PATH));
        $admin_path = untrailingslashit((string) wp_parse_url(admin_url('/'), PHP_URL_PATH));

        if ($path === $core_login || $path === $admin_path || strpos($path, $admin_path . '/') === 0) {
            wp_safe_redirect($this->redirect_url());
            exit;
        }
    }

    public function render_custom_login() {
        if (empty($this->options['enabled'])) return;
        if ($this->request_path() !== $this->custom_login_path() && !get_query_var('wutm_hidden_login')) return;

        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        $_SERVER['PHP_SELF'] = '/wp-login.php';
        $GLOBALS['pagenow'] = 'wp-login.php';
        require ABSPATH . 'wp-login.php';
        exit;
    }

    public function modify_login_url($url, $path = '', $scheme = null, $blog_id = null) {
        if (empty($this->options['enabled']) || strpos($url, 'wp-login.php') === false) return $url;
        return str_replace('wp-login.php', rawurlencode($this->slug()), $url);
    }

    public function modify_redirect_url($location) {
        if (empty($this->options['enabled']) || strpos($location, 'wp-login.php') === false) return $location;
        return str_replace('wp-login.php', rawurlencode($this->slug()), $location);
    }

    private function slug() {
        $slug = sanitize_title((string) $this->options['custom_slug']);
        return $slug !== '' ? $slug : 'loginwu';
    }

    private function redirect_url() {
        $url = !empty($this->options['redirect_url']) ? $this->options['redirect_url'] : home_url('/');
        return wp_validate_redirect($url, home_url('/'));
    }

    private function request_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return untrailingslashit((string) wp_parse_url($uri, PHP_URL_PATH));
    }

    private function custom_login_path() {
        return untrailingslashit((string) wp_parse_url(home_url('/' . $this->slug() . '/'), PHP_URL_PATH));
    }

    private function is_system_request() {
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (function_exists('wp_is_json_request') && wp_is_json_request())) {
            return true;
        }
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') return true;
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strpos($uri, 'admin-ajax.php') !== false
            || strpos($uri, 'wp-cron.php') !== false
            || strpos($uri, 'async-upload.php') !== false;
    }
}

new WU_Hide_Login_Page();
