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
            if (!wutm_is_enabled($key) || (($module['tag'] ?? '') === '第三方外掛')) continue;
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
        if (($module['tag'] ?? '') !== '第三方外掛') continue;
        $slug = 'wu-' . sanitize_key($key);
        $selectors[] = '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=' . $slug . '"])';
    }
    if ($selectors) echo '<style>' . implode(',', $selectors) . '{display:none!important}</style>';
    // The shared stylesheet is limited to Toolbox screens, so mirror only these
    // small menu rules here to keep the submenu usable on every admin screen.
    echo '<style>#adminmenu .wp-submenu .wutm-submenu-group-link{display:block!important;margin:10px 10px 3px!important;padding:9px 2px 5px!important;border-top:1px solid rgba(255,255,255,.2)!important;color:#72aee6!important;font-size:11px!important;font-weight:700!important;letter-spacing:.08em!important;line-height:1.2!important;cursor:pointer!important}#adminmenu .wp-submenu .wutm-submenu-group-link:focus{color:#fff!important;box-shadow:0 0 0 1px #72aee6!important;outline:0!important}#adminmenu .wp-submenu .wutm-submenu-group-link:after{content:"＋";float:right;margin-right:8px;color:#a7aaad;font-size:12px}#adminmenu .wp-submenu .wutm-submenu-group-link[aria-expanded="true"]:after{content:"−"}#adminmenu .wp-submenu li.wutm-menu-group-hidden{display:none!important}</style>';
});

add_action('admin_footer', function (): void {
    echo '<script>(function(){var menu=document.querySelector("#toplevel_page_wu-toolbox-modular .wp-submenu");if(!menu)return;menu.querySelectorAll(".wutm-submenu-group-label").forEach(function(label){var link=label.closest("a"),heading=label.closest("li"),group=label.getAttribute("data-group");if(!link||!heading||!group)return;link.classList.add("wutm-submenu-group-link");link.setAttribute("role","button");link.setAttribute("aria-expanded","false");link.setAttribute("aria-label",label.textContent.trim()+"（展開分類）");heading.classList.add("wutm-menu-group-heading");var members=[];for(var item=heading.nextElementSibling;item&&!item.querySelector(".wutm-submenu-group-label");item=item.nextElementSibling){item.classList.add("wutm-menu-group-hidden");item.hidden=true;item.setAttribute("aria-hidden","true");members.push(item);}function toggle(){var expanded=link.getAttribute("aria-expanded")!=="true";link.setAttribute("aria-expanded",String(expanded));link.setAttribute("aria-label",label.textContent.trim()+(expanded?"（收合分類）":"（展開分類）"));members.forEach(function(item){item.hidden=!expanded;item.classList.toggle("wutm-menu-group-hidden",!expanded);item.setAttribute("aria-hidden",String(!expanded));});}link.addEventListener("click",function(event){event.preventDefault();toggle();});link.addEventListener("keydown",function(event){if(event.key===" "||event.key==="Spacebar"){event.preventDefault();toggle();}});});})();</script>';
});
