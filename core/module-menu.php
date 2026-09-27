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
        || strpos($id, 'wu-toolbox-modular_page_') === 0
        || $page === 'wu-toolbox-modular'
        || strpos($page, 'wu-') === 0
        || strpos($page, 'wumetax-') === 0
        || strpos($page, 'site-ai-') === 0;
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
/** Compare a registered menu slug with either a plain slug or an admin URL. */
function wutm_menu_slug_matches(string $entry_slug, string $needle): bool {
    if ($entry_slug === $needle) return true;

    $entry_query = wp_parse_url($entry_slug, PHP_URL_QUERY);
    $needle_query = wp_parse_url($needle, PHP_URL_QUERY);
    $entry_args = [];
    $needle_args = [];
    if (is_string($entry_query)) parse_str($entry_query, $entry_args);
    if (is_string($needle_query)) parse_str($needle_query, $needle_args);
    if (!$entry_args && strpos($entry_slug, '&') !== false) parse_str('page=' . $entry_slug, $entry_args);
    if (!$needle_args && strpos($needle, '&') !== false) parse_str('page=' . $needle, $needle_args);
    $entry_page = isset($entry_args['page']) ? (string) $entry_args['page'] : $entry_slug;
    $needle_page = isset($needle_args['page']) ? (string) $needle_args['page'] : $needle;

    if ($entry_page !== $needle_page) return false;
    foreach ($needle_args as $key => $value) {
        if ($key === 'page') continue;
        if (!isset($entry_args[$key]) || (string) $entry_args[$key] !== (string) $value) return false;
    }
    return true;
}

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
            $external_settings_active = !empty($module['settings_when_active'])
                && function_exists('wutm_third_party_plugin_active')
                && wutm_third_party_plugin_active($key);
            if ((!wutm_is_enabled($key) && !$external_settings_active) || ((($module['tag'] ?? '') === '第三方外掛') && !$external_settings_active)) continue;
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
                if (isset($used[$index]) || !array_filter($needles, static fn($needle) => wutm_menu_slug_matches($slug, $needle))) continue;
                $entry[0] = $module['name'];
                $group_entries[] = $entry;
                $used[$index] = true;
                break;
            }
            foreach ((array) ($module['related_pages'] ?? []) as $related_slug) {
                foreach ($entries as $index => $entry) {
                    if (isset($used[$index]) || !wutm_menu_slug_matches((string) ($entry[2] ?? ''), (string) $related_slug)) continue;
                    $group_entries[] = $entry;
                    $used[$index] = true;
                    break;
                }
            }
        }
        if ($group_entries) {
            $group_slug = 'wutm-group-' . sanitize_title($group);
            $ordered[] = ['<span class="wutm-submenu-group-label" data-group="' . esc_attr($group_slug) . '">' . esc_html($group) . '</span>', 'read', $group_slug];
            foreach ($group_entries as $entry) $ordered[] = $entry;
        }
    }

    // Preserve any third-party/custom submenu entries not represented in the registry.
    foreach ($entries as $index => $entry) {
        if (!isset($used[$index])) $ordered[] = $entry;
    }
    $submenu[$parent] = $ordered;
}, 9999);

/** Hide installer shortcuts without removing registered page access or native plugin menus. */
add_action('admin_head', function (): void {
    if (!function_exists('wutm_modules')) return;
    $selectors = [];
    foreach (wutm_modules() as $key => $module) {
        if (($module['tag'] ?? '') !== '第三方外掛' || !empty($module['settings_when_active'])) continue;
        $slug = 'wu-' . sanitize_key($key);
        $selectors[] = '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=' . $slug . '"])';
    }
    if ($selectors) echo '<style>' . implode(',', $selectors) . '{display:none!important}</style>';
    // The shared stylesheet is limited to Toolbox screens, so mirror only these
    // small menu rules here to keep the submenu usable on every admin screen.
    echo '<style>#adminmenu .wp-submenu .wutm-submenu-group-link{display:block!important;position:relative!important;box-sizing:border-box!important;min-height:30px!important;margin:3px 10px 1px!important;padding:6px 28px 5px 12px!important;border-top:1px solid rgba(255,255,255,.2)!important;color:#72aee6!important;font-size:11px!important;font-weight:700!important;letter-spacing:.03em!important;line-height:18px!important;white-space:normal!important;overflow:visible!important;overflow-wrap:anywhere!important;cursor:pointer!important}#adminmenu .wp-submenu .wutm-submenu-group-link:focus{color:#fff!important;box-shadow:0 0 0 1px #72aee6!important;outline:0!important}#adminmenu .wp-submenu .wutm-submenu-group-link:after{content:"＋";position:absolute;right:9px;top:6px;color:#a7aaad;font-size:12px}#adminmenu .wp-submenu .wutm-submenu-group-link[aria-expanded="true"]:after{content:"−"}#adminmenu .wp-submenu li.wutm-menu-group-hidden{display:none!important}</style>';
    // Match the active item to the inset group headings. A full-width native
    // highlight otherwise appears to protrude over adjacent category rows.
    echo '<style>#adminmenu #toplevel_page_wu-toolbox-modular .wp-submenu>li.current{position:static!important;box-sizing:border-box!important;padding:3px 10px!important}#adminmenu #toplevel_page_wu-toolbox-modular .wp-submenu>li.current>a{position:static!important;display:block!important;box-sizing:border-box!important;width:100%!important;height:auto!important;min-height:30px!important;margin:0!important;border-radius:3px!important;white-space:normal!important;overflow-wrap:anywhere!important}</style>';
    // WordPress positions inactive submenus absolutely to the right of the
    // admin rail. The Toolbox has many grouped entries, so that flyout covers
    // the page. Keep its submenu in the rail's document flow on desktop.
    // When the native rail is folded, reserve room for the wider menu instead.
    // The selected top-level anchor must remain in its own row. The previous
    // active-item rule targeted submenu children only, leaving this anchor
    // free to overlap category rows when another admin style repositions it.
    echo '<style>#adminmenu #toplevel_page_wu-toolbox-modular>a.menu-top{position:static!important;inset:auto!important;float:none!important;transform:none!important;box-sizing:border-box!important;max-width:100%!important;margin:0!important}#adminmenu #toplevel_page_wu-toolbox-modular>a.menu-top:after{pointer-events:none}@media(min-width:783px){#adminmenu #toplevel_page_wu-toolbox-modular .wp-submenu{position:static!important;top:auto!important;left:auto!important;right:auto!important;width:160px!important;min-width:0!important;box-shadow:none!important}body.folded #adminmenu #toplevel_page_wu-toolbox-modular .wp-submenu{position:absolute!important;top:34px!important;left:36px!important;right:auto!important;width:176px!important;max-height:calc(100vh - 16px)!important;overflow-y:auto!important}body.folded.wutm-menu-flyout-open #wpcontent,body.folded.wutm-menu-flyout-open #wpfooter{margin-left:214px!important}}@media(min-width:783px) and (max-width:960px){body.auto-fold #adminmenu #toplevel_page_wu-toolbox-modular .wp-submenu{position:absolute!important;top:34px!important;left:36px!important;right:auto!important;width:176px!important;max-height:calc(100vh - 16px)!important;overflow-y:auto!important}body.auto-fold.wutm-menu-flyout-open #wpcontent,body.auto-fold.wutm-menu-flyout-open #wpfooter{margin-left:214px!important}}</style>';
});

/** The WU Toolbox sidebar needs its grouped menu behavior on every admin screen. */
add_action('admin_enqueue_scripts', function (): void {
    wp_enqueue_script(
        'wutm-module-menu',
        WUTM_URL . 'assets/js/module-menu.js',
        [],
        WUTM_VERSION,
        true
    );
});
