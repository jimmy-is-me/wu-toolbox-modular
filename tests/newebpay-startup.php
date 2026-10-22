<?php
namespace Automattic\WooCommerce\Utilities {
    final class FeaturesUtil {
        public static $state=[];
        public static function declare_compatibility($feature,$file,$supported=true){
            $key=$file.':'.$feature;
            if(isset(self::$state[$key])&&self::$state[$key]!==$supported)throw new \RuntimeException('conflicting compatibility declaration');
            self::$state[$key]=$supported;
        }
    }
}
namespace {
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');define('WUTM_PATH',dirname(__DIR__).'/');define('WUTM_FILE',WUTM_PATH.'wu-toolbox-modular.php');define('WUTM_URL','/plugin/');define('WUTM_VERSION','fixture');
$hooks=[];$registered=[];$phase='bootstrap';
function add_action($name,$fn,$priority=10,...$args){$GLOBALS['hooks'][$name][$priority][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function get_option($key,$default=false){return $default;}
function is_multisite(){return false;}
function is_admin(){return false;}
function _x($v,...$args){return $v;}function _n_noop(...$args){return $args;}
function register_post_status(...$args){}
function check($ok,$label){if(!$ok)throw new \RuntimeException($label);}
function wp_register_script(...$args){check($GLOBALS['phase']==='wp_enqueue_scripts','Script registered before allowed hook');$GLOBALS['registered'][$args[0]]=$args;}
function run_fixture_hook($name){$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);foreach($groups as $callbacks)foreach($callbacks as $fn)$fn();}
$features='Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
$features::declare_compatibility('custom_order_tables',WUTM_FILE,true);
try{$features::declare_compatibility('custom_order_tables',WUTM_FILE,false);throw new \LogicException('Expected reported conflict');}catch(\RuntimeException $error){}
$features::$state=[];
// Use the actual boot and compatibility callbacks from the other modules.
foreach(['payuni-payment','linepay-payment','newebpay-tools'] as $module)require WUTM_PATH.'modules/'.$module.'/module.php';
run_fixture_hook('before_woocommerce_init');
check($features::$state[WUTM_FILE.':custom_order_tables']===true,'Other modules retain their declared compatibility');
check($features::$state[WUTM_FILE.':cart_checkout_blocks']===true,'New component must not contradict LINE Pay');
define('WU_NP_WT_PLUGIN_URL','/vendor/');define('WU_NP_WT_VERSION','fixture');
require WUTM_PATH.'modules/newebpay-tools/vendor/woocommerce/shipping/wu-np-base.php';
check(!$registered,'No frontend registration while plugins bootstrap');
$phase='wp_enqueue_scripts';run_fixture_hook('wp_enqueue_scripts');
check(isset($registered['wu-np-shipping'])&&$registered['wu-np-shipping'][2]===['jquery'],'Frontend script registered at correct hook with original dependency');
echo "WU startup regression passed: original conflict reproduced, real multi-module declarations coexist and shipping registration is deferred.\n";
}
