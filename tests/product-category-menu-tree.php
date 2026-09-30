<?php
/** Regression checks for complete product-category hierarchy and ordering. */
define('ABSPATH', __DIR__ . '/');
function add_action(...$args): void {}
function add_shortcode(...$args): void {}
function get_option($key, $default = []) { return ['hide_empty' => 1]; }
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
function shortcode_atts($defaults, $atts, $shortcode): array { return array_merge($defaults, (array) $atts); }
function is_wp_error($value): bool { return false; }
function is_tax($taxonomy): bool { return false; }
function wp_unique_id($prefix): string { return $prefix . 'test'; }
function wp_json_encode($value): string { return json_encode($value); }
function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value): string { return (string) $value; }
function absint($value): int { return abs((int) $value); }
function get_term_link($term): string { return 'https://example.test/category/' . $term->term_id; }
function get_term_meta($id, $key, $single = true) {
    $order = [2 => 1, 4 => 0];
    return $key === 'wutm_content_order_position' ? ($order[$id] ?? '') : '';
}
function get_terms($args): array {
    if ($args['hide_empty'] !== false || $args['hierarchical'] !== false) {
        throw new RuntimeException('The menu must fetch all levels before filtering empty categories.');
    }
    return [
        (object) ['term_id' => 1, 'parent' => 0, 'name' => 'Empty parent', 'count' => 0],
        (object) ['term_id' => 2, 'parent' => 1, 'name' => 'Populated child', 'count' => 3],
        (object) ['term_id' => 3, 'parent' => 0, 'name' => 'Empty branch', 'count' => 0],
        (object) ['term_id' => 4, 'parent' => 0, 'name' => 'First by saved order', 'count' => 2],
        (object) ['term_id' => 5, 'parent' => 999, 'name' => 'Orphan with products', 'count' => 1],
    ];
}

require dirname(__DIR__) . '/modules/product-category-menu/module.php';
$html = wutm_pcm_shortcode([]);
foreach (['Empty parent', 'Populated child', 'First by saved order', 'Orphan with products'] as $name) {
    if (strpos($html, $name) === false) throw new RuntimeException('Missing category: ' . $name);
}
if (strpos($html, 'Empty branch') !== false) throw new RuntimeException('An entirely empty branch should be hidden.');
if (strpos($html, 'First by saved order') > strpos($html, 'Empty parent')) throw new RuntimeException('Saved category order was not applied.');

echo "Product category menu hierarchy checks passed.\n";
