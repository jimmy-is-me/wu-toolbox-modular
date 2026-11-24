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
            'purchase_mode' => 'standard', 'purchase_manual_gateways' => null, 'purchase_gateways' => [], 'purchase_transitions' => [],
            'purchase_test_dataset' => '', 'purchase_column' => false,
            'browser_evidence' => false, 'version' => 'v25.0', 'dataset' => '', 'test_code' => '', 'retention' => 14,
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
            if ($key === 'advanced-tracking-manager' && !$enabled && get_option('wutm_atm_event_schema')) {
                wp_clear_scheduled_hook('wutm_atm_process_events');
                wp_clear_scheduled_hook('wutm_atm_maintain_events');
                foreach (['wutm_atm_retry_payment_complete','wutm_atm_retry_payment_status','wutm_atm_retry_purchase_check'] as $hook) {
                    wp_unschedule_hook($hook);
                }
            }
        }, 10, 2);
        if (!self::settings()['enabled']) {
            if (get_option('wutm_atm_event_schema')) {
                require_once __DIR__ . '/class-event-queue.php';
                WUTM_ATM_Queue::boot();
            }
            return;
        }
        require_once __DIR__ . '/class-event-queue.php';
        WUTM_ATM_Queue::boot();
        if (!WUTM_ATM_Consent::active()) {
            add_action('template_redirect', [__CLASS__, 'protect_cache'], -100);
            add_action('template_redirect', [__CLASS__, 'page_events'], 5);
            add_action('wp_footer', [__CLASS__, 'print_browser'], 5);
        }
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
        if (WUTM_ATM_Consent::active()) return WUTM_ATM_Consent::marketing();
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
        $reference = $consent['reference'] ?? '';
        if (empty($consent['granted']) || !is_string($reference) || $reference === '') return false;
        if (WUTM_ATM_Consent::active() || strpos($reference, WUTM_ATM_Consent_Store::REFERENCE) === 0) {
            return WUTM_ATM_Consent::check_reference(false, $reference);
        }
        return true === apply_filters('wutm_atm_consent_still_valid', false, $reference, $context);
    }

    public static function page_events(): void {
        if (is_feed() || is_404() || is_preview() || wp_doing_ajax() || wp_is_json_request()) return;
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) return;
        self::$allowed = self::consent();
        // A denied page load has no event to dispatch and does not need a database row.
        if (empty(self::$allowed['granted'])) return;
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

    public static function request_url(): string {
        $home = wp_parse_url(home_url('/'));
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        return self::verified_url($origin . '/' . ltrim($uri, '/'));
    }

    public static function verified_url(string $url): string {
        if ($url === '' || strlen($url) > 8192 || strpos($url, chr(92)) !== false ||
            preg_match('/[[:cntrl:][:space:]]/', $url)) return '';
        $home = wp_parse_url(home_url('/'));
        $parts = wp_parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) ||
            ($parts['host'] ?? '') !== $home['host'] || ($parts['scheme'] ?? '') !== $home['scheme'] ||
            ($parts['port'] ?? null) !== ($home['port'] ?? null)) return '';
        $path = $parts['path'] ?? '/';
        $decoded = rawurldecode($path);
        if (function_exists('WC') && isset(WC()->query)) {
            $endpoints = WC()->query->get_query_vars();
            foreach (['order-pay','order-received'] as $endpoint) {
                $part = $endpoints[$endpoint] ?? '';
                if ($part !== '' && in_array($part, explode('/', $decoded), true)) return '';
            }
        }
        if (strlen($path) > 460 || strpos($decoded, chr(92)) !== false ||
            preg_match('~(?:^|/)(?:wp-admin|wp-json|wp-login\.php|order-pay|order-received)(?:/|$)~i', $decoded) ||
            preg_match('~(?:^|/)wc_order_[^/]+|@|[[:cntrl:]]~i', $decoded)) return '';
        parse_str($parts['query'] ?? '', $query);
        foreach (['rest_route','wc-ajax','order-pay','order-received'] as $private) {
            if (isset($query[$private])) return '';
        }
        $route = [];
        foreach (['p','page_id','product_id','paged','cat'] as $field) {
            if (is_string($query[$field] ?? null) && preg_match('/^[0-9]{1,12}$/D', $query[$field])) $route[$field] = $query[$field];
        }
        $result = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $path;
        if ($route) $result = add_query_arg($route, $result);
        return strlen($result) <= 512 ? esc_url_raw($result, ['http','https']) : '';
    }

    public static function test_configuration(): array {
        $settings = self::settings();
        $code = $settings['test_code'];
        $dataset = $settings['purchase_test_dataset'] ?: self::dataset();
        if (!self::server_active() || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $code) ||
            !preg_match('/^[1-9][0-9]{0,39}$/D', $dataset)) return [];
        return ['code'=>$code,'dataset'=>$dataset,'shared'=>$dataset === self::dataset()];
    }

    public static function source_url(string $url = ''): string {
        return ($url === '' ? self::request_url() : self::verified_url($url)) ?: home_url('/');
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
        $purchase = $name === 'Purchase' && $source === 'woocommerce' && isset($context['purchase_id']);
        if ($purchase) $id = $context['purchase_id'];
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
        if ($purchase) {
            $event['purchase_test'] = $context['purchase_test'];
            $event['test_configuration'] = $context['test_configuration'];
            $event['retry_purchase'] = $context['retry_purchase'];
        }
        $live = self::$allowed ?? self::consent();
        $consent = $context['recorded_consent'] ?? $live;
        $default_visit = empty($live['granted']) && !empty($context['browser_only']) &&
            in_array($name, ['PageView','Search','ViewContent'], true) &&
            WUTM_ATM_Consent::collecting() && WUTM_ATM_Consent::allows_default_visit();
        $browser_enabled = empty($context['server_only']) && self::browser_active() && !self::excluded();
        $browser = $browser_enabled && (!empty($live['granted']) || $default_visit);
        $server = empty($context['browser_only']) && self::server_active();
        $retry_matching = !empty($context['retry_matching']) && !empty($context['server_only']) && WUTM_ATM_Consent::collecting() &&
            in_array($name, ['PageView','ViewContent','Search','InitiateCheckout'], true);
        $event['retry_matching'] = $retry_matching;
        $event['browser_blocked'] = $browser_enabled && !$browser;
        if (!$browser && !$server && $browser_enabled) {
            WUTM_ATM_Queue::record($event, 'consent_blocked');
            return null;
        }
        if ($browser && $purchase && !WUTM_ATM_Queue::record($event, 'browser_prepared', true)) return null;
        if ($browser) {
            $payload = ['name'=>$name, 'id'=>$id, 'data'=>$data];
            if (!empty(self::settings()['browser_evidence'])) {
                $payload['evidence'] = WUTM_ATM_Browser_Evidence::ticket($id);
            }
            if (!empty($context['cart_session'])) {
                do_action('wutm_atm_defer_cart_event', $payload);
            } elseif (WUTM_ATM_Consent::active() && !WUTM_ATM_Consent::collecting()) {
                WUTM_ATM_Consent::defer($payload);
            } else {
                self::$browser[$id] = $payload;
            }
            if (!$purchase) WUTM_ATM_Queue::record($event, 'browser_prepared', true);
        }
        if ($server) {
            $event['server_channel'] = true;
            if (!self::consent_valid((array) $consent, $event)) {
                if (!$retry_matching) WUTM_ATM_Queue::record($event, 'consent_blocked');
                $server = false;
            } else {
                $user = self::user_data($context);
                $event['user_data'] = $user;
                if (empty($user['client_user_agent']) || count($user) < 2) {
                    if (!$retry_matching) WUTM_ATM_Queue::record($event, 'failed', false, 'matching_data_missing');
                    $server = false;
                } else {
                    $event['consent'] = $consent['reference'];
                    $recorded = WUTM_ATM_Queue::record($event, 'pending');
                    if ($purchase || $retry_matching) $server = $recorded;
                }
            }
        }
        return $browser || $server ? $id : null;
    }

    public static function valid_browser_id($value): bool {
        return is_string($value) && (bool) preg_match('/^fb\.\d+\.\d{10,16}\.\d{1,30}$/D', $value);
    }

    private static function user_data(array $context): array {
        $provided = (array) ($context['user'] ?? []);
        if (!array_key_exists('email', $provided) && empty($context['recorded_consent']) && is_user_logged_in()) {
            $provided['email'] = wp_get_current_user()->user_email;
        }
        $email = is_string($provided['email'] ?? null) ? strtolower(trim($provided['email'])) : '';
        $ua = sanitize_text_field($provided['user_agent'] ?? wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $fbp = $provided['fbp'] ?? wp_unslash($_COOKIE['_fbp'] ?? '');
        $user = [];
        if ($ua !== '') $user['client_user_agent'] = substr($ua, 0, 512);
        if (is_email($email)) $user['em'] = [hash('sha256', $email)];
        if (($context['purchase_test'] ?? '') === 'simulation' &&
            is_string($provided['external_id'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $provided['external_id'])) {
            $user['external_id'] = [$provided['external_id']];
        }
        if (self::valid_browser_id($fbp)) $user['fbp'] = $fbp;
        $fbc = $provided['fbc'] ?? (empty($context['recorded_consent']) ? wp_unslash($_COOKIE['_fbc'] ?? '') : '');
        if (is_string($fbc) && preg_match('/^fb\.\d+\.\d{10,16}\.[A-Za-z0-9_-]{1,250}$/D', $fbc)) $user['fbc'] = $fbc;
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

    public static function take_browser(): array {
        $events = array_values(self::$browser);
        self::$browser = [];
        return $events;
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
