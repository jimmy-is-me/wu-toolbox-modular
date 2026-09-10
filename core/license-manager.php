<?php
defined('ABSPATH') || exit;

/**
 * WU Toolbox client for the Wumetax licensing service.
 *
 * Normal front-end requests never contact the server. A successful result is
 * cached locally and refreshed by WP-Cron every 72 hours, with a 24-hour
 * network grace period. Existing enabled modules keep running if a license
 * lapses; only new module activation is locked to avoid breaking live sites.
 */
final class WUTM_License_Manager {
    const API_BASE = 'https://wpcd.wumetax.com/wp-json/wumetax-license/v1';
    const KEY_OPTION = 'wutm_license_key';
    const TOKEN_OPTION = 'wutm_license_activation_token';
    const UUID_OPTION = 'wutm_license_site_uuid';
    const IDENTITY_OPTION = 'wutm_license_site_identity';
    const STATE_OPTION = 'wutm_license_state';
    const CRON_HOOK = 'wutm_license_background_check';
    const LEGACY_CRON_HOOK = 'wutm_license_weekly_check';
    const CHECK_INTERVAL = 72 * HOUR_IN_SECONDS;
    const GRACE_PERIOD = 24 * HOUR_IN_SECONDS;
    const PRIVACY_URL = 'https://wumetax.com/privacy-policy/';
    const RETRY_INTERVAL = 8 * HOUR_IN_SECONDS;
    const MAX_RETRIES = 3;

    private static $instance;

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_wutm_license_activate', [$this, 'handle_activate']);
        add_action('admin_post_wutm_license_validate', [$this, 'handle_validate']);
        add_action('admin_post_wutm_license_deactivate', [$this, 'handle_deactivate']);
        add_action(self::CRON_HOOK, [$this, 'refresh']);
        add_action('admin_init', [$this, 'ensure_cron']);
    }

    public function ensure_cron(): void {
        if (wp_next_scheduled(self::LEGACY_CRON_HOOK)) wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
        if (!$this->key() || !$this->token() || wp_next_scheduled(self::CRON_HOOK)) return;
        $state = $this->state();
        $next = !empty($state['next_check_at']) ? max(time() + MINUTE_IN_SECONDS, (int) $state['next_check_at']) : time() + HOUR_IN_SECONDS;
        wp_schedule_single_event($next, self::CRON_HOOK);
    }

    public function key(): string {
        return trim((string) get_option(self::KEY_OPTION, ''));
    }

    public function token(): string {
        return trim((string) get_option(self::TOKEN_OPTION, ''));
    }

    public function site_uuid(): string {
        $identity = $this->current_site_identity();
        $uuid = trim((string) get_option(self::UUID_OPTION, ''));
        $stored_identity = trim((string) get_option(self::IDENTITY_OPTION, ''));
        if (!$uuid || !wp_is_uuid($uuid) || ($stored_identity && $stored_identity !== $identity)) {
            $uuid = wp_generate_uuid4();
            update_option(self::UUID_OPTION, $uuid, false);
        }
        if ($stored_identity !== $identity) update_option(self::IDENTITY_OPTION, $identity, false);
        return $uuid;
    }

    public function state(): array {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private function normalize_site(string $url): array {
        $url = esc_url_raw(trim($url), ['http', 'https']);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./i', '', $host);
        if (!$url || !$host) return ['', ''];
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $port = wp_parse_url($url, PHP_URL_PORT);
        $port_part = $port && !(($scheme === 'http' && (int) $port === 80) || ($scheme === 'https' && (int) $port === 443)) ? ':' . absint($port) : '';
        $path = '/' . trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $path = $path === '/' ? '/' : trailingslashit($path);
        return [$scheme . '://' . $host . $port_part . $path, $host . $port_part . ($path === '/' ? '' : $path)];
    }

    private function current_site_url(): string {
        [$url] = $this->normalize_site(home_url('/'));
        return $url;
    }

    private function current_site_identity(): string {
        [, $identity] = $this->normalize_site(home_url('/'));
        return $identity;
    }

    private function bound_to_current_site(array $state): bool {
        if (empty($state['site_identity']) && empty($state['site_url'])) return true;
        if (!empty($state['site_identity'])) return hash_equals((string) $state['site_identity'], $this->current_site_identity());
        [, $stored_identity] = $this->normalize_site((string) $state['site_url']);
        return $stored_identity === $this->current_site_identity();
    }

    public function is_valid(): bool {
        $state = $this->state();
        if (!$this->token()) return false;
        if (!$this->bound_to_current_site($state)) return false;
        $validated_at = (int) ($state['validated_at'] ?? ($state['checked_at'] ?? 0));
        if (empty($state['valid']) || !$validated_at) return false;
        $now = time();
        $expires = !empty($state['expires_at']) ? strtotime((string) $state['expires_at'] . ' UTC') : 0;
        if ($expires && $expires < $now) return false;
        return ($now - $validated_at) <= self::CHECK_INTERVAL + self::GRACE_PERIOD;
    }

    public function can_update(): bool {
        $state = $this->state();
        return $this->is_valid() && empty($state['transport_error_at']);
    }

    public function status_code(): string {
        $state = $this->state();
        if (!$this->bound_to_current_site($state)) return 'site_changed';
        if ($this->is_valid()) return 'active';
        if (!$this->key()) return 'unlicensed';
        return sanitize_key((string) ($state['status'] ?? 'invalid')) ?: 'invalid';
    }

    public function status_label(): string {
        $labels = [
            'active' => '授權有效',
            'pending' => '等待 Wumetax 核准',
            'suspended' => '網站授權已暫停',
            'revoked' => '授權碼已撤銷',
            'deactivated' => '已解除綁定',
            'expired' => '授權已過期',
            'site_changed' => '偵測到網站網址已變更，請重新綁定',
            'unlicensed' => '尚未輸入授權碼',
            'invalid' => '授權無效或尚未完成驗證',
        ];
        $code = $this->status_code();
        return $labels[$code] ?? '授權目前不可使用';
    }

    private function environment(): array {
        global $wp_version;
        return [
            'site_uuid' => $this->site_uuid(),
            'site_url' => home_url('/'),
            'plugin_slug' => 'wu-toolbox-modular',
            'version' => WUTM_VERSION,
            'wp_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
        ];
    }

    private function request(string $action, array $body): array {
        $url = self::API_BASE . '/' . $action;
        $response = wp_safe_remote_post($url, [
            'timeout' => 6,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
            'user-agent' => 'WU-Toolbox-Modular/' . WUTM_VERSION . '; ' . home_url('/'),
        ]);
        if (is_wp_error($response)) {
            return ['transport_error' => true, 'message' => $response->get_error_message()];
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) $data = [];
        $data['http_code'] = (int) wp_remote_retrieve_response_code($response);
        return $data;
    }

    private function schedule_next(int $delay): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event(time() + max(MINUTE_IN_SECONDS, $delay), self::CRON_HOOK);
    }

    private function store_state(array $data, bool $valid, int $retry_count = 0): void {
        $status = sanitize_key((string) ($data['status'] ?? ($valid ? 'active' : 'invalid')));
        if (!$valid && !empty($data['code']) && in_array($data['code'], ['expired', 'revoked', 'suspended'], true)) {
            $status = sanitize_key($data['code']);
        }
        $now = time();
        $previous = $this->state();
        update_option(self::STATE_OPTION, [
            'valid' => $valid,
            'status' => $status ?: 'invalid',
            'checked_at' => $now,
            'validated_at' => $valid ? $now : (int) ($previous['validated_at'] ?? ($previous['checked_at'] ?? 0)),
            'expires_at' => sanitize_text_field((string) ($data['expires_at'] ?? '')),
            'site_url' => $this->current_site_url(),
            'site_identity' => $this->current_site_identity(),
            'message' => sanitize_text_field((string) ($data['message'] ?? '')),
            'retry_count' => $retry_count,
            'next_check_at' => $now + self::CHECK_INTERVAL,
        ], false);
    }

    public function activate(string $key): array {
        $key = trim(sanitize_text_field($key));
        if (!$key) return ['success' => false, 'message' => '請輸入授權碼。'];
        $data = $this->request('activate', array_merge($this->environment(), ['license_key' => $key]));
        if (!empty($data['transport_error'])) {
            return ['success' => false, 'message' => '無法連線授權伺服器，請稍後再試。'];
        }
        $token = sanitize_text_field((string) ($data['activation_token'] ?? ''));
        if (!$token) {
            $this->store_state($data, false);
            return ['success' => false, 'message' => sanitize_text_field((string) ($data['message'] ?? '授權碼驗證失敗。'))];
        }
        update_option(self::KEY_OPTION, $key, false);
        update_option(self::TOKEN_OPTION, $token, false);
        $valid = !empty($data['valid']) && ($data['status'] ?? '') === 'active';
        $this->store_state($data, $valid);
        $this->schedule_next(self::CHECK_INTERVAL);
        return [
            'success' => $valid,
            'pending' => ($data['status'] ?? '') === 'pending',
            'message' => sanitize_text_field((string) ($data['message'] ?? ($valid ? '授權啟用成功。' : '授權尚未核准。'))),
        ];
    }

    public function refresh(): bool {
        if (!$this->token() || !$this->key()) return false;
        $state = $this->state();
        if (!$this->bound_to_current_site($state)) return false;
        $data = $this->request('validate', array_merge($this->environment(), [
            'activation_token' => $this->token(),
        ]));
        if (!empty($data['transport_error'])) {
            $state = $this->state();
            $retry_count = max(0, (int) ($state['retry_count'] ?? 0)) + 1;
            $has_retry = $retry_count <= self::MAX_RETRIES;
            $delay = $has_retry ? self::RETRY_INTERVAL : self::CHECK_INTERVAL;
            $state['checked_at'] = time();
            $state['transport_error_at'] = time();
            $state['retry_count'] = $has_retry ? $retry_count : 0;
            $state['next_check_at'] = time() + $delay;
            $state['message'] = $has_retry ? sprintf('授權伺服器暫時無法連線，將於 8 小時後重試（%d／%d）。', $retry_count, self::MAX_RETRIES) : '已完成 3 次重試，將於下一個 72 小時週期再次檢查。';
            update_option(self::STATE_OPTION, $state, false);
            $this->schedule_next($delay);
            return false;
        }
        $valid = !empty($data['valid']) && ($data['status'] ?? '') === 'active';
        $this->store_state($data, $valid);
        $this->schedule_next(self::CHECK_INTERVAL);
        return $valid;
    }

    public function deactivate(): bool {
        $success = true;
        $state = $this->state();
        $bound_to_current_site = $this->bound_to_current_site($state);
        if ($this->token() && $bound_to_current_site) {
            $data = $this->request('deactivate', array_merge($this->environment(), [
                'activation_token' => $this->token(),
            ]));
            $success = empty($data['transport_error']) && (($data['status'] ?? '') === 'deactivated');
        }
        delete_option(self::KEY_OPTION);
        delete_option(self::TOKEN_OPTION);
        delete_option(self::STATE_OPTION);
        if (!$bound_to_current_site) delete_option(self::UUID_OPTION);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
        return $success;
    }

    private function redirect(string $notice): void {
        $url = add_query_arg([
            'page' => 'wu-toolbox-modular',
            'wutm_license_notice' => sanitize_key($notice),
        ], admin_url('admin.php'));
        wp_safe_redirect($url . '#wutm-license');
        exit;
    }

    public function handle_activate(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足。');
        check_admin_referer('wutm_license_activate');
        if ($this->token()) $this->redirect('already_bound');
        $result = $this->activate((string) wp_unslash($_POST['wutm_license_key'] ?? ''));
        if (!empty($result['success'])) $this->redirect('activated');
        if (!empty($result['pending'])) $this->redirect('pending');
        set_transient('wutm_license_error_' . get_current_user_id(), $result['message'] ?? '授權失敗。', MINUTE_IN_SECONDS);
        $this->redirect('error');
    }

    public function handle_validate(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足。');
        check_admin_referer('wutm_license_validate');
        $this->redirect($this->refresh() ? 'validated' : 'validate_failed');
    }

    public function handle_deactivate(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足。');
        check_admin_referer('wutm_license_deactivate');
        $this->redirect($this->deactivate() ? 'deactivated' : 'deactivated_local');
    }

    public function render_panel(): void {
        if (!current_user_can('manage_options')) return;
        $state = $this->state();
        $notice = sanitize_key(wp_unslash($_GET['wutm_license_notice'] ?? ''));
        $messages = [
            'activated' => ['授權啟用成功，現在可以啟用模組。', 'success'],
            'validated' => ['已完成授權重新驗證。', 'success'],
            'pending' => ['網站已送出，請等待 Wumetax 授權管理員核准。', 'warning'],
            'deactivated' => ['已解除授權並釋放網站名額。', 'success'],
            'deactivated_local' => ['本機授權已清除，但授權伺服器暫時無法連線；可由 Wumetax 管理端解除網站。', 'warning'],
            'validate_failed' => ['本次即時驗證未完成；若是網路問題，系統每 8 小時重試、最多 3 次。24 小時寬限期間會暫停外掛更新。', 'warning'],
            'already_bound' => ['此網站已有綁定；如需更換授權碼，請先解除目前授權。', 'warning'],
            'error' => [(string) get_transient('wutm_license_error_' . get_current_user_id()), 'error'],
        ];
        delete_transient('wutm_license_error_' . get_current_user_id());
        $offline = !empty($state['transport_error_at']);
        $can_update = $this->can_update();
        $next_check = (int) ($state['next_check_at'] ?? 0);
        $status_hint = $offline
            ? '授權伺服器暫時離線；既有功能在 24 小時寬限內可使用，外掛更新已暫停。'
            : ($this->is_valid() ? '授權與外掛更新皆可正常使用。' : '請完成授權驗證，才能啟用新模組與取得外掛更新。');
        ?>
        <section id="wutm-license" class="wutm-license-panel" aria-labelledby="wutm-license-title">
            <div class="wutm-license-heading"><div><span class="wutm-header-kicker">LICENSE &amp; UPDATES</span><h2 id="wutm-license-title">授權與更新</h2><p><?php echo esc_html($status_hint); ?></p></div><strong class="wutm-license-status <?php echo $offline ? 'is-warning' : ($this->is_valid() ? 'is-active' : 'is-inactive'); ?>"><?php echo esc_html($offline ? '暫時離線' : $this->status_label()); ?></strong></div>
            <?php if (isset($messages[$notice])): ?><div class="notice notice-<?php echo esc_attr($messages[$notice][1]); ?> inline"><p><?php echo esc_html($messages[$notice][0]); ?></p></div><?php endif; ?>
            <div class="wutm-license-grid">
            <div class="wutm-license-block">
                <h3>授權狀態</h3>
                <p>綁定網站：<code><?php echo esc_html(home_url('/')); ?></code></p>
                <?php if (!empty($state['expires_at'])): ?><p>有效期限：<?php echo esc_html($state['expires_at']); ?></p><?php endif; ?>
                <?php $last_validated = (int) ($state['validated_at'] ?? ($state['checked_at'] ?? 0)); if ($last_validated): ?><p>最後成功驗證：<?php echo esc_html(wp_date('Y-m-d H:i:s', $last_validated)); ?></p><?php endif; ?>
                <?php if ($next_check && $this->token()): ?><p>下次檢查：<?php echo esc_html(wp_date('Y-m-d H:i:s', $next_check)); ?></p><?php endif; ?>
                <p>外掛更新：<strong><?php echo esc_html($can_update ? '可使用' : '已暫停'); ?></strong></p>
            </div>
            <?php if (!$this->token()): ?>
                <div class="wutm-license-block">
                    <h3>啟用授權</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wutm_license_activate">
                        <?php wp_nonce_field('wutm_license_activate'); ?>
                        <label for="wutm-license-key"><strong>授權碼</strong></label>
                        <input class="regular-text code" id="wutm-license-key" name="wutm_license_key" required autocomplete="off" placeholder="WUTM-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX">
                        <p class="description">按下「啟用授權」時，會將授權碼、網站網址、隨機網站識別碼，以及外掛、WordPress、PHP、WooCommerce 版本傳送至 Wumetax 授權伺服器，以確認授權與相容性。不會傳送密碼、Cookie、會員、訂單或付款資料。詳見 <a href="<?php echo esc_url(self::PRIVACY_URL); ?>" target="_blank" rel="noopener noreferrer">隱私權政策</a>。</p>
                        <?php submit_button('啟用授權'); ?>
                    </form>
                </div>
            <?php else: ?>
                <div class="wutm-license-block"><h3>授權操作</h3><p class="description">需要立即確認授權時可手動驗證；更換授權碼前請先解除目前綁定。</p><div class="wutm-license-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wutm_license_validate"><?php wp_nonce_field('wutm_license_validate'); ?><button class="button button-primary">立即重新驗證</button></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定解除這個網站的授權？')"><input type="hidden" name="action" value="wutm_license_deactivate"><?php wp_nonce_field('wutm_license_deactivate'); ?><button class="button">解除授權</button></form>
                </div></div>
            <?php endif; ?>
            </div>
            <details class="wutm-license-details"><summary>授權驗證如何運作</summary><ul><li>每 72 小時在背景驗證一次，不影響一般前台請求。</li><li>網路失敗後每 8 小時重試一次，最多 3 次。</li><li>離線寬限為 24 小時；寬限期間既有功能繼續運作，但不提供外掛更新。</li><li>授權失效不會關閉已運作的模組，但不能啟用新模組或更新外掛。</li><li>HTTP／HTTPS、www 與尾斜線差異視為同一網站；搬移或複製網站會建立新的網站識別。</li></ul></details>
        </section>
        <?php
    }
}

WUTM_License_Manager::instance();

function wutm_license_is_valid(): bool {
    return WUTM_License_Manager::instance()->is_valid();
}

function wutm_license_can_update(): bool {
    return WUTM_License_Manager::instance()->can_update();
}
