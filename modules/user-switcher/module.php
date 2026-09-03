<?php
/**
 * Module: user-switcher
 * 安全地在可編輯的使用者帳號間切換，並可一鍵回到原始帳號。
 */
defined('ABSPATH') || exit;

final class WUTM_User_Switcher {
    private const SLUG = 'wu-user-switcher';
    private const OPTION = 'wu_user_switcher_settings';
    private const COOKIE = 'wutm_switch_origin';
    private const COOKIE_TTL = 172800;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 90);
        add_filter('user_row_actions', [__CLASS__, 'user_row_actions'], 10, 2);
        add_action('admin_action_wutm_switch_user', [__CLASS__, 'switch_user']);
        add_action('admin_action_wutm_switch_back', [__CLASS__, 'switch_back']);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 100);
        add_action('admin_post_wutm_user_switcher_save', [__CLASS__, 'save']);
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'show_admin_bar' => true,
            'allowed_roles' => ['administrator'],
            'restricted_users' => [],
            'clear_wc_session' => true,
        ]);
    }

    public static function menu(): void {
        add_submenu_page('wu-toolbox-modular', '使用者切換', '使用者切換', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    private static function allowed_operator(): bool {
        $settings = self::settings();
        $user = wp_get_current_user();
        return (bool) array_intersect((array) $user->roles, (array) $settings['allowed_roles']);
    }

    private static function can_switch_to(int $user_id): bool {
        if (!$user_id || $user_id === get_current_user_id() || !self::allowed_operator()) {
            return false;
        }
        $settings = self::settings();
        if (in_array($user_id, array_map('absint', (array) $settings['restricted_users']), true)) {
            return false;
        }
        return current_user_can('edit_user', $user_id);
    }

    public static function user_row_actions(array $actions, WP_User $user): array {
        if (!self::can_switch_to((int) $user->ID)) {
            return $actions;
        }
        $url = wp_nonce_url(add_query_arg([
            'action' => 'wutm_switch_user',
            'user_id' => (int) $user->ID,
            'redirect_to' => rawurlencode(self::current_url()),
        ], admin_url('users.php')), 'wutm_switch_user_' . (int) $user->ID);
        $actions['wutm_switch_user'] = '<a href="' . esc_url($url) . '">切換至此帳號</a>';
        return $actions;
    }

    private static function current_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return $host ? esc_url_raw($scheme . '://' . $host . $uri) : admin_url();
    }

    private static function cookie_value(int $user_id): string {
        $expires = time() + self::COOKIE_TTL;
        $payload = $user_id . '|' . $expires;
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        return rawurlencode(base64_encode($payload . '|' . $signature));
    }

    private static function origin_user_id(): int {
        if (empty($_COOKIE[self::COOKIE])) {
            return 0;
        }
        $decoded = base64_decode(rawurldecode((string) wp_unslash($_COOKIE[self::COOKIE])), true);
        if (!$decoded) {
            return 0;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return 0;
        }
        [$user_id, $expires, $signature] = $parts;
        $payload = $user_id . '|' . $expires;
        if (!ctype_digit($user_id) || !ctype_digit($expires) || (int) $expires < time() || !hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $signature)) {
            return 0;
        }
        return (int) $user_id;
    }

    private static function set_origin_cookie(int $user_id): void {
        $value = self::cookie_value($user_id);
        $options = [
            'expires' => time() + self::COOKIE_TTL,
            'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie(self::COOKIE, $value, $options);
        $_COOKIE[self::COOKIE] = $value;
    }

    private static function clear_origin_cookie(): void {
        $options = [
            'expires' => time() - YEAR_IN_SECONDS,
            'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie(self::COOKIE, '', $options);
        unset($_COOKIE[self::COOKIE]);
    }

    private static function clear_woocommerce_session(): void {
        $settings = self::settings();
        if (empty($settings['clear_wc_session']) || !function_exists('WC')) {
            return;
        }
        $wc = WC();
        if (isset($wc->session) && method_exists($wc->session, 'forget_session')) {
            $wc->session->forget_session();
        }
    }

    public static function switch_user(): void {
        $target_id = absint($_GET['user_id'] ?? 0);
        if (!self::can_switch_to($target_id)) {
            wp_die('您沒有切換至此帳號的權限。', 403);
        }
        check_admin_referer('wutm_switch_user_' . $target_id);

        if (!self::origin_user_id()) {
            self::set_origin_cookie(get_current_user_id());
        }
        self::clear_woocommerce_session();
        wp_clear_auth_cookie();
        wp_set_auth_cookie($target_id, false);
        wp_set_current_user($target_id);
        do_action('wutm_user_switched', $target_id);

        $redirect = !empty($_GET['redirect_to']) ? esc_url_raw(rawurldecode((string) wp_unslash($_GET['redirect_to']))) : admin_url();
        wp_safe_redirect($redirect ?: admin_url());
        exit;
    }

    public static function switch_back(): void {
        $origin_id = self::origin_user_id();
        if (!$origin_id || !get_userdata($origin_id)) {
            self::clear_origin_cookie();
            wp_die('找不到可返回的原始帳號。', 400);
        }
        check_admin_referer('wutm_switch_back_' . $origin_id);
        self::clear_woocommerce_session();
        wp_clear_auth_cookie();
        wp_set_auth_cookie($origin_id, false);
        wp_set_current_user($origin_id);
        self::clear_origin_cookie();
        do_action('wutm_user_switched_back', $origin_id);
        wp_safe_redirect(admin_url());
        exit;
    }

    public static function admin_bar(WP_Admin_Bar $bar): void {
        $settings = self::settings();
        $origin_id = self::origin_user_id();
        if (!$origin_id || empty($settings['show_admin_bar'])) {
            return;
        }
        $origin = get_userdata($origin_id);
        if (!$origin) {
            return;
        }
        $url = wp_nonce_url(add_query_arg('action', 'wutm_switch_back', admin_url('admin-post.php')), 'wutm_switch_back_' . $origin_id);
        $bar->add_node([
            'id' => 'wutm-switch-back',
            'title' => '返回原帳號：' . esc_html($origin->display_name),
            'href' => $url,
            'meta' => ['class' => 'wutm-switch-back'],
        ]);
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        check_admin_referer('wutm_user_switcher_save');
        $roles = array_keys(wp_roles()->get_names());
        $allowed_roles = array_values(array_intersect($roles, array_map('sanitize_key', (array) wp_unslash($_POST['allowed_roles'] ?? []))));
        $restricted = preg_split('/[\s,]+/', (string) wp_unslash($_POST['restricted_users'] ?? '')) ?: [];
        update_option(self::OPTION, [
            'show_admin_bar' => !empty($_POST['show_admin_bar']),
            'allowed_roles' => $allowed_roles ?: ['administrator'],
            'restricted_users' => array_values(array_filter(array_map('absint', $restricted))),
            'clear_wc_session' => !empty($_POST['clear_wc_session']),
        ], false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        $settings = self::settings();
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>使用者切換</h1>
            <p class="wutm-module-subtitle">在「使用者 → 全部使用者」列表中，對可編輯的帳號使用「切換至此帳號」。切換後可從上方管理列一鍵返回原始帳號。</p>
            <?php if (!empty($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div><?php endif; ?>
            <div class="notice notice-info inline"><p>為了安全，只有您指定的角色可以切換，且只能切換至本身具有「編輯使用者」權限的帳號。原始帳號資訊只保留於此瀏覽器的 48 小時安全 Cookie。</p></div>
            <form class="card" style="max-width:760px;padding:24px;" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_user_switcher_save">
                <?php wp_nonce_field('wutm_user_switcher_save'); ?>
                <h2 style="margin-top:0;">操作設定</h2>
                <p><label><input type="checkbox" name="show_admin_bar" value="1" <?php checked(!empty($settings['show_admin_bar'])); ?>> 在前台與後台管理列顯示「返回原帳號」</label></p>
                <p><label><input type="checkbox" name="clear_wc_session" value="1" <?php checked(!empty($settings['clear_wc_session'])); ?>> 切換時清除 WooCommerce 會話，避免購物車資料混用</label></p>
                <h3>可執行切換的角色</h3>
                <?php foreach (wp_roles()->get_names() as $key => $label) : ?>
                    <label style="display:block;margin:6px 0;"><input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, (array) $settings['allowed_roles'], true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
                <h3>禁止被切換的使用者 ID</h3>
                <p><textarea class="large-text" rows="3" name="restricted_users"><?php echo esc_textarea(implode(PHP_EOL, (array) $settings['restricted_users'])); ?></textarea></p>
                <p class="description">每行一個 ID。留空代表不額外限制。</p>
                <?php submit_button('儲存設定'); ?>
            </form>
        </div>
        <?php
    }
}

WUTM_User_Switcher::boot();
