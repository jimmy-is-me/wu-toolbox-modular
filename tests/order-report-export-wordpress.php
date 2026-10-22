<?php
/** Native WooCommerce data/CSV/JSON/profile preservation, disposable DB only. */
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable CI only');
error_reporting(E_ALL & ~E_DEPRECATED);
if(getenv('WUTM_REPORT_EXPECT_HPOS')==='1'&&!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled())throw new RuntimeException('HPOS fixture must actually use HPOS');
function report_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function report_request($action,$post,$user,$bad=false){
    $old=getenv('WUTM_REPORT_REQUEST');putenv('WUTM_REPORT_REQUEST='.base64_encode(wp_json_encode(['action'=>$action,'post'=>$post,'user'=>$user,'bad_nonce'=>$bad])));
    $command=[PHP_BINARY,getenv('RUNNER_TEMP').'/wutm-wp-cli.phar','eval-file',__DIR__.'/order-report-export-request.php','--path='.ABSPATH,'--user=fixture'];
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start isolated report request');
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if($old===false)putenv('WUTM_REPORT_REQUEST');else putenv('WUTM_REPORT_REQUEST='.$old);
    report_assert(strpos($errors,'Fatal error')===false&&strpos($errors,'Uncaught')===false,'Native request must not crash: '.$errors);
    return [$output,$status,$errors];
}
function report_csv($bytes){
    report_assert(substr($bytes,0,3)==="\xEF\xBB\xBF",'Original UTF-8 BOM CSV download');
    $stream=fopen('php://temp','w+');fwrite($stream,substr($bytes,3));rewind($stream);$rows=[];while(($row=fgetcsv($stream,0,',','"',''))!==false)$rows[]=$row;fclose($stream);return $rows;
}
add_filter('pre_http_request',static function(){throw new RuntimeException('Report fixture cannot send external requests');},1);
add_filter('pre_wp_mail',static function(){return true;});
$admin=get_current_user_id();$other=wp_insert_user(['user_login'=>'report_fixture_manager','user_pass'=>'fixture-only','role'=>'shop_manager']);report_assert(!is_wp_error($other),'Second per-user profile');
$orders=[];$product=new WC_Product_Simple();$product->set_name('中文商品');$product->set_sku('0012345678901234');$product->set_regular_price('100');$product->save();
$originalProfile=get_user_meta($admin,'_wctre_export_v3_settings',true);
try{
    for($i=0;$i<105;$i++){
        $order=wc_create_order();$order->set_date_created('2050-01-15 12:00:00');$order->set_status($i<2?'completed':'processing');$order->set_billing_first_name('小明');$order->set_billing_last_name('王');$order->set_billing_phone('0912345678');$order->set_currency('TWD');$order->set_payment_method('bacs');$order->set_payment_method_title('銀行轉帳');$order->set_customer_note('=HYPERLINK("https://invalid.example")');
        $order->set_shipping_country('TW');$order->set_shipping_address_1("中文地址，第一行\n第二行");
        if($i!==1){$item=new WC_Order_Item_Product();$item->set_product($product);$item->set_name('中文商品，甲');$item->set_quantity(2);$item->set_total('200');$item->add_meta_data('客製資料','紅色');$order->add_item($item);if($i===0){$item2=new WC_Order_Item_Product();$item2->set_product($product);$item2->set_name('商品乙');$item2->set_quantity(1);$item2->set_total('100');$item2->add_meta_data('客製資料','藍色');$order->add_item($item2);}}
        $shipping=new WC_Order_Item_Shipping();$shipping->set_method_id('flat_rate');$shipping->set_method_title('測試配送');$shipping->set_total('20');$shipping->add_meta_data('StoreID','001234');$shipping->add_meta_data('Items',['hidden'=>'fixture']);$order->add_item($shipping);
        $order->update_meta_data('_billing_tax_id','01234567');$order->update_meta_data('_invoice_bundle',['載具'=>'/ABC1234']);$order->calculate_totals();$order->save();$orders[]=$order->get_id();
    }
    $first=wc_get_order($orders[0]);$refund=wc_create_refund(['order_id'=>$first->get_id(),'amount'=>25,'reason'=>'fixture','refund_payment'=>false,'restock_items'=>false]);report_assert(!is_wp_error($refund),'Refund fixture');$first=wc_get_order($orders[0]);
    $post=['date_from'=>'2050-01-01','date_to'=>'2050-01-31','statuses'=>['wc-processing','wc-completed'],'mode'=>'order','repeat_order'=>'repeat','text_guard'=>'1','field_order'=>['order_number','billing_phone','item_name','item_qty','order_total','refund_total','net_total','customer_note','shipping_address','custom_tax','custom_item','custom_shipping','custom_json'],'custom_scope'=>['custom_tax'=>'order','custom_item'=>'item','custom_shipping'=>'shipping','custom_json'=>'order'],'custom_meta'=>['custom_tax'=>'_billing_tax_id','custom_item'=>'客製資料','custom_shipping'=>'StoreID','custom_json'=>'_invoice_bundle']];
    foreach($post['field_order'] as $key){$post['field_enabled'][$key]='1';$post['field_label'][$key]=$key;}
    [$raw,$exit]=report_request('preview',$post,$admin);$preview=json_decode($raw,true);report_assert($exit===0&&$preview['success']===true&&count($preview['data']['rows'])===30&&$preview['data']['limited']===true,'Native preview is limited to 30 real rows');
    wp_cache_delete($admin,'user_meta');report_assert(get_user_meta($admin,'_wctre_export_v3_settings',true)===$originalProfile,'Preview does not save settings');
    [$scan]=report_request('scan',$post,$admin);$scanned=json_decode($scan,true);report_assert($scanned['success']===true&&$scanned['data']['scanned']===100,'Original scan limit is 100 matching orders');
    $keys=array_map(static function($field){return $field['scope'].'|'.$field['key'];},$scanned['data']['fields']);report_assert(in_array('order|_billing_tax_id',$keys,true)&&in_array('item|客製資料',$keys,true)&&in_array('shipping|StoreID',$keys,true)&&!in_array('shipping|Items',$keys,true),'All three custom meta scopes and hidden-meta rules');
    $post['operation']='export';[$csv,$code]=report_request('submit',$post,$admin);report_assert($code===0,'CSV request completes');$rows=report_csv($csv);report_assert(count($rows)===106,'Export crosses 100-order page boundary without losing rows');
    report_assert(array_slice($rows,1,30)===$preview['data']['rows'],'Preview cell values equal actual CSV including protection/newlines');
    $byId=[];foreach(array_slice($rows,1) as $row)$byId[$row[0]]=$row;$row=$byId[(string)$first->get_order_number()];report_assert($row[1]==="'0912345678"&&$row[3]==='3','Phone protection and aggregated quantity');report_assert((float)$row[5]===25.0&&(float)$row[6]===(float)$first->get_total()-25,'Original refund/net totals');report_assert(strpos($row[7],"'=HYPERLINK")===0&&strpos($row[8],"\n")!==false,'Formula defense and actual CSV quoted multiline cells');report_assert(strpos($row[10],'紅色')!==false&&strpos($row[10],'藍色')!==false&&strpos($row[12],'載具')!==false,'Item aggregation and JSON export preserved');
    $post['mode']='item';$post['repeat_order']='first';$post['statuses']=['wc-completed'];[$csv]=report_request('submit',$post,$admin);$items=report_csv($csv);report_assert(count($items)===4,'Two item rows plus source no-items placeholder');
    $secondRows=array_values(array_filter(array_slice($items,1),static function($r){return $r[2]==='商品乙';}));report_assert(count($secondRows)===1&&$secondRows[0][0]===''&&$secondRows[0][1]===''&&$secondRows[0][4]==='','First-row-only option hides repeated order details and money');
    report_assert(strpos($csv,'（無商品項目）')!==false&&strpos($csv,'商品乙')!==false,'Empty orders and split product rows retained');
    $moneyValues=array_filter(array_column(array_slice($items,1),4),static function($v){return $v!=='';});report_assert(count($moneyValues)===2,'Order money emitted once per order, never duplicated per product');
    [$denied]=report_request('preview',$post,0);report_assert((json_decode($denied,true)['success']??null)===false,'Anonymous cannot preview personal data');
    [$denied]=report_request('preview',$post,$admin,true);report_assert((json_decode($denied,true)['success']??null)===false,'Bad preview nonce rejected');
    [$denied]=report_request('submit',$post,0);report_assert(substr($denied,0,3)!=="\xEF\xBB\xBF",'Anonymous cannot download CSV');
    $post['date_from']='2050-02-30';[$invalid]=report_request('preview',$post,$admin);report_assert((json_decode($invalid,true)['success']??null)===false,'Invalid calendar date rejected by real endpoint');$post['date_from']='2050-01-01';
    $post['operation']='save';report_request('submit',$post,$admin);wp_cache_delete($admin,'user_meta');$saved=get_user_meta($admin,'_wctre_export_v3_settings',true);report_assert($saved['mode']==='item'&&array_keys($saved['fields'])===$post['field_order'],'Original per-user field order/profile saved');
    $post['operation']='count';report_request('submit',$post,$admin);$notice=get_transient('wctre_v3_notice_'.$admin);report_assert(is_array($notice)&&strpos($notice['text'],'2 筆')!==false,'Count action reports orders rather than split item rows');$post['operation']='save';
    $post['mode']='order';report_request('submit',$post,$other);wp_cache_delete($other,'user_meta');wp_cache_delete($admin,'user_meta');report_assert(get_user_meta($other,'_wctre_export_v3_settings',true)['mode']==='order'&&get_user_meta($admin,'_wctre_export_v3_settings',true)['mode']==='item','Shop manager and admin have isolated profiles');
    report_request('submit',['operation'=>'reset'],$other);wp_cache_delete($other,'user_meta');report_assert(get_user_meta($other,'_wctre_export_v3_settings',true)==='','Reset only clears current user settings');
    echo "Native order report passed: original CSV/BOM and 100+ pagination, matching 30-row preview, custom meta scopes, refunds, split/empty orders, guards and per-user settings; no external requests.\n";
}finally{
    foreach($orders as $id){$order=wc_get_order($id);if($order){foreach($order->get_refunds() as $refund)$refund->delete(true);$order->delete(true);}}$product->delete(true);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($other);if($originalProfile==='')delete_user_meta($admin,'_wctre_export_v3_settings');else update_user_meta($admin,'_wctre_export_v3_settings',$originalProfile);
}
