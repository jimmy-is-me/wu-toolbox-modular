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

    $groups = [];
    foreach (wutm_modules() as $key => $module) {
        $groups[$module['group']][$key] = $module;
    }

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

    foreach ($groups as $items) {
        foreach ($items as $key => $module) {
            $needle = 'wu-' . $key;
            foreach ($entries as $index => $entry) {
                $slug = (string) ($entry[2] ?? '');
                if (isset($used[$index]) || ($slug !== $needle && strpos($slug, $needle) === false)) continue;
                $entry[0] = $module['name'];
                $ordered[] = $entry;
                $used[$index] = true;
                break;
            }
        }
    }

    // Preserve any third-party/custom submenu entries not represented in the registry.
    foreach ($entries as $index => $entry) {
        if (!isset($used[$index])) $ordered[] = $entry;
    }
    $submenu[$parent] = $ordered;
}, 999);
