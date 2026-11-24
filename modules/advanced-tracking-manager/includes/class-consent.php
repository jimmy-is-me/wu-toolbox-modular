<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Consent {
    const OPTION = 'wutm_atm_privacy';
    const AJAX = 'wutm_atm_privacy';
    private static $read = false;
    private static $choice;
    private static $collecting = false;
    private static $rendered = false;

    public static function settings(): array {
        $saved = (array) get_option(self::OPTION, []);
        return wp_parse_args($saved, [
            'enabled'=>false, 'policy'=>'', 'days'=>180, 'gtm'=>'both', 'url'=>'',
            'layout'=>'inline', 'appearance'=>'light', 'first_visit'=>!empty($saved['enabled']) ? 'opt_out' : 'opt_in',
            'default_analytics'=>true, 'default_marketing'=>true,
            'title'=>'Cookie 與隱私偏好',
            'description'=>'必要 Cookie 用於網站和購物功能。您可以選擇是否允許分析與行銷 Cookie。',
            'accept'=>'同意全部', 'reject'=>'拒絕非必要', 'preferences'=>'偏好設定', 'save'=>'儲存偏好',
        ]);
    }

    public static function active(): bool {
        return (bool) self::settings()['enabled'];
    }

    public static function layouts(): array {
        return ['inline'=>'頁面內橫幅', 'bar'=>'底部滿版橫幅', 'corner'=>'角落浮動卡片',
            'center'=>'中央視窗', 'side'=>'右側面板'];
    }

    private static function browser_preferences(): array {
        $choice = self::choice();
        $settings = self::settings();
        $defaults = $choice === null && $settings['first_visit'] === 'opt_out';
        return [
            'chosen'=>$choice !== null,
            'defaults'=>$defaults,
            'analytics'=>$defaults ? (bool) $settings['default_analytics'] : !empty($choice['analytics']),
            'marketing'=>$defaults ? (bool) $settings['default_marketing'] : !empty($choice['marketing']),
            'expires'=>(int) ($choice['expires'] ?? 0),
        ];
    }

    public static function allows_default_visit(): bool {
        $prefs = self::browser_preferences();
        return self::active() && $prefs['defaults'] && $prefs['marketing'] && WUTM_ATM_Events::browser_active();
    }

    public static function boot(): void {
        if (get_option(WUTM_ATM_Consent_Store::SCHEMA) === '1') {
            add_action(WUTM_ATM_Consent_Store::CRON, [WUTM_ATM_Consent_Store::class, 'cleanup']);
        }
        // Recognize revoked or expired past receipts even when the built-in banner is switched off.
        add_filter('wutm_atm_consent_still_valid', [__CLASS__, 'check_reference'], PHP_INT_MAX, 2);
        if (!self::active()) return;
        add_filter('wutm_atm_marketing_consent', [__CLASS__, 'marketing'], PHP_INT_MAX);
        add_action('wp_ajax_' . self::AJAX, [__CLASS__, 'request']);
        add_action('wp_ajax_nopriv_' . self::AJAX, [__CLASS__, 'request']);
        add_action('template_redirect', [__CLASS__, 'protect_receipt'], -100);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_body_open', [__CLASS__, 'banner'], 20);
        add_action('wp_footer', [__CLASS__, 'banner'], 1);
        add_shortcode('wu_cookie_preferences', [__CLASS__, 'preferences']);
    }

    public static function cookie_name(): string {
        return 'wutm_privacy_' . substr(hash('sha256', home_url('/') . '|' . get_current_blog_id()), 0, 12);
    }

    public static function token(): string {
        $token = $_COOKIE[self::cookie_name()] ?? '';
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
    }

    private static function set_token(string $token, int $expires): bool {
        $name = self::cookie_name();
        if (!setcookie($name, $token, [
            'expires'=>$expires, 'path'=>'/', 'secure'=>is_ssl() || wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https',
            'httponly'=>true, 'samesite'=>'Lax',
        ])) return false;
        $_COOKIE[$name] = $token;
        return true;
    }

    private static function choice(): ?array {
        if (!self::$read) {
            self::$read = true;
            self::$choice = WUTM_ATM_Consent_Store::read(self::token(), self::settings()['policy']);
        }
        return self::$choice;
    }

    public static function marketing(): array {
        $choice = self::active() ? self::choice() : null;
        return !empty($choice['marketing']) && !empty($choice['receipt']) ?
            ['granted'=>true,'reference'=>$choice['receipt']] : [];
    }

    public static function check_reference($valid, $reference): bool {
        if (strpos((string) $reference, WUTM_ATM_Consent_Store::REFERENCE) === 0) {
            return self::active() && WUTM_ATM_Consent_Store::valid((string) $reference, self::settings()['policy']);
        }
        return !self::active() && $valid === true;
    }

    public static function reset_cache(): void {
        self::$read = false;
        self::$choice = null;
    }

    public static function protect_receipt(): void {
        if (!function_exists('is_wc_endpoint_url') || (!is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('order-pay'))) return;
        WUTM_ATM_Events::protect_cache();
        self::private_response();
    }

    private static function visitor_key(string $purpose, string $token): string {
        return hash_hmac('sha256', $purpose . '|' . self::settings()['policy'] . '|' . $token, wp_salt('nonce'));
    }

    private static function origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http','https'], true)) return '';
        $default = strtolower($parts['scheme']) === 'https' ? 443 : 80;
        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) && $parts['port'] !== $default ? ':' . $parts['port'] : '');
    }

    private static function headers_allowed(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        $site_origin = self::origin(home_url('/'));
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_SERVER['HTTP_X_WUTM_CONSENT'] ?? '') === '1'
            && is_string($content_type) && preg_match('~^application/json(?:\s*;.*)?$~iD', $content_type)
            && $site_origin !== '' && is_string($origin) && strtolower($origin) === $site_origin
            && in_array($fetch_site, ['', 'same-origin'], true);
    }

    public static function request(): void {
        self::private_response();
        if (!self::headers_allowed()) wp_send_json_error(null, 403);
        $input = json_decode((string) file_get_contents('php://input', false, null, 0, 4097), true);
        if (!is_array($input)) wp_send_json_error(null, 400);
        $operation = $input['op'] ?? null;
        $token = self::token();
        if ($operation === 'state') {
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                if (!self::set_token($token, time() + 2 * HOUR_IN_SECONDS)) wp_send_json_error(null, 503);
            }
            wp_send_json_success(self::state($token));
        }
        $proof = $input['csrf'] ?? null;
        if ($token === '' || !is_string($proof) || !hash_equals(self::visitor_key('csrf', $token), $proof)) wp_send_json_error(null, 403);
        if ($operation === 'save') {
            if (!is_bool($input['analytics'] ?? null) || !is_bool($input['marketing'] ?? null)) wp_send_json_error(null, 400);
            $settings = self::settings();
            $expires = time() + (int) $settings['days'] * DAY_IN_SECONDS;
            if (!WUTM_ATM_Consent_Store::save($token, $settings['policy'], $input['analytics'], $input['marketing'], $expires)) wp_send_json_error(null, 503);
            self::reset_cache();
            self::set_token($token, $expires);
            wp_send_json_success(self::state($token));
        }
        if ($operation === 'visit' || $operation === 'cart') {
            $browser_only = !self::marketing() && $operation === 'visit' && self::allows_default_visit();
            if ((!$browser_only && !self::marketing()) || WUTM_ATM_Events::excluded()) wp_send_json_error(null, 403);
            $events = $operation === 'visit' ? self::visit($input, $token, $browser_only) : self::cart();
            wp_send_json_success($events);
        }
        wp_send_json_error(null, 400);
    }

    private static function private_response(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0', true);
        header('CDN-Cache-Control: no-store', true);
    }

    private static function state(string $token): array {
        $preferences = self::browser_preferences();
        $analytics = $preferences['analytics'];
        $marketing = $preferences['marketing'];
        $excluded = WUTM_ATM_Events::excluded();
        $managed = WUTM_ATM_Events::settings()['enabled'];
        $gtm_category = self::settings()['gtm'];
        $gtm = $gtm_category === 'analytics' ? $analytics : ($gtm_category === 'marketing' ? $marketing : $analytics && $marketing);
        $tag = static function ($name, $allowed) {
            return $allowed && get_option('atm_' . $name . '_enabled', 0) ? (string) get_option('atm_' . $name . '_id', '') : '';
        };
        return $preferences + [
            'csrf'=>self::visitor_key('csrf', $token),
            'tags'=>[
                'ga4'=>$tag('ga4', !$excluded && $analytics), 'ads'=>$tag('google_ads', !$excluded && $marketing),
                'gtm'=>$tag('gtm', !$excluded && $gtm),
                'pixel'=>!$excluded && $marketing && (!$managed || WUTM_ATM_Events::browser_active()) ? $tag('fb_pixel', true) : '',
                'legacy'=>!$managed,
                'visit'=>!$excluded && $marketing && $managed && (!$preferences['defaults'] || WUTM_ATM_Events::browser_active()),
                'cart'=>!$excluded && $marketing && $preferences['chosen'] && $managed && class_exists('WUTM_ATM_Woo_Events') && WUTM_ATM_Events::is_enabled('AddToCart'),
            ],
        ];
    }

    private static function descriptor(): array {
        $kind = is_search() ? 'search' : 'page';
        $product = 0;
        if (class_exists('WUTM_ATM_Woo_Events')) {
            if (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay')) $kind = 'receipt';
            elseif (is_checkout() && !is_wc_endpoint_url()) $kind = 'checkout';
            elseif (is_product()) { $kind = 'product'; $product = get_queried_object_id(); }
        }
        if (is_404() || is_feed() || is_preview()) $kind = 'ignore';
        $url = WUTM_ATM_Events::source_url();
        return [
            'kind'=>$kind, 'product'=>$product, 'url'=>$url,
            'proof'=>hash_hmac('sha256', "$kind|$product|$url", wp_salt('nonce')),
        ];
    }

    private static function visit(array $input, string $token, bool $browser_only = false): array {
        $page = $input['page'] ?? [];
        $id = $input['visit'] ?? '';
        if (!is_array($page) || !is_string($id) || !preg_match('/^[a-f0-9-]{32,40}$/D', $id)) return [];
        $kind = $page['kind'] ?? null;
        $product = $page['product'] ?? null;
        $url = $page['url'] ?? null;
        $proof = $page['proof'] ?? null;
        if (!in_array($kind, ['page','search','checkout','receipt','product','ignore'], true) || !is_int($product) ||
            !is_string($url) || strlen($url) > 512 || !is_string($proof) ||
            !hash_equals(hash_hmac('sha256', "$kind|$product|$url", wp_salt('nonce')), $proof)) return [];
        $key = self::visitor_key('visit:' . $proof, $token . $id);
        self::$collecting = true;
        try {
            if (!in_array($kind, ['receipt','ignore'], true)) {
                $context = ['source'=>'wordpress','key'=>$key,'url'=>$url,'browser_only'=>$browser_only];
                WUTM_ATM_Events::track('PageView', [], $context);
                if ($kind === 'search') WUTM_ATM_Events::track('Search', [], $context);
            }
            if (class_exists('WUTM_ATM_Woo_Events')) {
                if ($kind === 'product') WUTM_ATM_Woo_Events::view_product($product, $key, $browser_only, $url);
                if (!$browser_only && $kind === 'checkout') WUTM_ATM_Woo_Events::start_checkout($key, $url);
            }
            if ($browser_only) return WUTM_ATM_Events::take_browser();
            return array_merge(WUTM_ATM_Events::take_browser(), self::deferred(), $kind === 'receipt' ? [] : self::cart());
        } finally {
            self::$collecting = false;
        }
    }

    private static function cart(): array {
        return class_exists('WUTM_ATM_Woo_Events') && WUTM_ATM_Events::is_enabled('AddToCart') ?
            WUTM_ATM_Woo_Events::consume_cart() : [];
    }

    public static function collecting(): bool {
        return self::$collecting;
    }

    private static function browser_key(): string {
        $reference = self::marketing()['reference'] ?? '';
        return $reference === '' ? '' : 'wutm_atmb_' . hash('sha256', $reference);
    }

    public static function defer(array $payload): void {
        $key = self::browser_key();
        if ($key === '') return;
        $events = (array) get_transient($key);
        $events[$payload['id']] = $payload;
        set_transient($key, array_slice($events, -15, null, true), 300);
    }

    private static function deferred(): array {
        $key = self::browser_key();
        if ($key === '') return [];
        $items = get_transient($key);
        if (!$items) return [];
        delete_transient($key);
        return array_values((array) $items);
    }

    public static function assets(): void {
        wp_enqueue_style('wutm-atm-privacy', plugin_dir_url(WUTM_ATM_FILE) . 'assets/consent.css', [], WUTM_ATM_VERSION);
        wp_enqueue_script('wutm-atm-events', plugin_dir_url(WUTM_ATM_FILE) . 'assets/events.js', [], WUTM_ATM_VERSION, true);
        $dependencies = ['wutm-atm-events'];
        if (class_exists('WUTM_ATM_Woo_Events') && WUTM_ATM_Events::browser_active() && WUTM_ATM_Events::is_enabled('AddToCart')) {
            $dependencies[] = 'jquery';
        }
        wp_enqueue_script('wutm-atm-privacy', plugin_dir_url(WUTM_ATM_FILE) . 'assets/consent.js', $dependencies, WUTM_ATM_VERSION, true);
        wp_add_inline_script('wutm-atm-privacy', 'window.wutmAtmPrivacy=' . wp_json_encode([
            'url'=>add_query_arg('action', self::AJAX, admin_url('admin-ajax.php', 'relative')),
            'page'=>self::descriptor(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public static function preferences(): string {
        return '<button type="button" class="wutm-atm-privacy-link" data-wutm-privacy-open>Cookie 偏好設定</button>';
    }

    public static function banner(): void {
        if (self::$rendered) return;
        self::$rendered = true;
        $settings = self::settings();
        $layout = isset(self::layouts()[$settings['layout']]) ? $settings['layout'] : 'inline';
        // Keep account and checkout controls accessible on transactional screens.
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()) ||
            (function_exists('is_account_page') && is_account_page())) $layout = 'inline';
        $appearance = $settings['appearance'] === 'dark' ? 'dark' : 'light';
        $compact = $settings['first_visit'] === 'opt_out';
        $url = $settings['url'] ?: get_privacy_policy_url();
        require WUTM_ATM_DIR . 'templates/consent-banner.php';
    }

}
