<?php
/** Tech Precision presentation adapter. Never changes module options or callbacks. */
defined('ABSPATH') || exit;

/** New modules use this presentation contract; form ownership stays with callers. */
function wutm_admin_page_open(string $title, string $description = ''): void {
    echo '<div class="wrap wutm-ui-root"><header class="wutm-ui-header"><h1>' . esc_html($title) . '</h1>';
    if ($description !== '') echo '<p>' . esc_html($description) . '</p>';
    echo '</header>';
}
function wutm_admin_page_close(): void { echo '</div>'; }

/** Resolve ownership from the registered page callback, not a URL prefix. */
function wutm_admin_design_screen(): bool {
    if (!is_admin()) return false;
    $screen = get_current_screen();
    if (!$screen || empty($screen->id)) return false;
    global $wp_filter;
    $hook = $wp_filter[(string) $screen->id] ?? null;
    if (!$hook || empty($hook->callbacks)) return false;
    foreach ($hook->callbacks as $callbacks) {
        foreach ($callbacks as $entry) {
            $callback = $entry['function'] ?? null;
            if (!is_callable($callback)) continue;
            try {
                if (is_array($callback)) {
                    $reflection = new ReflectionMethod($callback[0], $callback[1]);
                } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                    $reflection = new ReflectionMethod($callback);
                } elseif (is_object($callback) && !($callback instanceof Closure)) {
                    $reflection = new ReflectionMethod($callback, '__invoke');
                } else {
                    $reflection = new ReflectionFunction($callback);
                }
                $file = str_replace('\\', '/', (string) $reflection->getFileName());
                $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';
                if (strpos($file, $root . 'modules/') === 0 || strpos($file, $root . 'core/') === 0) return true;
            } catch (ReflectionException $exception) {
                // Unknown callbacks do not confer ownership of another plugin's screen.
            }
        }
    }
    return false;
}

add_filter('admin_body_class', function (string $classes): string {
    return wutm_admin_design_screen() ? $classes . ' wutm-admin-screen' : $classes;
});

add_action('admin_enqueue_scripts', function (): void {
    if (!wutm_admin_design_screen()) return;
    // Late enqueue keeps shared tokens after module styles, without loading modules.
    wp_enqueue_style('wutm-admin', WUTM_URL . 'assets/css/admin.css', [], WUTM_VERSION);
    wp_enqueue_style('wutm-admin-components', WUTM_URL . 'assets/css/admin-components.css', ['wutm-admin'], (string) filemtime(__DIR__ . '/../assets/css/admin-components.css'));
    wp_enqueue_script('wutm-admin-design', WUTM_URL . 'assets/js/admin-design.js', [], (string) filemtime(__DIR__ . '/../assets/js/admin-design.js'), true);
}, 100);
