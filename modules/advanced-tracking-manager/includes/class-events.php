<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Events {
    const OPTION = 'wutm_atm_events';
    const STANDARD = ['PageView','ViewContent','Search','AddToCart','InitiateCheckout','Purchase','Lead','CompleteRegistration','Contact'];
    private static $browser = [];
    private static $allowed = null;

    public static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => false, 'mode' => 'browser', 'woo' => false, 'events' => [],
            'version' => 'v25.0', 'dataset' => '', 'test_code' => '', 'retention' => 14,
        ]);
    }

    public static function names(): array {
        return array_values(array_unique(array_filter(
            array_merge(self::STANDARD, (array) apply_filters('wutm_atm_custom_events', [])),
            static function ($name) { return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $name); }
        )));
    }

    public static function boot(): void {
        add_action('wutm_module_toggled', static function ($key, $enabled) {
            if ($key === 'advanced-tracking-manager' && !$enabled && get_option('wutm_atm_event_schema') === '1') {
                wp_clear_scheduled_hook('wutm_atm_process_events');
                wp_clear_scheduled_hook('wutm_atm_maintain_events');
            }
        }, 10, 2);
        if (!self::settings()['enabled']) {
            if (get_option('wutm_atm_event_schema') === '1') {
                require_once __DIR__ . '/class-event-queue.php';
                WUTM_ATM_Queue::boot();
            }
            return;
        }
        require_once __DIR__ . '/class-event-queue.php';
        WUTM_ATM_Queue::boot();
        add_action('template_redirect', [__CLASS__, 'protect_cache'], -100);
        add_action('template_redirect', [__CLASS__, 'page_events'], 5);
        add_action('wp_footer', [__CLASS__, 'print_browser'], 5);
        add_action('wutm_atm_track', [__CLASS__, 'track'], 10, 3);
        add_action('user_register', static function () {
            self::track('CompleteRegistration', [], ['source' => 'wordpress']);
        });
        if (class_exists('WooCommerce') && self::settings()['woo']) {
            require_once __DIR__ . '/class-woocommerce-events.php';
            WUTM_ATM_Woo_Events::boot();
        }
    }

    public static function protect_cache(): void {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
    }

    public static function excluded(): bool {
        return (bool) get_option('atm_exclude_admin', 1) && current_user_can('manage_options');
    }

    public static function consent(): array {
        try {
            $value = apply_filters('wutm_atm_marketing_consent', false);
        } catch (Throwable $error) {
            return [];
        }
        if ($value === true) return ['granted' => true, 'reference' => ''];
        if (!is_array($value) || ($value['granted'] ?? false) !== true) return [];
        $reference = $value['reference'] ?? '';
        return [
            'granted' => true,
            'reference' => is_string($reference) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $reference) ? $reference : '',
        ];
    }

    public static function consent_valid(array $consent, array $context = []): bool {
        return !empty($consent['granted']) && !empty($consent['reference']) &&
            true === apply_filters('wutm_atm_consent_still_valid', false, $consent['reference'], $context);
    }

    public static function page_events(): void {
        if (is_feed() || is_404() || is_preview() || wp_doing_ajax() || wp_is_json_request()) return;
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) return;
        self::$allowed = self::consent();
        self::track('PageView', [], ['source' => 'wordpress']);
        if (is_search()) self::track('Search', [], ['source' => 'wordpress']);
    }

    public static function is_enabled(string $name): bool {
        $settings = self::settings();
        return $settings['enabled'] && in_array($name, (array) $settings['events'], true) && in_array($name, self::names(), true);
    }

    public static function pixel(): string {
        return preg_replace('/\D/', '', (string) get_option('atm_fb_pixel_id', ''));
    }

    public static function dataset(): string {
        return preg_replace('/\D/', '', (string) (self::settings()['dataset'] ?: self::pixel()));
    }

    public static function token(): string {
        return defined('WUTM_ATM_CAPI_TOKEN') ? (string) WUTM_ATM_CAPI_TOKEN : (string) get_option('atm_fb_capi_token', '');
    }

    public static function browser_active(): bool {
        return self::settings()['enabled'] && self::settings()['mode'] !== 'server' && get_option('atm_fb_pixel_enabled', 0) && self::pixel() !== '';
    }

    public static function server_active(): bool {
        $settings = self::settings();
        return $settings['enabled'] && $settings['mode'] !== 'browser' && get_option('atm_fb_capi_enabled', 0) && self::dataset() !== '' && self::token() !== '' &&
            ($settings['mode'] !== 'both' || self::dataset() === self::pixel());
    }

    public static function source_url(string $url = ''): string {
        $home = wp_parse_url(home_url('/'));
        if ($url === '') {
            $candidate = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $url = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . '/' . ltrim(is_string($candidate) ? $candidate : '/', '/');
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') !== $home['host'] || ($parts['scheme'] ?? '') !== $home['scheme'] || ($parts['port'] ?? null) !== ($home['port'] ?? null)) return home_url('/');
        return substr(esc_url_raw(preg_split('/[?#]/', $url, 2)[0], ['http','https']), 0, 512);
    }

    public static function event_id(string $name, string $key): string {
        return 'atm-' . hash_hmac('sha256', get_current_blog_id() . '|' . $name . '|' . $key, wp_salt('auth'));
    }

    public static function track($name, $params = [], $context = []): ?string {
        try {
            return self::build_and_dispatch($name, $params, $context);
        } catch (Throwable $error) {
            // A tracking failure must never interrupt a payment, form or cart mutation.
            return null;
        }
    }

    private static function build_and_dispatch($name, $params, $context): ?string {
        if (!is_string($name) || !is_array($params) || !is_array($context) || !self::is_enabled($name)) return null;
        if (self::excluded() && !array_key_exists('recorded_consent', $context)) return null;
        $source = sanitize_key(is_string($context['source'] ?? null) ? $context['source'] : 'custom');
        $key = is_scalar($context['key'] ?? null) ? (string) $context['key'] : wp_generate_uuid4();
        $id = self::event_id($name, $source === 'woocommerce' ? $key : $source . ':' . $key);
        // Only the order adapter can supply order-derived monetary fields for its Purchase.
        $data = self::parameters((array) apply_filters('wutm_atm_event_parameters', $params, $name, $context));
        if ($name === 'Purchase' && $source === 'woocommerce') {
            $data = array_replace($data, self::parameters($params));
        }
        $event = [
            'event_id' => $id, 'event_name' => $name, 'source' => substr($source, 0, 40),
            'event_time' => min(time(), absint($context['time'] ?? time())),
            'event_source_url' => self::source_url((string) ($context['url'] ?? '')),
            'custom_data' => $data, 'order_id' => absint($context['order_id'] ?? 0),
        ];
        $live = self::$allowed ?? self::consent();
        $consent = $context['recorded_consent'] ?? $live;
        $browser = empty($context['server_only']) && self::browser_active() && !empty($live['granted']) && !self::excluded();
        $server = empty($context['browser_only']) && self::server_active();
        if (!$browser && !$server && (empty($consent['granted']) || (self::browser_active() && empty($live['granted'])))) {
            WUTM_ATM_Queue::record($event, 'consent_blocked');
            return null;
        }
        if ($browser) {
            $payload = ['name'=>$name, 'id'=>$id, 'data'=>$data];
            if (!empty($context['cart_session'])) {
                do_action('wutm_atm_defer_cart_event', $payload);
            } else {
                self::$browser[$id] = $payload;
            }
            WUTM_ATM_Queue::record($event, 'browser_prepared', true);
        }
        if ($server) {
            if (!self::consent_valid((array) $consent, $event)) {
                WUTM_ATM_Queue::record($event, 'consent_blocked');
                $server = false;
            } else {
                $user = self::user_data($context);
                if (empty($user['client_user_agent']) || count($user) < 2) {
                    WUTM_ATM_Queue::record($event, 'failed', false, 'matching_data_missing');
                    $server = false;
                } else {
                    $event['user_data'] = $user;
                    $event['consent'] = $consent['reference'];
                    WUTM_ATM_Queue::record($event, 'pending');
                }
            }
        }
        return $browser || $server ? $id : null;
    }

    private static function user_data(array $context): array {
        $provided = (array) ($context['user'] ?? []);
        $email = is_string($provided['email'] ?? null) ? strtolower(trim($provided['email'])) : '';
        $ua = sanitize_text_field($provided['user_agent'] ?? wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $fbp = $provided['fbp'] ?? wp_unslash($_COOKIE['_fbp'] ?? '');
        $user = [];
        if ($ua !== '') $user['client_user_agent'] = substr($ua, 0, 512);
        if (is_email($email)) $user['em'] = [hash('sha256', $email)];
        if (is_string($fbp) && preg_match('/^fb\.\d+\.\d{10,16}\.\d{1,30}$/D', $fbp)) $user['fbp'] = $fbp;
        $ip = $provided['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        if (true === apply_filters('wutm_atm_allow_ip_matching', false) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $user['client_ip_address'] = $ip;
        }
        return $user;
    }

    private static function parameters(array $params): array {
        $data = [];
        foreach (['content_name','content_type','search_string','order_id'] as $name) {
            if (isset($params[$name]) && is_scalar($params[$name])) $data[$name] = substr(sanitize_text_field((string) $params[$name]), 0, 160);
        }
        if (isset($params['content_ids']) && is_array($params['content_ids'])) {
            $ids = array_slice(array_filter($params['content_ids'], 'is_scalar'), 0, 50);
            $data['content_ids'] = array_values(array_map(static function ($id) { return substr(sanitize_text_field((string) $id), 0, 100); }, $ids));
        }
        if (isset($params['num_items'])) $data['num_items'] = absint($params['num_items']);
        if (is_string($params['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $params['currency'])) {
            $data['currency'] = $params['currency'];
            if (is_numeric($params['value'] ?? null) && is_finite((float) $params['value']) && $params['value'] >= 0) $data['value'] = (float) $params['value'];
        }
        foreach (array_slice((array) ($params['custom'] ?? []), 0, 12, true) as $key => $value) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/D', (string) $key)) continue;
            // Custom codes and numerical metrics only. Free-form copy may contain personal data.
            if (is_bool($value) || (is_numeric($value) && is_finite((float) $value))) $data['custom_' . $key] = $value;
        }
        return $data;
    }

    public static function add_browser_payload(array $payload): void {
        self::$browser[$payload['id']] = $payload;
    }

    public static function print_browser(): void {
        if (self::excluded() || empty(self::consent()['granted'])) return;
        $pixel = self::browser_active() ? self::pixel() : '';
        $ga4 = get_option('atm_ga4_enabled', 0) ? (string) get_option('atm_ga4_id', '') : '';
        $google = $ga4 || get_option('atm_google_ads_enabled', 0) || get_option('atm_gtm_enabled', 0);
        $cart = $pixel !== '' && (bool) apply_filters('wutm_atm_browser_cart', false);
        if (!$google && !self::$browser && !$cart) return;
        wp_enqueue_script('wutm-atm-events', plugin_dir_url(WUTM_ATM_FILE) . 'assets/events.js', $cart ? ['jquery'] : [], WUTM_ATM_VERSION, true);
        wp_add_inline_script('wutm-atm-events', 'window.wutmAtmConfig=' . wp_json_encode([
            'pixel'=>$pixel, 'events'=>array_values(self::$browser), 'google'=>$google, 'ga4'=>$ga4,
            'cart'=>$cart ? admin_url('admin-ajax.php') : '', 'nonce'=>$cart ? wp_create_nonce('wutm_atm_cart') : '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';', 'before');
    }
}
