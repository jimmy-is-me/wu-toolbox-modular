<?php
defined('ABSPATH') || exit;

/** Select the installable artifact from the project's published release metadata. */
function wutm_wp_booking_release_asset(array $release): ?array {
    $tag = $release['tag_name'] ?? '';
    if (!is_string($tag) || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $tag)
        || !empty($release['draft']) || !empty($release['prerelease'])) return null;
    $url = 'https://github.com/jimmy-is-me/WP-Booking/releases/download/' . $tag . '/wp-booking.zip';
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        if (($asset['name'] ?? '') !== 'wp-booking.zip' || ($asset['browser_download_url'] ?? '') !== $url) continue;
        $digest = $asset['digest'] ?? '';
        if (is_string($digest) && preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $match)) {
            return ['url' => $url, 'sha256' => $match[1]];
        }
    }
    return null;
}

function wutm_enable_wp_booking() {
    if (!wutm_toolbox_can_manage() || !current_user_can('activate_plugins') || !wutm_license_is_valid()) {
        return new WP_Error('wutm_booking_access', '需要有效的 Wu Toolbox 授權及外掛啟用權限。');
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = 'wp-booking/wp-booking.php';
    if (is_plugin_active($plugin)) return true;
    if (is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
        $activated = activate_plugin($plugin);
        return is_wp_error($activated) ? $activated : true;
    }
    if (!current_user_can('install_plugins')) return new WP_Error('wutm_booking_install_access', '此帳號沒有安裝外掛的權限。');

    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (get_filesystem_method() !== 'direct') {
        return new WP_Error('wutm_booking_filesystem', '此主機需要檔案系統憑證。請用 WordPress 的外掛安裝畫面安裝 WP Booking，再開啟卡片。');
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (!WP_Upgrader::create_lock('wutm_wp_booking_install', 180)) {
        return new WP_Error('wutm_booking_busy', 'WP Booking 正在安裝，請稍候再試。');
    }
    $archive = null;
    try {
        if (!is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
            $response = wp_safe_remote_get('https://api.github.com/repos/jimmy-is-me/WP-Booking/releases/latest', [
                'timeout' => 10, 'limit_response_size' => 512 * 1024,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]);
            $release = wp_remote_retrieve_response_code($response) === 200
                ? json_decode(wp_remote_retrieve_body($response), true) : null;
            $asset = is_array($release) ? wutm_wp_booking_release_asset($release) : null;
            if (!$asset) return new WP_Error('wutm_booking_release', '無法取得 WP Booking 最新正式版或其檔案校驗值。');

            $archive = download_url($asset['url'], 60);
            if (is_wp_error($archive)) return $archive;
            if (!hash_equals($asset['sha256'], (string) hash_file('sha256', $archive))) {
                return new WP_Error('wutm_booking_checksum', 'WP Booking 安裝檔校驗失敗。');
            }

            $check_source = static function ($source) {
                global $wp_filesystem;
                if (is_wp_error($source)) return $source;
                return basename(untrailingslashit($source)) === 'wp-booking' && $wp_filesystem->is_file(trailingslashit($source) . 'wp-booking.php')
                    ? $source : new WP_Error('wutm_booking_package', 'WP Booking 套件缺少預期的外掛入口。');
            };
            add_filter('upgrader_source_selection', $check_source);
            ob_start();
            try {
                $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
                $result = $upgrader->install($archive);
            } finally {
                ob_end_clean();
                remove_filter('upgrader_source_selection', $check_source);
            }
            if (is_wp_error($result)) return $result;
            if (!$result || !is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
                return new WP_Error('wutm_booking_install', 'WP Booking 安裝未完成，請檢查外掛目錄的寫入權限。');
            }
        }

        if (is_plugin_active($plugin)) return true;
        $activated = activate_plugin($plugin);
        return is_wp_error($activated) ? $activated : true;
    } finally {
        if (is_string($archive) && is_file($archive)) wp_delete_file($archive);
        WP_Upgrader::release_lock('wutm_wp_booking_install');
    }
}
