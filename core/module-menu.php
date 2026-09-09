<?php
defined('ABSPATH') || exit;

/**
 * Load the shared admin stylesheet only on WU Toolbox screens.
 * Keeping CSS in assets/css/admin.css makes every module inherit one visual system.
 */
add_action('admin_enqueue_scripts', function (): void {
    $screen = get_current_screen();
    if (!$screen) return;

    $id = (string) $screen->id;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $is_wu_screen = $id === 'toplevel_page_wu-toolbox-modular'
        || strpos($id, 'wu-toolbox-modular_page_wu-') === 0
        || $page === 'wu-toolbox-modular'
        || strpos($page, 'wu-') === 0;
    if (!$is_wu_screen) return;

    wp_enqueue_style(
        'wutm-admin',
        WUTM_URL . 'assets/css/admin.css',
        [],
        WUTM_VERSION
    );
});

/**
 * Keep the WordPress submenu in the exact same grouped order and naming
 * as the dashboard cards. Modules may register their own pages, so we
 * normalize the existing submenu entries after all modules have loaded.
 */
add_action('admin_menu', function (): void {
    global $submenu;
    $parent = 'wu-toolbox-modular';
    if (empty($submenu[$parent]) || !function_exists('wutm_modules')) return;

    $groups = wutm_grouped_modules();

    $entries = $submenu[$parent];
    $ordered = [];
    $used = [];

    // Keep the dashboard link first.
    foreach ($entries as $index => $entry) {
        if (($entry[2] ?? '') === $parent) {
            $ordered[] = $entry;
            $used[$index] = true;
            break;
        }
    }

    foreach ($groups as $group => $items) {
        $group_entries = [];
        foreach ($items as $key => $module) {
            $needles = ['wu-' . $key];
            if (!empty($module['settings_page'])) $needles[] = (string) $module['settings_page'];
            if (!empty($module['settings_url'])) {
                $query = wp_parse_url((string) $module['settings_url'], PHP_URL_QUERY);
                if (is_string($query)) {
                    $settings_args = [];
                    parse_str($query, $settings_args);
                    if (!empty($settings_args['page'])) $needles[] = (string) $settings_args['page'];
                }
            }
            foreach ($entries as $index => $entry) {
                $slug = (string) ($entry[2] ?? '');
                if (isset($used[$index]) || !in_array($slug, $needles, true)) continue;
                $entry[0] = $module['name'];
                $group_entries[] = $entry;
                $used[$index] = true;
                break;
            }
        }
        if ($group_entries) {
            $group_slug = 'wutm-group-' . sanitize_title($group);
            $ordered[] = ['<span class="wutm-submenu-group-label">' . esc_html($group) . '</span>', 'read', $group_slug];
            foreach ($group_entries as $entry) $ordered[] = $entry;
        }
    }

    // Preserve any third-party/custom submenu entries not represented in the registry.
    foreach ($entries as $index => $entry) {
        if (!isset($used[$index])) $ordered[] = $entry;
    }
    $submenu[$parent] = $ordered;
}, 999);

/** Hide installer shortcuts without removing registered page access or native plugin menus. */
add_action('admin_head', function (): void {
    if (!function_exists('wutm_modules')) return;
    $selectors = [];
    foreach (wutm_modules() as $key => $module) {
        if (($module['tag'] ?? '') !== '第三方外掛') continue;
        $slug = 'wu-' . sanitize_key($key);
        $selectors[] = '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=' . $slug . '"])';
    }
    if ($selectors) echo '<style>' . implode(',', $selectors) . '{display:none!important}</style>';
    echo '<style>#adminmenu .wp-submenu a[href*="page=wutm-group-"]{pointer-events:none!important;cursor:default!important;margin:9px 10px 3px!important;padding:7px 0 4px!important;border-top:1px solid rgba(255,255,255,.16)!important;color:#72aee6!important;font-size:11px!important;font-weight:700!important;letter-spacing:.08em!important;text-transform:uppercase!important}#adminmenu .wp-submenu li:first-child a[href*="page=wutm-group-"]{margin-top:3px!important;border-top:0!important}</style>';
});

add_action('admin_footer', function (): void {
    echo '<script>document.querySelectorAll("#adminmenu .wp-submenu a[href*=\\"page=wutm-group-\\"]").forEach(function(link){link.setAttribute("aria-disabled","true");link.setAttribute("tabindex","-1");link.addEventListener("click",function(event){event.preventDefault();});});</script>';
});
