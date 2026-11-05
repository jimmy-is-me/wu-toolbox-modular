<?php
ob_start();require __DIR__.'/v391-preview.php';ob_end_clean();
require dirname(__DIR__).'/modules/order-list-layout/module.php';
$GLOBALS['options']['wutm_order_list_columns']=['legacy'=>['cb'=>'選取','order_number'=>'訂單','order_date'=>'發佈日期','order_status'=>'狀態','order_total'=>'總計','tracking'=>'物流查詢編號']];
$_GET['view']='legacy';WUTM_Order_List_Layout::page();
