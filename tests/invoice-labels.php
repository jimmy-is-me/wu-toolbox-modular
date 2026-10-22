<?php
/** Offline display regression: real source schemas/checkout, no network or writes. */
define('ABSPATH', __DIR__.'/');
$hooks=[]; $options=['wc_woomp_ezpay_invoice_carrier_type'=>['雲端電子發票載具','Mobile Code','Citizen Digital Certificate']];
function add_action($name,$fn,$priority=10,...$args){$GLOBALS['hooks'][$name][$priority][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function apply_filters($name,$value,...$args){$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);foreach($groups as $callbacks)foreach($callbacks as $fn)$value=$fn($value,...$args);return $value;}
function __($text,$domain='default'){return apply_filters('gettext_'.$domain,$text,$text,$domain);}
function get_option($key,$default=false){return apply_filters('option_'.$key,$GLOBALS['options'][$key]??$default);}
function update_option(...$args){throw new RuntimeException('Rendering must not write existing options');}
function wc_get_log_file_path($id){return '/fixture/'.$id;}
function wc_get_order_statuses(){return ['wc-processing'=>'處理中','wc-completed'=>'已完成'];}
class WC_Settings_Page{public $id,$label;public function __construct(){}}
function WC(){return (object)['cart'=>(object)['total'=>'100']];}
function woocommerce_form_field($id,$args){$GLOBALS['fields'][$id]=$args;}
function wc_add_notice($message,$type){$GLOBALS['errors'][]=$message;}
function expect_label($ok,$message){if(!$ok)throw new RuntimeException($message);}
require dirname(__DIR__).'/core/invoice-labels.php';
$before=$options;
$page=require dirname(__DIR__).'/modules/ezpay-invoice/settings.php';
$schema=array_column($page->get_settings(),null,'id');
foreach(['issue_mode'=>'開立模式','invalid_mode'=>'作廢模式','carrier_type'=>'載具類型','donate_org'=>'捐贈機構'] as $id=>$label)expect_label($schema['wc_woomp_ezpay_invoice_'.$id]['name']===$label,'Chinese setting: '.$id);
expect_label(array_keys($schema['wc_woomp_ezpay_invoice_issue_mode']['options'])===['manual','auto'],'Wire mode values unchanged');
expect_label(get_option('wc_woomp_ezpay_invoice_carrier_type')===['雲端電子發票載具','手機條碼','自然人憑證'],'Legacy option aliases normalized');
expect_label(__('Mobile Code','unrelated')==='Mobile Code'&&__('Issue Mode')==='Issue Mode','Other/default domains untouched');
expect_label(__('Citizen Digital Certificate','paynow-einvoice')==='自然人憑證','Certificate translated');
require dirname(__DIR__).'/modules/ezpay-invoice/src/templates/Checkout.php';
$checkout=new WUEZPAYINVOICE\Templates\Checkout();$checkout->set_invoice_field();
expect_label($fields['ezpay-individual-invoice']['options']===['雲端電子發票載具'=>'雲端電子發票載具','手機條碼'=>'手機條碼','自然人憑證'=>'自然人憑證'],'Actual checkout dropdown Chinese');
expect_label($fields['ezpay-invoice-type']['options']===['individual'=>'個人','company'=>'公司','donate'=>'捐贈'],'Type values retained, labels Chinese');
foreach(['Mobile Code'=>'/ABC1234','手機條碼'=>'/ABC1234','Citizen Digital Certificate'=>'AB12345678901234','自然人憑證'=>'AB12345678901234'] as $carrier=>$number){
    $_POST=['ezpay-invoice-type'=>'individual','ezpay-individual-invoice'=>$carrier,'ezpay-carrier-number'=>'invalid'];$errors=[];$checkout->set_invoice_field_validate();expect_label(count($errors)===1,'Legacy and Chinese carriers validated');
    $_POST['ezpay-carrier-number']=$number;$errors=[];$checkout->set_invoice_field_validate();expect_label(!$errors,'Valid carrier accepted');
}
expect_label($options===$before,'Display/read did not rewrite saved options');
echo "Invoice Traditional Chinese labels passed: settings, actual checkout, legacy aliases, validation, unchanged mode values and no writes.\n";
