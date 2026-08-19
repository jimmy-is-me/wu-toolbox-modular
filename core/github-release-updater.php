<?php
defined('ABSPATH') || exit;

final class WUTM_GitHub_Release_Updater {
    private const REPOSITORY = 'jimmy-is-me/wu-toolbox-modular';
    private const ASSET_NAME = 'wu-toolbox-modular.zip';
    private const CACHE_KEY = 'wutm_github_release_v1';
    private const CACHE_TTL = 43200;

    private string $plugin_basename;

    public function __construct(string $plugin_file) {
        $this->plugin_basename = plugin_basename($plugin_file);
        add_filter('site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download_release_asset'], 20, 3);
    }

    public function inject_update($transient) {
        if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[$this->plugin_basename])) return $transient;
        $release = $this->get_release();
        if (!$release || !version_compare($release['version'], WUTM_VERSION, '>')) return $transient;

        $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $transient->response[$this->plugin_basename] = (object) [
            'slug' => 'wu-toolbox-modular',
            'plugin' => $this->plugin_basename,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['asset_api_url'],
            'icons' => [], 'banners' => [], 'banners_rtl' => [],
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'autoupdate' => false,
        ];
        return $transient;
    }

    public function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wu-toolbox-modular') return $result;
        $release = $this->get_release();
        if (!$release) return $result;
        return (object) [
            'name' => 'WU Toolbox Modular',
            'slug' => 'wu-toolbox-modular',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/jimmy-is-me">Wumetax</a>',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'download_link' => $release['asset_api_url'],
            'sections' => ['description' => 'WU Toolbox 的按需載入模組化版本。', 'changelog' => wp_kses_post($release['body'] ?: '請參閱 GitHub Release 說明。')],
        ];
    }

    public function download_release_asset($reply, $package, $upgrader) {
        $release = $this->get_release();
        if (!$release || $package !== $release['asset_api_url']) return $reply;
        $response = wp_remote_get($package, ['timeout' => 45, 'redirection' => 5, 'headers' => ['Accept' => 'application/octet-stream', 'User-Agent' => $this->user_agent()]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return is_wp_error($response) ? $response : new WP_Error('wutm_release_download_failed', __('無法下載 GitHub Release 更新檔。', 'wu-toolbox-modular'));
        }
        $file = wp_tempnam(self::ASSET_NAME);
        if (!$file || false === file_put_contents($file, wp_remote_retrieve_body($response))) {
            return new WP_Error('wutm_release_write_failed', __('無法建立更新暫存檔。', 'wu-toolbox-modular'));
        }
        return $file;
    }

    private function get_release(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached ?: null;
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', ['timeout' => 12, 'redirection' => 3, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => $this->user_agent()]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::CACHE_KEY, [], self::CACHE_TTL);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $tag = is_array($data) ? ltrim((string) ($data['tag_name'] ?? ''), 'vV') : '';
        $asset = null;
        foreach ((array) ($data['assets'] ?? []) as $candidate) {
            if (($candidate['name'] ?? '') === self::ASSET_NAME && !empty($candidate['url'])) {$asset = $candidate; break;}
        }
        if (!$asset || !preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $tag)) {
            set_site_transient(self::CACHE_KEY, [], self::CACHE_TTL);
            return null;
        }
        $release = ['version' => $tag, 'asset_api_url' => esc_url_raw($asset['url']), 'html_url' => esc_url_raw((string) ($data['html_url'] ?? 'https://github.com/' . self::REPOSITORY . '/releases')), 'body' => (string) ($data['body'] ?? '')];
        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private function user_agent(): string { return 'WU-Toolbox-Modular/' . WUTM_VERSION . '; ' . home_url('/'); }
}
new WUTM_GitHub_Release_Updater(WUTM_FILE);
