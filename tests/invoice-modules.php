<?php
/** Offline bootstrap checks. No WordPress database or invoice API requests. */
error_reporting( E_ALL & ~E_DEPRECATED );
foreach(json_decode(file_get_contents(__DIR__.'/invoice-source-files.json'),true) as $file=>$hash){
    if(hash('sha256',str_replace("\r\n","\n",file_get_contents(dirname(__DIR__).'/modules/'.$file)))!==$hash)throw new RuntimeException('Invoice source preservation failed: '.$file);
}
define( 'ABSPATH', __DIR__ . '/' );
define('WUTM_PATH', dirname(__DIR__).'/');
function wutm_is_enabled($key){global $mode;return $mode==='both-active' && in_array($key,['ezpay-invoice','ecpay-invoice'],true);}
function current_user_can(...$args){return empty($GLOBALS['deny_capability']);}
function absint($n){return abs((int)$n);}
class InvoiceTestError extends RuntimeException {}
function check_ajax_referer(...$a){if(!empty($GLOBALS['bad_nonce']))throw new InvoiceTestError('nonce');}
function wp_send_json_error($data,$status=200){throw new InvoiceTestError('forbidden');}
function wc_get_order($id){return $id===123?(object)['id'=>123]:false;}
function add_submenu_page(...$args){$GLOBALS['submenus'][$args[4]]=$args;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function wutm_admin_page_open($title,$description=''){echo '<div class="wrap wutm-ui-root"><h1>'.esc_html($title).'</h1>';}
function wutm_admin_page_close(){echo '</div>';}
function admin_url($v){return '/wp-admin/'.$v;}
function wp_nonce_field($v){echo '<input name="_wpnonce" value="fixture">';}
function submit_button($label){echo '<button type="submit">'.esc_html($label).'</button>';}
function check_admin_referer(...$a){check_ajax_referer(...$a);}
function wp_die(...$a){throw new InvoiceTestError('forbidden');}
class WC_Admin_Settings {
 public static function output_fields($fields){foreach($fields as $field){if(!empty($field['id']))echo '<input name="'.esc_attr($field['id']).'">';}}
 public static function save_fields($fields){$GLOBALS['saved_fields']=$fields;throw new InvoiceTestError('save-captured');}
}
require_once WUTM_PATH.'core/invoice-modules.php';
$mode = $argv[1] ?? 'enabled';
$provider = $argv[2] ?? 'both';
$hooks = [];
$options = [];
if ( $mode !== 'no-woocommerce' ) {
    class WooCommerce {}
}
if ($mode === 'official') {define('WOOECPAY_VERSION','fixture');$options['wooecpay_enabled_invoice']='yes';}
if ( $mode === 'conflict' ) {
    define( 'WOOMP_PLUGIN_DIR', '/upstream/' );
}
if ( $mode === 'disabled' ) {
    $options['wc_woomp_enabled_ezpay_invoice'] = 'no';
    $options['wc_woomp_enabled_ecpay_invoice'] = 'no';
}
class WC_Settings_Page {
    public $id;
    public $label;
    public function __construct() {}
    public function get_settings( $section = '' ) { return []; }
}
function add_action( $name, $callback, $priority = 10, $args = 1 ) {
    global $hooks;
    $hooks[$name][$priority][] = $callback;
}
function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
    add_action( $name, $callback, $priority, $args );
}
function apply_filters( $name, $value ) {
    global $hooks;
    $priorities = $hooks[$name] ?? [];
    ksort( $priorities );
    foreach ( $priorities as $callbacks ) {
        foreach ( $callbacks as $callback ) { $value = $callback( $value ); }
    }
    return $value;
}
function do_action( $name ) {
    global $hooks;
    $priorities = $hooks[$name] ?? [];
    ksort( $priorities );
    foreach ( $priorities as $callbacks ) {
        foreach ( $callbacks as $callback ) { $callback(); }
    }
}
function get_option( $name, $default = false ) {
    global $options;
    return $options[$name] ?? $default;
}
function wc_string_to_bool( $value ) { return in_array( $value, [ 'yes', 'true', '1', 1, true ], true ); }
function plugin_dir_url( $file ) { return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function load_plugin_textdomain( ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function wc_get_log_file_path( $slug ) { return '/tmp/' . $slug . '.log'; }
function wc_get_order_statuses() { return [ 'wc-processing' => 'Processing', 'wc-completed' => 'Completed' ]; }
function check( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$providers = $provider === 'both' ? [ 'ezpay', 'ecpay' ] : [ $provider ];
foreach ( $providers as $name ) {
    require dirname( __DIR__ ) . '/modules/' . $name . '-invoice/module.php';
}
do_action( 'plugins_loaded' );
$pages = apply_filters( 'woocommerce_get_settings_pages', [] );
if ( in_array( $mode, [ 'conflict', 'no-woocommerce', 'official', 'both-active' ], true ) ) {
    check( count( $pages ) === 0, 'Conflicting/missing dependencies must skip settings.' );
    check( isset( $hooks['admin_notices'] ), 'A helpful notice is required.' );
    check( ! isset( $hooks['woocommerce_after_checkout_billing_form'] ), 'Checkout must not load.' );
} else {
    check( count( $pages ) === count( $providers ), 'Each plugin must register its own settings page.' );
    foreach ( $pages as $index => $page ) {
        $name = $providers[$index];
        check( $page->id === 'wu-' . $name . '-invoice', 'Settings IDs must be unique.' );
        $fields = $page->get_settings();
        $ids = array_column( $fields, 'id' );
        foreach ( [ 'enabled', 'merchant_id', 'hashkey', 'hashiv', 'issue_mode', 'invalid_mode', 'carrier_type' ] as $key ) {
            $id = $key === 'enabled' ? 'wc_woomp_enabled_' . $name . '_invoice' : 'wc_woomp_' . $name . '_invoice_' . $key;
            check( in_array( $id, $ids, true ), 'Missing setting: ' . $id );
        }
    }
    if ( $mode === 'disabled' ) {
        check( ! isset( $hooks['woocommerce_after_checkout_billing_form'] ), 'Disabled modules must skip checkout.' );
    } else {
        check( isset( $hooks['woocommerce_after_checkout_billing_form'] ), 'Checkout hooks missing.' );
        check( class_exists( 'ODS\\Metabox' ), 'Missing shared metabox dependency.' );
        foreach ( $providers as $name ) {
            $namespace = $name === 'ezpay' ? 'WUEZPAYINVOICE' : 'WUECPAYINVOICE';
            check( class_exists( $namespace . '\\Templates\\Checkout' ), 'Checkout class missing.' );
        }
        if ( in_array( 'ezpay', $providers, true ) ) {
            check( class_exists( 'InvoicePorter\\EzpayInvoice' ), 'InvoicePorter missing.' );
            $client = new GuzzleHttp\Client();
            $request = new GuzzleHttp\Psr7\Request( 'GET', 'https://example.test/' );
            check( $request->getMethod() === 'GET', 'HTTP dependencies did not load.' );
        }
        if ( in_array( 'ecpay', $providers, true ) ) {
            check( class_exists( 'WUECPAYINVOICE\\APIs\\ECPay_Woo_EcpayInvoice' ), 'ECPay SDK load order is broken.' );
        }
    }
}
echo "PASS: $mode / $provider\n";
if($mode==='enabled'){
 foreach($providers as $name){
  $action='wp_ajax_gen_invoice'.($name==='ezpay'?'_ezpay':'');
  check(isset($hooks[$action][1]),'Permission preflight registered before source mutation');
  $_POST=['orderId'=>0];try{$hooks[$action][1][0]();throw new LogicException('Invalid order accepted');}catch(InvoiceTestError $e){}
  $_POST=['orderId'=>123];$bad_nonce=true;try{$hooks[$action][1][0]();throw new LogicException('Invalid nonce accepted');}catch(InvoiceTestError $e){}$bad_nonce=false;
  $deny_capability=true;try{$hooks[$action][1][0]();throw new LogicException('Customer access accepted');}catch(InvoiceTestError $e){}$deny_capability=false;
  $native=wutm_invoice_settings_page($name);check($native===wutm_invoice_settings_page($name),'Shared settings object cached, no class redeclaration');
  do_action('admin_menu');$submenu=$submenus['wu-'.$name.'-invoice'];check($submenu[0]==='wu-toolbox-modular'&&$submenu[3]==='manage_woocommerce','Ordered Toolbox submenu retains WooCommerce capability');
  ob_start();$submenu[5]();$html=ob_get_clean();check(substr_count($html,'<form')===1&&strpos($html,'wutm-ui-root')!==false&&strpos($html,'wutm_save_'.$name.'_invoice')!==false,'One common settings form with dedicated action');
  foreach($native->get_settings() as $field)if(!empty($field['id']))check(strpos($html,'name="'.$field['id'].'"')!==false,'Every original setting field rendered');
  $save=$hooks['admin_post_wutm_save_'.$name.'_invoice'][10][0];
  $bad_nonce=true;try{$save();throw new LogicException('Invalid settings nonce accepted');}catch(InvoiceTestError $e){check(empty($saved_fields),'No settings mutation before nonce');}$bad_nonce=false;
  $deny_capability=true;try{$save();throw new LogicException('Settings permission bypass');}catch(InvoiceTestError $e){check(empty($saved_fields),'No settings mutation before permission');}$deny_capability=false;
  try{$save();throw new LogicException('Expected intercepted WC save');}catch(InvoiceTestError $e){check($e->getMessage()==='save-captured','Delegates saving to WooCommerce');}
  check($saved_fields===$native->get_settings(),'Complete native fields passed to save, no reduced/replaced option set');unset($saved_fields);
 }
}
