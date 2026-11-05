<?php
ob_start();require __DIR__.'/admin-commerce-ui.php';ob_end_clean();
if(($argv[1]??'')==='js'){$m=new ReflectionMethod(WU_WooCommerce_Optimizer::class,'get_taiwan_address_js');if(PHP_VERSION_ID<80100)$m->setAccessible(true);echo $m->invoke($optimizer);exit;}
$screen=['id'=>'edit-product','post_type'=>'product','base'=>'edit'];
foreach(['weight','dimensions','shipping_class','featured'] as $key)$options['wu_woo_hide_quick_'.$key]=1;
foreach(['reviews','import','export'] as $key)$options['wu_woo_hide_product_'.$key]=1;
echo $optimizer->visibility_css();
