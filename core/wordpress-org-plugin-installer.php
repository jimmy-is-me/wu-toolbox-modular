<?php
/**
 * Shared WordPress.org installer used by third-party plugin shortcut modules.
 *
 * These modules intentionally do not embed or reimplement any third-party
 * plugin. They only invoke WordPress core's normal install and activation APIs.
 */
defined('ABSPATH') || exit;

final class WUTM_WordPress_Org_Plugin_Installer {
    private const NOTICE_PREFIX = 'wutm_third_party_plugin_notice_';

    public static function register(array $config): void {
        $config = self::normalize_config($config);
        if (!$config) {
            return;
        }

        add_action('admin_menu', static function () use ($config): void {
            add_submenu_page(
                'wu-toolbox-modular',
                $config['name'],
                $config['name'],
                'install_plugins',
                $config['page_slug'],
                static function () use ($config): void {
                    self::page($config);
                }
            );
        }, 20);

        add_action('admin_post_' . $config['install_action'], static function () use ($config): void {
            self::install($config);
        });
        add_action('admin_post_' . $config['activate_action'], static function () use ($config): void {
            self::activate($config);
        });
    }

    private static function normalize_config(array $config): ?array {
        foreach (['key', 'name', 'plugin_slug', 'plugin_folder', 'plugin_file', 'plugin_url'] as $required) {
            if (empty($config[$required]) || !is_string($config[$required])) {
                return null;
            }
        }

        $key = sanitize_key($config['key']);
        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'name' => sanitize_text_field($config['name']),
            'plugin_slug' => sanitize_key($config['plugin_slug']),
            'plugin_folder' => trim(sanitize_text_field($config['plugin_folder']), '/'),
            'plugin_file' => ltrim(sanitize_text_field($config['plugin_file']), '/'),
            'plugin_url' => esc_url_raw($config['plugin_url']),
            'page_slug' => 'wu-' . $key,
            'install_action' => 'wutm_third_party_install_' . $key,
            'activate_action' => 'wutm_third_party_activate_' . $key,
        ];
    }

    private static function plugin_file(array $config): string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (file_exists(WP_PLUGIN_DIR . '/' . $config['plugin_file'])) {
            return $config['plugin_file'];
        }

        $plugins = get_plugins($config['plugin_folder']);
        foreach ($plugins as $file => $data) {
            return $config['plugin_folder'] . '/' . $file;
        }

        return $config['plugin_file'];
    }

    private static function notice_key(array $config): string {
        return self::NOTICE_PREFIX . $config['key'];
    }

    private static function set_notice(array $config, string $type, string $message): void {
        set_transient(self::notice_key($config), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS);
    }

    private static function redirect(array $config): void {
        wp_safe_redirect(add_query_arg(['page' => $config['page_slug']], admin_url('admin.php')));
        exit;
    }

    private static function ensure_capability(string $capability): void {
        if (!current_user_can($capability)) {
            wp_die('您沒有執行此操作的權限。', 403);
        }
    }

    private static function install(array $config): void {
        self::ensure_capability('install_plugins');
        check_admin_referer($config['install_action']);

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', [
            'slug' => $config['plugin_slug'],
            'fields' => ['sections' => false, 'banners' => false],
        ]);
        if (is_wp_error($api) || empty($api->download_link)) {
            self::set_notice($config, 'error', '無法取得 ' . $config['name'] . ' 的 WordPress.org 官方安裝檔，請稍後再試。');
            self::redirect($config);
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);
        if (is_wp_error($installed) || !$installed) {
            self::set_notice($config, 'error', '安裝未完成。請確認伺服器可寫入 wp-content/plugins，或改由「外掛 → 安裝外掛」手動安裝。');
            self::redirect($config);
        }

        $activated = activate_plugin(self::plugin_file($config));
        if (is_wp_error($activated)) {
            self::set_notice($config, 'warning', $config['name'] . ' 已安裝，但尚未啟用。請在本頁點選「啟用外掛」。');
            self::redirect($config);
        }

        self::set_notice($config, 'success', $config['name'] . ' 已完成安裝並啟用。');
        self::redirect($config);
    }

    private static function activate(array $config): void {
        self::ensure_capability('activate_plugins');
        check_admin_referer($config['activate_action']);

        $result = activate_plugin(self::plugin_file($config));
        self::set_notice(
            $config,
            is_wp_error($result) ? 'error' : 'success',
            is_wp_error($result) ? '無法啟用 ' . $config['name'] . '，請確認外掛檔案完整。' : $config['name'] . ' 已啟用。'
        );
        self::redirect($config);
    }

    private static function page(array $config): void {
        self::ensure_capability('install_plugins');
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin = self::plugin_file($config);
        $installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin);
        $active = $installed && is_plugin_active($plugin);
        $notice = get_transient(self::notice_key($config));
        delete_transient(self::notice_key($config));
        ?>
        <div class="wrap wutm-module-wrap">
            <h1><?php echo esc_html($config['name']); ?></h1>
            <p class="wutm-module-subtitle">這是官方外掛的快速安裝入口。WU Toolbox Modular 不會內建、重製、修改或載入 <?php echo esc_html($config['name']); ?> 的功能。</p>

            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr(in_array($notice['type'], ['success', 'warning', 'error'], true) ? $notice['type'] : 'info'); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>

            <div class="card wutm-third-party-installer">
                <h2>官方外掛狀態</h2>
                <?php if ($active) : ?>
                    <p><strong class="wutm-status-success">已安裝並啟用</strong> — 可前往「外掛 → 已安裝的外掛」管理或設定此官方外掛。</p>
                <?php elseif ($installed) : ?>
                    <p><strong class="wutm-status-warning">已安裝，尚未啟用</strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($config['activate_action']); ?>">
                        <?php wp_nonce_field($config['activate_action']); ?>
                        <?php submit_button('啟用 ' . $config['name'], 'primary', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <p>按下按鈕後，系統會透過 WordPress 原生安裝流程，從 <code>wordpress.org</code> 下載、安裝並啟用官方 <?php echo esc_html($config['name']); ?> 外掛。</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($config['install_action']); ?>">
                        <?php wp_nonce_field($config['install_action']); ?>
                        <?php submit_button('安裝並啟用 ' . $config['name'], 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <p><a href="<?php echo esc_url($config['plugin_url']); ?>" target="_blank" rel="noopener noreferrer">查看 <?php echo esc_html($config['name']); ?> 官方外掛頁面</a></p>
        </div>
        <?php
    }
}
