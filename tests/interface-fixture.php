<?php
/** CLI-only regression/visual fixture. Never expose a production endpoint. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'WUTM_URL', '/' );
define( 'WUTM_VERSION', 'test' );
$hooks = array(); $scripts = array(); $styles = array(); $meta = array(); $admin = false; $capable = true;
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter( ...$args ) { add_action( ...$args ); }
function get_option( $key, $default = false ) { return $default; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function get_bloginfo( $key ) { return 'Demo Shop'; }
function is_admin() { return $GLOBALS['admin']; }
function current_user_can( ...$args ) { return $GLOBALS['capable']; }
function get_current_screen() { return (object) array( 'post_type' => $GLOBALS['post_type'] ?? 'product' ); }
function wp_enqueue_style( ...$args ) { $GLOBALS['styles'][] = $args; }
function wp_enqueue_script( ...$args ) { $GLOBALS['scripts'][] = $args; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$key] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$key] ); }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][$key] ); }
function wp_verify_nonce( $value, $action ) { return 'valid' === $value; }
function wp_is_post_autosave( $id ) { return false; }
function wp_is_post_revision( $id ) { return false; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_kses_post( $value ) { return strip_tags( $value, '<p><strong><em><span><a><br><ul><ol><li>' ); }
function wp_unique_id( $prefix ) { static $id = 0; return $prefix . ++$id; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function selected( $value, $expected ) { if ( $value === $expected ) echo 'selected'; }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid">'; }
require dirname( __DIR__ ) . '/modules/product-faq/module.php';
require dirname( __DIR__ ) . '/modules/page-transition/module.php';
require dirname( __DIR__ ) . '/modules/custom-cursor/module.php';
$mode = $argv[1] ?? 'policy';
if ( 'policy' === $mode ) {
    function check( $value, $message ) { if ( ! $value ) throw new RuntimeException( $message ); }
    wutm_product_faq_admin_assets( 'edit.php' );
    check( ! $styles && ! $scripts, 'No FAQ assets on lists.' );
    $post_type = 'post'; wutm_product_faq_admin_assets( 'post.php' );
    check( ! $styles && ! $scripts, 'No FAQ assets on unrelated post types.' );
    $post_type = 'product'; wutm_product_faq_admin_assets( 'post.php' );
    check( count( $styles ) === 1 && count( $scripts ) === 1, 'Only product editor loads FAQ assets.' );
    $meta['_custom_faq_data'] = array( array( 'question' => '原問題', 'answer' => '<p>原答案</p>' ) );
    $_POST = array(); wutm_product_faq_save_data( 7 );
    check( $meta['_custom_faq_data'][0]['question'] === '原問題', 'Quick edit/missing nonce preserves FAQ.' );
    $_POST = array( 'wutm_product_faq_nonce' => 'invalid', 'faq_question' => array( 'bad' ) ); wutm_product_faq_save_data( 7 );
    check( $meta['_custom_faq_data'][0]['question'] === '原問題', 'Invalid nonce cannot change FAQ.' );
    $_POST = array( 'wutm_product_faq_nonce' => 'valid', 'faq_behavior' => 'toggle', 'faq_question' => array( '新問題', '', '第二題' ), 'faq_answer' => array( '<strong>新答案</strong>', '', '<p>格式保留</p>' ) );
    $capable = false; wutm_product_faq_save_data( 7 );
    check( $meta['_custom_faq_data'][0]['question'] === '原問題', 'Missing edit capability preserves FAQ.' );
    $capable = true; wutm_product_faq_save_data( 7 );
    check( count( $meta['_custom_faq_data'] ) === 2 && $meta['_custom_faq_behavior'] === 'toggle', 'Compatible saved names and behavior.' );
    check( $meta['_custom_faq_data'][0]['answer'] === '<strong>新答案</strong>', 'Rich text preserved.' );
    $scripts = array(); Wumetax_Brand_Transition_v220::frontend_script();
    check( $scripts[0][0] === 'wutm-page-transition' && ! isset( $hooks['wp_footer'] ), 'Transition enqueued normally, not injected after footer scripts.' );
    echo "Interface policy passed: product-only assets, permissions, nonces, data preservation and normal script loading.\n"; exit;
}
echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">';
if ( 'transition' === $mode ) Wumetax_Brand_Transition_v220::frontend_head();
if ( 'cursor' === $mode ) {
    foreach ( $hooks['wp_head'] ?? array() as $callback ) if ( $callback instanceof Closure && str_contains( (new ReflectionFunction( $callback ))->getFileName(), 'custom-cursor' ) ) $callback();
}
if ( 'faq' === $mode ) echo '<link rel="stylesheet" href="/assets/css/product-faq-admin.css">';
echo '<style>body{margin:16px;font:14px Arial;color:#243b53;background:#f0f3f6}input,select,.button{font:inherit;padding:8px;border:1px solid #ccd0d4;border-radius:4px}.button{background:#fff;color:#2271b1;text-decoration:none;cursor:pointer}.button-primary{background:#2271b1;color:#fff}#wutm_product_faq_box{max-width:1200px;margin:auto}main{min-height:1800px}a{display:inline-block;margin:10px}</style></head><body>';
if ( 'faq' === $mode ) {
    $meta['_custom_faq_data'] = array( array( 'question' => '需要多久出貨？', 'answer' => '<p>下單後 <strong>3～5 個工作天</strong>出貨。</p>' ), array( 'question' => '可以退換貨嗎？', 'answer' => '<p>請聯絡客服協助辦理。</p>' ) );
    echo '<form id="post"><div id="wutm_product_faq_box">';
    wutm_product_faq_render_meta_box( (object) array( 'ID' => 7 ) );
    echo '</div><button id="save">儲存</button></form><script src="/assets/js/product-faq-admin.js"></script>';
} elseif ( 'cursor' === $mode ) {
    echo '<main><a id="link" href="#">連結</a><input id="text" placeholder="輸入"><iframe id="frame" srcdoc="<p>frame</p>"></iframe></main><script src="/assets/js/custom-cursor.js"></script>';
} else {
    Wumetax_Brand_Transition_v220::frontend_markup();
    echo '<a id="fast" href="/fast/">快速頁面</a><a id="slow" href="/slow/">慢速頁面</a><a id="cancel" href="/cancel/">取消導向</a><a id="anchor" href="#section">錨點</a><a id="ajax" class="ajax_add_to_cart" href="/ajax/">AJAX</a><div id="section"></div><script>document.querySelector("#cancel").addEventListener("click",e=>e.preventDefault());document.querySelector("#ajax").addEventListener("click",e=>e.preventDefault());</script><script src="/assets/js/page-transition.js"></script>';
}
echo '</body></html>';

