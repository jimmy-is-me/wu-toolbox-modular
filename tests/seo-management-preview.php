<?php
ob_start(); require __DIR__ . '/seo-core.php'; ob_end_clean();
$_POST = $_GET = $_REQUEST = []; $options = []; $meta = []; $allowed = true;
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $v ) { return '/wp-admin/' . $v; }
function wp_count_posts( $type ) { return (object) [ 'publish' => 5 ]; }
function add_query_arg( $key, $value, $url ) { return $url . '&' . urlencode( $key ) . '=' . urlencode( $value ); }
function selected( $a, $b, $echo = true ) { $s = $a === $b ? ' selected' : ''; if ( $echo ) echo $s; return $s; }
function wp_nonce_field( ...$args ) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function get_current_user_id() { return 1; }
function get_current_screen() { return (object) [ 'post_type' => 'post', 'base' => 'edit' ]; }
function wutm_admin_page_open( $title, $desc ) { echo '<div class="wrap wutm-ui-root"><header class="wutm-ui-header"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $desc ) . '</p></header>'; }
function wutm_admin_page_close() { echo '</div>'; }
Wumetax_SEO_Core_v120::seo_tools_page();
Wumetax_SEO_Core_v120::seo_side_panel();
