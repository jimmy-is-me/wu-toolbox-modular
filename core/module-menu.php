<?php
defined('ABSPATH') || exit;

/**
 * Adds one left-menu item for each enabled module and proxies its legacy
 * settings callback into a stable wu-<module> route.
 */
function wutm_legacy_module_slug(string $key): string {
    $map = [
        'moving-mode' => 'moving-mode',
        'no-cache-pages' => 'wu-no_cache_pages',
        'post-optimization' => 'wu-post-optimization-settings',
        'system-monitor' => 'wumetax-system-monitor',
    ];
    return $map[$key] ?? ('wu-' . $key);
}
function wutm_render_module_proxy(string $key): void {
    if (!current_user_can('manage_options')) return;
    $legacy_hook = get_plugin_page_hookname(wutm_legacy_module_slug($key), 'wumetax-toolkit');
    echo '<div class="wutm-module-page">';
    if (has_action($legacy_hook)) {
        do_action($legacy_hook);
    } else {
        $module = wutm_get_module($key);
        echo '<div class="wrap"><h1>' . esc_html($module['name'] ?? $key) . '</h1><div class="notice notice-warning"><p>此模組沒有可用的設定頁。</p></div></div>';
    }
    echo '</div>';
}
add_action('admin_menu', function (): void {
    foreach (wutm_modules() as $key => $module) {
        if ($key === 'media-encoder' || !wutm_is_enabled($key)) continue;
        if (($module['requires'] ?? '') === 'woocommerce' && !class_exists('WooCommerce')) continue;
        add_submenu_page(
            'wu-toolbox-modular',
            $module['name'],
            $module['name'],
            'manage_options',
            'wu-' . $key,
            static function () use ($key): void { wutm_render_module_proxy($key); }
        );
    }
}, 999);

/* Shared surface styling for every proxied module settings screen. */
add_action('admin_head', function (): void {
    $screen = get_current_screen();
    if (!$screen || strpos((string) $screen->id, 'wu-toolbox-modular_page_wu-') !== 0) return;
    ?>
    <style>
      .wutm-module-page .wrap{max-width:1200px;margin:20px 20px 0 0}
      .wutm-module-page .wrap>h1{background:#1d2327;color:#fff;padding:20px 24px;border-radius:10px;margin:0 0 24px;font-size:20px}
      .wutm-module-page .form-table,.wutm-module-page .card,.wutm-module-page form{background:#fff}
      .wutm-module-page .form-table{border-radius:10px;padding:10px 22px;margin-top:18px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
      .wutm-module-page .form-table th{color:#1d2327}
      .wutm-module-page .button-primary{background:#00a32a;border-color:#00a32a;border-radius:5px}
      .wutm-module-page .card{max-width:none!important;border-radius:10px;border:1px solid #dcdcde;box-shadow:none}
      .wutm-module-page .notice{border-radius:7px}
    </style>
    <?php
});
