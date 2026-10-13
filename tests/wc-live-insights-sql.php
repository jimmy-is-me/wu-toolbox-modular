<?php
// Runs only against the disposable CI database; never connects to a website database.
if (PHP_SAPI !== 'cli' || getenv('WUTM_TEST_MYSQL_DB') !== 'wci_fixture') {
    throw new RuntimeException('This test requires the disposable wci_fixture database.');
}
require __DIR__ . '/wc-live-insights.php';
class SQLInsightsDB extends FakeInsightsDB {
    public $pdo;
    function __construct() {
        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=wci_fixture;charset=utf8mb4', 'root', 'fixture-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->prefix='wci_'.bin2hex(random_bytes(5)).'_';
        foreach(['posts','postmeta','terms','term_relationships','term_taxonomy'] as $name)$this->$name=$this->prefix.$name;
        $this->version=$this->pdo->query('SELECT VERSION()')->fetchColumn();
    }
    function get_results($sql,$format=null) {
        $this->queries[]=$sql;$this->last_error='';
        try{return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
        catch(PDOException $e){$this->last_error=$e->getMessage();return [];}
    }
    function get_var($sql) { $rows=$this->get_results($sql);return $rows?reset($rows[0]):null; }
    function insert($table,$row) {
        $keys=array_keys($row);
        $q=$this->pdo->prepare('INSERT INTO '.$this->prefix.$table.' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
        $q->execute(array_values($row));
    }
}
$wpdb=new SQLInsightsDB;
$schema=[
 'posts'=>'ID bigint primary key,post_type varchar(32),post_status varchar(32),post_parent bigint,post_date datetime,post_date_gmt datetime,post_title text,KEY type_date(post_type,post_date)',
 'postmeta'=>'meta_id bigint auto_increment primary key,post_id bigint,meta_key varchar(100),meta_value text,KEY post_key(post_id,meta_key)',
 'wc_orders'=>'id bigint primary key,parent_order_id bigint,type varchar(32),status varchar(32),currency varchar(8),total_amount decimal(26,8),tax_amount decimal(26,8),customer_id bigint,billing_email varchar(255),date_created_gmt datetime,KEY type_date(type,date_created_gmt)',
 'wc_order_operational_data'=>'order_id bigint primary key,shipping_tax_amount decimal(26,8),shipping_total_amount decimal(26,8),discount_total_amount decimal(26,8)',
 'wc_order_addresses'=>'order_id bigint,address_type varchar(32),first_name varchar(255),last_name varchar(255),UNIQUE KEY address(order_id,address_type)',
 'woocommerce_order_items'=>'order_item_id bigint primary key,order_id bigint,order_item_name text,order_item_type varchar(32),KEY order_type(order_id,order_item_type)',
 'woocommerce_order_itemmeta'=>'meta_id bigint auto_increment primary key,order_item_id bigint,meta_key varchar(100),meta_value text,KEY item_key(order_item_id,meta_key)',
 'term_relationships'=>'object_id bigint,term_taxonomy_id bigint,PRIMARY KEY(object_id,term_taxonomy_id)',
 'term_taxonomy'=>'term_taxonomy_id bigint primary key,term_id bigint,taxonomy varchar(32)',
 'terms'=>'term_id bigint primary key,name varchar(255),slug varchar(255)',
];
foreach($schema as $table=>$columns)$wpdb->pdo->exec('CREATE TABLE '.$wpdb->prefix.$table.' ('.$columns.')');
$local=wp_date('Y-m-d').' 12:00:00';
$utc=(new DateTimeImmutable($local,wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$fixtures=[
 [101,0,'shop_order','wc-processing','TWD',1000,80,20,60,50,3,'buyer@example.test'],
 [102,0,'shop_order','wc-completed','TWD',500,20,0,0,0,0,'guest@example.test'],
 [103,0,'shop_order','wc-cancelled','TWD',900,0,0,0,0,0,'cancel@example.test'],
 [104,0,'shop_order','wc-completed','USD',500,0,0,0,0,0,'usd@example.test'],
 [201,101,'shop_order_refund','wc-completed','TWD',-200,-15,-5,-10,0,0,''],
];
foreach($fixtures as [$id,$parent,$type,$status,$cur,$total,$tax,$shiptax,$ship,$discount,$uid,$email]){
 $wpdb->insert('posts',['ID'=>$id,'post_parent'=>$parent,'post_type'=>$type,'post_status'=>$status,'post_date'=>$local,'post_date_gmt'=>$utc,'post_title'=>'Order']);
 $wpdb->insert('wc_orders',['id'=>$id,'parent_order_id'=>$parent,'type'=>$type,'status'=>$status,'currency'=>$cur,'total_amount'=>$total,'tax_amount'=>$tax,'customer_id'=>$uid,'billing_email'=>$email,'date_created_gmt'=>$utc]);
 $wpdb->insert('wc_order_operational_data',['order_id'=>$id,'shipping_tax_amount'=>$shiptax,'shipping_total_amount'=>$ship,'discount_total_amount'=>$discount]);
 $wpdb->insert('wc_order_addresses',['order_id'=>$id,'address_type'=>'billing','first_name'=>'Buyer','last_name'=>'Fixture']);
 $fields=['_order_total'=>$total,'_order_tax'=>$tax,'_order_shipping_tax'=>$shiptax,'_order_shipping'=>$ship,'_cart_discount'=>$discount,'_order_currency'=>$cur,'_customer_user'=>$uid,'_billing_email'=>$email,'_billing_first_name'=>'Buyer','_billing_last_name'=>'Fixture'];
 if($parent)$fields['_refund_amount']=abs($total);
 foreach($fields as $key=>$value)$wpdb->insert('postmeta',['post_id'=>$id,'meta_key'=>$key,'meta_value'=>(string)$value]);
}
$wpdb->insert('posts',['ID'=>11,'post_parent'=>0,'post_type'=>'product','post_status'=>'publish','post_date'=>$local,'post_date_gmt'=>$utc,'post_title'=>'SQL fixture']);
foreach(['_jmi_unit_cost'=>'50','_sku'=>'SQL-FIXTURE','_stock'=>'2','_stock_status'=>'instock','_manage_stock'=>'yes','_low_stock_amount'=>'2'] as $key=>$value)$wpdb->insert('postmeta',['post_id'=>11,'meta_key'=>$key,'meta_value'=>$value]);
foreach([2,5] as $id){
 $wpdb->insert('terms',['term_id'=>$id,'name'=>'Category '.$id]);
 $wpdb->insert('term_taxonomy',['term_taxonomy_id'=>$id,'term_id'=>$id,'taxonomy'=>'product_cat']);
 $wpdb->insert('term_relationships',['object_id'=>11,'term_taxonomy_id'=>$id]);
}
foreach([[1001,101,2,800,50,100,0],[1002,102,1,400,null,null,0],[2001,201,-1,-100,50,100,1001]] as [$itemid,$oid,$qty,$revenue,$cost,$cogs,$ref]){
 $wpdb->insert('woocommerce_order_items',['order_item_id'=>$itemid,'order_id'=>$oid,'order_item_name'=>'SQL fixture','order_item_type'=>'line_item']);
 $fields=['_product_id'=>11,'_variation_id'=>0,'_qty'=>$qty,'_line_total'=>$revenue];
 if($cost!==null)$fields['_jmi_unit_cost_snapshot']=$cost;
 if($cogs!==null)$fields['_cogs_value']=$cogs;
 if($ref)$fields['_refunded_item_id']=$ref;
 foreach($fields as $key=>$value)$wpdb->insert('woocommerce_order_itemmeta',['order_item_id'=>$itemid,'meta_key'=>$key,'meta_value'=>(string)$value]);
}
$currency='TWD';$user=17;$_GET=['from'=>wp_date('Y-m-d'),'to'=>wp_date('Y-m-d')];$options=[];
foreach([false,true] as $storage){
 $hpos=$storage;$transients=[];$s=call_insights('state');
 $summary=call_insights('summary',$s);
 foreach(['orders'=>2,'gross'=>1500,'refunds'=>200,'collected'=>1300,'net'=>1150,'tax'=>100,'shipping'=>50,'customers'=>2,'member_revenue'=>800] as $key=>$expected)check(abs((float)$summary[$key]-$expected)<0.0001,'Real SQL summary '.$key);
 $sales=call_insights('rows',call_insights('sales_sql',$s));
 foreach(['qty'=>2,'revenue'=>1100,'cogs'=>100,'estimated_cost'=>1,'missing_cost'=>0] as $key=>$expected)check(abs((float)$sales[0][$key]-$expected)<0.0001,'Real SQL item/refund '.$key);
 foreach(['overview','products','customers','stock','abc','finance'] as $tab){
  $s['tab']=$tab;$before=$orderReads;$result=call_insights('job_start',$s);
  for($n=0;!empty($result['pending'])&&$n<8;$n++)$result=call_insights('job_step',$result['token'],$result['seq']);
  check(empty($result['pending'])&&isset($result['html'],$result['csv']),'Real SQL complete '.$tab);
  check($orderReads===$before,'No WC order-object reads '.$tab);
  $cached=call_insights('job_load',call_insights('ready_key',$s));
  if($tab==='overview'){
   check($cached['current']['orders']===2&&$cached['current']['net']===1150.0,'Bulk overview totals');
   check($cached['categories'][2]===1100.0&&count($cached['categories'])===1,'Category deduplication');
   check(array_sum($cached['statuses'])===3,'Statuses include cancelled but exclude foreign currency');
  }
  $count=count($wpdb->queries);check(call_insights('cached_report',$s)['cached']===true,'Warm report '.$tab);
  check(count($wpdb->queries)===$count,'Warm report zero SQL '.$tab);
 }
}
echo "Real MySQL passed: HPOS and legacy, all six reports, tax/shipping/refunds, costs, currency/status exclusions, zero order-object aggregation, zero-SQL warm views.\n";
