<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
$enabled = ($argv[1] ?? '') === 'enabled';
$options = $enabled ? ['disable_random' => true, 'slow_heartbeat' => true, 'skip_self_ping' => true] : [];
$hooks = [];
$screen = null;
function get_option($key, $default = false) { return $GLOBALS['options']; }
function add_filter($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function add_action($hook, $callback) { add_filter($hook, $callback); }
function __return_false() { return false; }
function get_current_screen() { return $GLOBALS['screen']; }
function wp_parse_url($url) { return parse_url($url); }
function home_url($path) { return 'https://example.test/site' . $path; }
function check_optimization($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}

require dirname(__DIR__) . '/modules/website-optimization/module.php';
if (!$enabled) {
    check_optimization(array_keys($hooks) === ['admin_menu'], 'A newly enabled card applies no optimizations');
    echo "Website optimization defaults passed.\n";
    exit;
}

check_optimization($hooks['wp_enable_random_content_redirect']() === false, 'Random redirects disabled');
foreach (['dashboard', 'edit-post', 'edit-page', 'post', 'product', 'woocommerce_page_wc-orders', null] as $id) {
    $screen = $id === null ? null : new class($id) {
        public $id;
        public function __construct($id) { $this->id = $id; }
        public function in_admin($context) { return false; }
    };
    $expected = in_array($id, ['dashboard', 'edit-post', 'edit-page'], true) ? 60 : 15;
    check_optimization($hooks['heartbeat_settings'](['interval' => 15])['interval'] === $expected, 'Heartbeat scope for ' . ($id ?? 'frontend'));
    check_optimization($hooks['heartbeat_settings'](['interval' => 120])['interval'] === 120, 'Retain an existing longer interval');
}

$links = [
    'http://example.test/site/article/',
    'https://example.test/site',
    'https://example.test/site-other/article/',
    'https://example.test.evil/site/article/',
];
$hooks['pre_ping']($links);
check_optimization(array_values($links) === [
    'https://example.test/site-other/article/',
    'https://example.test.evil/site/article/',
], 'Only the current site and path are skipped');
echo "Website optimization scoped hooks passed.\n";
