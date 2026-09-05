<?php
// Standalone regression checks: no live WordPress database or network required.
$root = sys_get_temp_dir() . '/wutm-mir-test-' . bin2hex(random_bytes(6));
mkdir($root . '/wp-admin/includes', 0755, true);
mkdir($root . '/uploads');
file_put_contents($root . '/wp-admin/includes/file.php', '<?php');
define('ABSPATH', $root . '/');
function add_action(...$args) {}
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_upload_dir(...$args) { return ['basedir' => $GLOBALS['root'] . '/uploads']; }
class WP_Error { public $code; public function __construct($code, $message) {$this->code=$code;} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_tempnam($name) { return tempnam($GLOBALS['root'], 'download-'); }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0755, true); }
function wp_get_image_mime($path) { $v=@getimagesize($path); return $v['mime'] ?? false; }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests']++;
    file_put_contents($args['filename'], $GLOBALS['body']);
    return ['code' => $GLOBALS['http'], 'length' => strlen($GLOBALS['body'])];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_header($r, $key) { return $key === 'content-length' ? $r['length'] : ''; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require dirname(__DIR__) . '/modules/missing-product-images/module.php';
function clean_fixture($path) {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (array_diff(scandir($path), ['.', '..']) as $name) clean_fixture($path . '/' . $name);
    rmdir($path);
}
try {
    $class = WUTM_Missing_Product_Images::class;
    $html = '<img src="https://dest.example/wp-content/uploads/2026/a.png" srcset="//dest.example/wp-content/uploads/2026/b.webp 2x, /wp-content/uploads/2026/c.jpg 1x" data-src="wp-content/uploads/2026/%E5%9C%96.png"><img src="https://foreign.example/wp-content/uploads/2026/foreign.png"><img src="/wp-content/uploads/%2e%2e/escape.png">';
    check($class::extract_paths($html, 'https://dest.example') === ['2026/a.png','2026/b.webp','2026/c.jpg','2026/圖.png'], 'URLs, srcset, lazyload, Unicode, foreign-domain exclusion');
    check($class::extract_paths('https://dest.example/shop/wp-content/uploads/a.png', 'https://dest.example/shop') === ['a.png'], 'Subdirectory site');
    check($class::extract_paths('https:\/\/dest.example\/wp-content\/uploads\/a.png', 'https://dest.example') === ['a.png'], 'Escaped JSON URL');
    foreach (['../a.png','/a.png','a//b.png','a.php','a.php.png/..','a/%2e%2e/a.png','a\\b.png','a:stream.png','.htaccess','a.svg',"a\0.png"] as $bad) check(!$class::valid_path($bad), 'Rejected path: ' . $bad);
    $method = new ReflectionMethod($class, 'repair'); $method->setAccessible(true);
    $settings = ['source_url'=>'https://source.example', 'attachment'=>false];
    $requests = 0; $http=200; $body=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $result=$method->invoke(null, '2026/test.png', $settings);
    check($result['status']==='repaired' && file_get_contents($root.'/uploads/2026/test.png')===$body, 'Restore exact bytes and path');
    $body='malicious or error HTML';
    $result=$method->invoke(null, '2026/test.png', $settings);
    check($result['status']==='exists' && $requests===1, 'Existing files never downloaded or overwritten');
    check(is_wp_error($method->invoke(null, '2026/bad.png', $settings)) && !file_exists($root.'/uploads/2026/bad.png'), 'Reject disguised HTML');
    $http=404;
    check(is_wp_error($method->invoke(null, '2026/missing.png', $settings)), 'Reject HTTP errors');
    check(is_wp_error($class::local_path('../outside.png')), 'Reject traversal');
    if (function_exists('symlink') && @symlink($root, $root.'/uploads/link')) check(is_wp_error($class::local_path('link/a.png')), 'Reject symlink escape');
    check(count(glob($root.'/download-*'))===0, 'Temporary downloads cleaned');
    echo "PASS: extraction, foreign hosts, traversal, MIME, no overwrite, exact restoration, HTTP errors, symlinks, temporary cleanup\n";
} finally { clean_fixture($root); }

