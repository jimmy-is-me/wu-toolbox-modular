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
