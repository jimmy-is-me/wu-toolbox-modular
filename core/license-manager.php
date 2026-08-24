<?php
defined('ABSPATH') || exit;

/**
 * Central license gate for WU Toolbox Modular.
 *
 * The license is checked once when activated and then refreshed every 30 days.
 * Results are cached locally so normal WordPress requests never call the
 * licensing site. Network failures keep the last valid result for 14 days.
 */
final class WUTM_License_Manager {
    const KEY_OPTION = 'wutm_license_key';
    const STATE_OPTION = 'wutm_license_state';
    const ENDPOINT_OPTION = 'wutm_license_endpoint';
    const CHECK_INTERVAL = 30 * DAY_IN_SECONDS;
    const GRACE_PERIOD = 14 * DAY_IN_SECONDS;

    private static $instance;

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_page'], 20);
        add_action('admin_init', [$this, 'maybe_refresh']);
        add_action('wutm_license_daily_check', [$this, 'refresh']);
        add_filter('cron_schedules', [$this, 'cron_schedule']);
    }

    public function cron_schedule(array $schedules): array {
        if (!isset($schedules['wutm_daily'])) {
            $schedules['wutm_daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'WU Toolbox daily license check'];
        }
        return $schedules;
    }

    public function register_page(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            __('授權設定', 'wu-toolbox-modular'),
            __('授權設定', 'wu-toolbox-modular'),
            'manage_options',
            'wu-license',
            [$this, 'render_page']
        );
    }

    public function endpoint(): string {
        $saved = trim((string) get_option(self::ENDPOINT_OPTION, ''));
        return (string) apply_filters('wutm_license_endpoint', $saved);
    }

    public function key(): string {
        return trim((string) get_option(self::KEY_OPTION, ''));
    }

    public function state(): array {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    public function is_valid(): bool {
        $state = $this->state();
        if (empty($state['valid']) || empty($state['checked_at'])) return false;
        $now = time();
        $expires = !empty($state['expires_at']) ? strtotime((string) $state['expires_at']) : 0;
        if ($expires && $expires < $now) return false;
        // A valid cached result remains usable during the short offline grace period.
        return ($now - (int) $state['checked_at']) <= self::CHECK_INTERVAL + self::GRACE_PERIOD;
    }

    public function status_label(): string {
        if ($this->is_valid()) return __('已驗證', 'wu-toolbox-modular');
        if ($this->key() === '') return __('尚未輸入授權碼', 'wu-toolbox-modular');
        return __('尚未驗證或已過期', 'wu-toolbox-modular');
    }

    public function maybe_refresh(): void {
        if (!is_admin() || !$this->key() || !$this->endpoint()) return;
        $state = $this->state();
        if (empty($state['checked_at']) || time() - (int) $state['checked_at'] > self::CHECK_INTERVAL) {
            $this->refresh();
        }
    }

    public function refresh(): bool {
        $key = $this->key();
        $endpoint = $this->endpoint();
        if (!$key || !$endpoint || !wp_http_validate_url($endpoint)) return false;

        $response = wp_safe_remote_post($endpoint, [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'license_key' => $key,
                'site_url' => home_url('/'),
                'plugin_slug' => 'wu-toolbox-modular',
                'version' => WUTM_VERSION,
            ],
        ]);
        if (is_wp_error($response)) return false;

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) return false;

        $valid = !empty($data['valid']);
        $state = [
            'valid' => $valid,
            'checked_at' => time(),
            'expires_at' => sanitize_text_field((string) ($data['expires_at'] ?? '')),
            'site_url' => esc_url_raw((string) ($data['site_url'] ?? home_url('/'))),
            'message' => sanitize_text_field((string) ($data['message'] ?? '')),
        ];
        update_option(self::STATE_OPTION, $state, false);
        return $valid;
    }

    public function save(): array {
        if (!current_user_can('manage_options')) return ['success' => false, 'message' => __('權限不足。', 'wu-toolbox-modular')];
        check_admin_referer('wutm_license_save');

        $key = sanitize_text_field(wp_unslash($_POST['wutm_license_key'] ?? ''));
        $endpoint = esc_url_raw(trim((string) wp_unslash($_POST['wutm_license_endpoint'] ?? '')));
        update_option(self::KEY_OPTION, $key, false);
        update_option(self::ENDPOINT_OPTION, $endpoint, false);
        delete_option(self::STATE_OPTION);

        $valid = $this->refresh();
        return [
            'success' => $valid,
            'message' => $valid ? __('授權驗證成功，所有模組已解鎖。', 'wu-toolbox-modular') : __('驗證失敗，請確認授權碼與授權伺服器網址。', 'wu-toolbox-modular'),
        ];
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $notice = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wutm_license_save'])) {
            $notice = $this->save();
        }
        $state = $this->state();
        ?>
        <div class="wrap wutm-wrap wutm-license-page">
            <header class="wutm-header"><div><h1><?php echo esc_html__('授權設定', 'wu-toolbox-modular'); ?></h1><p><?php echo esc_html__('輸入授權碼後即可啟用 WU Toolbox Modular 的所有功能。', 'wu-toolbox-modular'); ?></p></div><span>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
            <?php if ($notice): ?><div class="notice <?php echo $notice['success'] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
            <section class="wutm-panel">
                <h2><?php echo esc_html__('授權狀態', 'wu-toolbox-modular'); ?></h2>
                <p class="wutm-license-status"><?php echo esc_html($this->status_label()); ?></p>
                <?php if (!empty($state['expires_at'])): ?><p><?php echo esc_html(sprintf(__('有效期限：%s', 'wu-toolbox-modular'), $state['expires_at'])); ?></p><?php endif; ?>
            </section>
            <section class="wutm-panel">
                <h2><?php echo esc_html__('連線設定', 'wu-toolbox-modular'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('wutm_license_save'); ?>
                    <input type="hidden" name="wutm_license_save" value="1">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="wutm_license_key"><?php echo esc_html__('授權碼', 'wu-toolbox-modular'); ?></label></th><td><input class="regular-text" type="text" id="wutm_license_key" name="wutm_license_key" value="<?php echo esc_attr($this->key()); ?>" autocomplete="off"><p class="description"><?php echo esc_html__('由授權管理站產生的授權碼。', 'wu-toolbox-modular'); ?></p></td></tr>
                        <tr><th><label for="wutm_license_endpoint"><?php echo esc_html__('授權伺服器驗證網址', 'wu-toolbox-modular'); ?></label></th><td><input class="regular-text code" type="url" id="wutm_license_endpoint" name="wutm_license_endpoint" value="<?php echo esc_attr($this->endpoint()); ?>" placeholder="https://license.example.com/wp-json/wutm-license/v1/validate"><p class="description"><?php echo esc_html__('WordPress A 提供的 REST API 驗證端點。驗證結果會快取，不會每次請求連線。', 'wu-toolbox-modular'); ?></p></td></tr>
                    </table>
                    <?php submit_button(__('儲存並驗證', 'wu-toolbox-modular')); ?>
                </form>
            </section>
        </div>
        <?php
    }
}

WUTM_License_Manager::instance();
function wutm_license_is_valid(): bool {
    return WUTM_License_Manager::instance()->is_valid();
}
