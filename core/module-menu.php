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
    if ($id !== 'toplevel_page_wu-toolbox-modular' && strpos($id, 'wu-toolbox-modular_page_wu-') !== 0) {
        return;
    }

    wp_enqueue_style(
        'wutm-admin',
        WUTM_URL . 'assets/css/admin.css',
        [],
        WUTM_VERSION
    );
});
