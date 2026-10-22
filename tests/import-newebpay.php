<?php
/** CLI-only mechanical source import, pinned provenance recorded in NOTICE.md. */
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit(1);
$ref = rtrim($argv[1], '/\\');
$source = $ref . '/includes/ry-woocommerce-tools/';
$dest = dirname(__DIR__) . '/modules/newebpay-tools/vendor/';
$files = ['LICENSE.txt','woocommerce/abstracts/abstract-newebpay.php','woocommerce/shipping/ry-base.php','woocommerce/admin/meta-boxes/newebpay-shipping-meta-box.php','style/js/ry_shipping.js','style/js/admin/ry_shipping.js','style/admin/ry_shipping.css','icon/newebpay_logo.png'];
foreach (['woocommerce/gateways/newebpay','woocommerce/shipping/newebpay','templates/order','languages'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $relative = str_replace('\\','/',substr($file->getPathname(),strlen($source)));
        if ($directory === 'templates/order' && strpos(basename($relative),'order-newebpay-') !== 0) continue;
        if ($directory === 'languages' && basename($relative) !== 'ry-woocommerce-tools-zh_TW.mo') continue;
        $files[] = $relative;
    }
}
$map = ['Woomp_HPOS_Helper'=>'WU_NP_HPOS_Helper','WooMP_Checkout'=>'WU_NP_Checkout','WOOMP_VERSION'=>'WUTM_VERSION','Woomp'=>'WU','woomp'=>'wu','RYNewebPay'=>'WUNewebPay','RYECPay'=>'WUNPShipping','ecpayShippingInfo'=>'wuNPShippingInfo','ecpay_shipping_info'=>'wu_np_shipping_info','newebpay_shipping_info'=>'wu_np_newebpay_shipping_info','RY_WT'=>'WU_NP_WT','RY_'=>'WU_NP_','WMP_'=>'WU_NP_','wmp_'=>'wu_np_','ry_'=>'wu_np_','rytools'=>'wu-newebpay','ry-woocommerce-tools'=>'wu-newebpay-tools','ry-'=>'wu-np-','RY WooCommerce Tools'=>'WU NewebPay Tools','valid_newebpay_gateway_request'=>'wu_np_valid_newebpay_gateway_request','send_at_cvs_email'=>'wu_np_send_at_cvs_email'];
$manifest=[];
foreach ($files as $file) {
    $target = strtr($file,$map); $data = file_get_contents($source.$file);
    if (in_array(pathinfo($file,PATHINFO_EXTENSION),['php','js','css'],true)) $data = strtr($data,$map);
    if (!is_dir(dirname($dest.$target))) mkdir(dirname($dest.$target),0777,true);
    file_put_contents($dest.$target,$data);
    $manifest[$target]=['source'=>$file,'sha256'=>hash('sha256',str_replace("\r\n","\n",$data))];
}
$data=strtr(file_get_contents($ref.'/includes/class-woomp-hpos-helper.php'),$map);
file_put_contents($dest.'class-wu-hpos-helper.php',$data);
$manifest['class-wu-hpos-helper.php']=['source'=>'../class-woomp-hpos-helper.php','sha256'=>hash('sha256',str_replace("\r\n","\n",$data))];
ksort($manifest);
file_put_contents(__DIR__.'/newebpay-source-files.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Imported NewebPay source files: '.count($manifest)."\n";
