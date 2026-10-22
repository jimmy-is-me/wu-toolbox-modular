<?php
/** Source preservation and disabled/foreign-dependency guards; no merchant API. */
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');define('WUTM_PATH',dirname(__DIR__).'/');define('WUTM_URL','/fixture/');define('WUTM_VERSION','fixture');
$mode=$argv[1]??'no-woocommerce';$hooks=[];$options=[];
function add_action($hook,$fn,$priority=10,...$args){$GLOBALS['hooks'][$hook][$priority][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function is_multisite(){return $GLOBALS['mode']==='network';}
function get_site_option($key,$default=[]){return ['wu-payuni-shipping/wu-payuni-shipping.php'=>1];}
function ps_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
if($mode==='standalone')$options['active_plugins']=['wu-payuni-shipping/wu-payuni-shipping.php'];
if($mode==='foreign')define('PAYUNI_SHIPPING_PLUGIN_DIR','/foreign/');
require WUTM_PATH.'modules/payuni-shipping/module.php';$before=$options;
WUTM_PAYUNi_Shipping_Module::initialize();ps_check($before===$options,'Boot may not rewrite merchant/module options');
ps_check(!isset($hooks['woocommerce_shipping_methods']),'No duplicate/native methods when blocked');
ps_check(!isset($hooks['woocommerce_api_payuni_shipping_711_notify']),'No callback installed when blocked');
ps_check(isset($hooks['admin_notices']),'Explain unmet requirements');
$manifest=json_decode(file_get_contents(__DIR__.'/payuni-shipping-source-files.json'),true);ps_check(count($manifest)===36,'Every supplied file retained');
foreach($manifest as $name=>$entry){$file=WUTM_PATH.'modules/payuni-shipping/vendor/'.$name;$bytes=file_get_contents($file);if(pathinfo($file,PATHINFO_EXTENSION)!=='mo')$bytes=str_replace("\r\n","\n",$bytes);ps_check(hash('sha256',$bytes)===$entry['bundled_sha256'],'Source/adaptation checksum: '.$name);}
$registry=file_get_contents(WUTM_PATH.'core/module-registry.php');ps_check(strpos($registry,"'payuni-shipping' =>")>strpos($registry,"'payuni-payment' =>"),'Card follows payment');
echo "PAYUNi shipping source/guard checks passed: $mode.\n";
