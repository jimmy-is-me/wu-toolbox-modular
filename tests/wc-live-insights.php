<?php
namespace Automattic\WooCommerce\Utilities {
    class OrderUtil { public static function custom_orders_table_usage_is_enabled(){return $GLOBALS['hpos'];} }
}
namespace {
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');define('DB_NAME','fixture');define('ARRAY_A','ARRAY_A');define('WUTM_VERSION','test');
$hooks=[];$menus=[];$options=[];$transients=[];$caps=true;$nonce=true;$hpos=false;$currency='TWD';$user=17;$meta=[];$errors=[];$orders=[];$orderReads=0;
function check($ok,$label){if(!$ok)throw new \RuntimeException($label);}
function add_action($key,$fn,...$args){$GLOBALS['hooks'][$key][]=$fn;}
function apply_filters($key,$value){return $value;}
function current_user_can(...$args){return $GLOBALS['caps'];}
function get_current_user_id(){return $GLOBALS['user'];}
function wp_timezone(){return new \DateTimeZone('Asia/Taipei');}
function wp_timezone_string(){return 'Asia/Taipei';}
function wp_date($format){return (new \DateTimeImmutable('now',wp_timezone()))->format($format);}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_email($s){return filter_var($s,FILTER_SANITIZE_EMAIL);}
function wp_unslash($s){return $s;}
function absint($s){return abs((int)$s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function wp_kses_post($s){return $s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function is_wp_error($s){return false;}
function selected($a,$b,$echo=true){$s=$a===$b?'selected':'';if($echo)echo $s;return $s;}
function checked($a,$b,$echo=true){$s=$a===$b?'checked':'';if($echo)echo $s;return $s;}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;$GLOBALS['ttls'][$key]=$ttl;return true;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function wp_generate_uuid4(){static $n=0;return sprintf('11111111-2222-3333-4444-%012d',++$n);}
function get_post_meta($id,$key,...$args){return $GLOBALS['meta'][$id][$key]??'';}
function wp_get_post_terms($id,$tax,$args){return [5,2];}
function get_term($id,...$args){return (object)['name'=>'Category '.$id];}
function get_edit_post_link($id,...$args){return admin_url('post.php?post='.$id);}
function get_post_status_object($s){return (object)['label'=>$s];}
function number_format_i18n($n,$d=0){return number_format($n,$d);}
function wc_price($n,$args=[]){return '<span class="woocommerce-Price-amount">'.number_format($n,2).' '.($args['currency']??'TWD').'</span>';}
function get_woocommerce_currency(){return $GLOBALS['currency'];}
function wc_get_is_paid_statuses(){return ['processing','completed'];}
function wc_get_order_statuses(){return ['wc-processing'=>'處理中','wc-completed'=>'完成','wc-refunded'=>'退款','wc-cancelled'=>'取消','wc-pending'=>'待付款'];}
function wc_get_order_status_name($s){return wc_get_order_statuses()[$s]??$s;}
function wc_get_order($id){$GLOBALS['orderReads']++;return $GLOBALS['orders'][$id]??null;}
function wc_get_orders($args){$GLOBALS['orderQueries'][]=$args;return (object)['orders'=>array_slice(array_values($GLOBALS['orders']),0,15),'total'=>count($GLOBALS['orders']),'max_num_pages'=>2];}
function wp_create_nonce(...$args){return 'fixture-nonce';}
function wp_nonce_field(...$args){echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">';}
function check_admin_referer(...$args){if(!$GLOBALS['nonce'])throw new \RuntimeException('nonce');}
function check_ajax_referer(...$args){check_admin_referer();}
function wp_die($message){throw new \RuntimeException($message);}
function wp_safe_redirect($url){throw new \RuntimeException('redirect');}
function wp_send_json_error($data,$code){throw new \RuntimeException('json-error-'.$code);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function woocommerce_wp_text_input($args){$GLOBALS['costFields'][]=$args;}
function add_menu_page(...$args){$GLOBALS['menus'][]=$args;return 'toplevel_page_'.$args[3];}
function add_submenu_page(...$args){$GLOBALS['menus'][]=$args;return 'wu-toolbox_page_'.$args[4];}
class WooCommerce{}
class WC_Admin_Meta_Boxes{public static function add_error($s){$GLOBALS['errors'][]=$s;}}
class WC_Product{
 public $id,$parent,$meta=[];function __construct($id=11,$parent=0){$this->id=$id;$this->parent=$parent;$this->meta=$GLOBALS['meta'][$id]??[];}
 function get_id(){return $this->id;}function get_parent_id(){return $this->parent;}function get_meta($key,...$args){return $this->meta[$key]??'';}
 function update_meta_data($key,$value){$this->meta[$key]=$value;}function delete_meta_data($key){unset($this->meta[$key]);}
}
class WC_Date{function date_i18n($format){return (new \DateTimeImmutable('today',wp_timezone()))->format($format);}}
class WC_Order_Item_Product{
 public $meta=[],$saved=0,$qty=2,$total=800,$product_id=11,$variation_id=0,$name='商品 <安全測試>',$product;
 function __construct(){$this->product=new WC_Product;}
 function get_meta($key,...$args){return $this->meta[$key]??'';}function meta_exists($key){return array_key_exists($key,$this->meta);}
 function add_meta_data($key,$value,...$args){$this->meta[$key]=$value;}function update_meta_data($key,$value){$this->meta[$key]=$value;}function save_meta_data(){$this->saved++;}
 function get_product(){return $this->product;}function get_product_id(){return $this->product_id;}function get_variation_id(){return $this->variation_id;}
 function get_quantity(){return $this->qty;}function get_total(){return $this->total;}function get_name(){return $this->name;}function get_order(){return new WC_Order;}
}
class WC_Order{
 public $id=1,$status='processing',$items=[],$refunds=[];
 function get_id(){return $this->id;}function get_status(){return $this->status;}function get_total(){return 1000;}
 function get_total_refunded(){return 200;}function get_total_tax(){return 100;}function get_shipping_total(){return 60;}
 function get_total_tax_refunded(){return 20;}function get_total_shipping_refunded(){return 10;}function get_discount_total(){return 50;}
 function get_refunds(){return $this->refunds;}function get_customer_id(){return 3;}function get_billing_email(){return 'buyer@example.test';}
 function get_billing_first_name(){return 'Buyer';}function get_billing_last_name(){return 'Fixture';}function get_date_created(){return new WC_Date;}
 function get_items(...$args){return $this->items;}function get_currency(){return 'TWD';}function get_order_number(){return $this->id;}
 function get_formatted_billing_full_name(){return 'Buyer';}function get_payment_method_title(){return 'ATM';}function get_edit_order_url(){return admin_url('admin.php?page=wc-orders&id='.$this->id);}
}
class WC_Order_Refund extends WC_Order{}
class FakeInsightsDB{
 public $prefix='wp_',$posts='wp_posts',$postmeta='wp_postmeta',$terms='wp_terms',$term_relationships='wp_term_relationships',$term_taxonomy='wp_term_taxonomy',$last_error='',$queries=[],$batches=[],$locked=true,$quiet=false,$version='8.0.36';
 function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",$v)."'";},$sql);}
 function esc_like($s){return addcslashes($s,'_%\\');}function db_server_info(){return $this->version;}
 function suppress_errors($value){$old=$this->quiet;$this->quiet=$value;return $old;}
 function get_var($sql){$this->queries[]=$sql;return strpos($sql,'GET_LOCK')!==false?($this->locked?'1':'0'):'1';}
 function get_results($sql,$format){$this->queries[]=$sql;
  if(strpos($sql,' AS id ')!==false)return array_map(static function($id){return ['id'=>$id];},array_shift($this->batches)??[]);
  if(strpos($sql,'configured_cost')!==false)return [['product_id'=>11,'name'=>'商品 <安全測試>','post_status'=>'publish','sku'=>'FIXTURE','configured_cost'=>'50']];
  if(strpos($sql,' AS total')!==false)return [['total'=>1,'outofstock'=>0,'low'=>0,'onbackorder'=>0,'unmanaged'=>0]];
  return [];
 }
}
$wpdb=new FakeInsightsDB;
if(($argv[1]??'')==='legacy'){
 eval('class JMI_WC_Admin_Insights {const CAP="manage_options";const SLUG="jmi-wc-insights";}');
 require dirname(__DIR__).'/modules/wc-live-insights/module.php';
 check(!isset($hooks['wp_ajax_jmi_wci_live'])&&isset($hooks['admin_notices']),'Legacy class retained without duplicate boot');echo "Legacy guard passed.\n";exit;
}
require dirname(__DIR__).'/modules/wc-live-insights/module.php';
// Complete PHP method inventory from the supplied 2.4.1 reference.
foreach(explode(',', 'boot,menu,input,choice,date,state,statuses,url,hpos,bounds,local_time_sql,status_sql,anchor,headers,cohort,identity_sql,guard_orders,rows,summary,item_lines,sales_sql,overview,customers,lifetime,abc,field_definitions,context,finance,finance_values,with_lock,ajax,batch_ids_sql,job_key,job_store,job_load,next_order_batch,summary_empty,job_start,job_accumulate_orders,text_contains,job_accumulate_categories_for_order,job_accumulate_sales,accumulate_product_line,job_step,catalog_rows,job_finish,report_payload,products,view_products,stock,page_data,money,num,rate,card,comparison,table_start,table_end,product_name,pager,select,filters,trend,view_stock,styles,cost_field,cost_input,save_cost,variation_cost_field,save_variation_cost,snapshot_cost,snapshot_saved_item,save_context,overview_series,overview_spark,overview_change,overview_kpi,overview_chart,overview_categories,orders_page,overview_orders,view_overview,view_customers,view_abc,metric_value,view_finance,render,live_script') as $method)check(method_exists('JMI_WC_Admin_Insights',$method),'Reference feature retained: '.$method);
function call_insights($name,...$args){$m=new \ReflectionMethod('JMI_WC_Admin_Insights',$name);if(PHP_VERSION_ID<80100)$m->setAccessible(true);return $m->invokeArgs(null,$args);}
function expect_error($fn,$text){try{$fn();check(false,'Expected rejection '.$text);}catch(\RuntimeException $e){check(strpos($e->getMessage(),$text)!==false,'Expected error '.$text.', got '.$e->getMessage());}}
check(!$wpdb->queries&&!$options&&!$transients,'Boot adds no queries, data writes or report jobs');
check(!isset($hooks['wp_enqueue_scripts'])&&!isset($hooks['admin_enqueue_scripts'])&&!isset($hooks['init']),'No global assets or automatic scan');
foreach($hooks['admin_menu'] as $fn)$fn();check(count($menus)===2&&$menus[0][3]==='jmi-wc-insights'&&$menus[1][0]==='wu-toolbox-modular'&&$menus[1][4]==='wu-wc-live-insights','Main menu dashboard + ordered Toolbox settings');
require dirname(__DIR__).'/core/module-registry.php';$entry=wutm_modules()['wc-live-insights'];check($entry['group']==='電商工具'&&$entry['requires']==='woocommerce','Commerce module card and Woo dependency');
ob_start();wutm_wc_live_insights_settings();$settings_html=ob_get_clean();check(!$wpdb->queries&&!$orderReads,'Settings do not query reports');
ob_start();JMI_WC_Admin_Insights::render();$shell=ob_get_clean();check(!$wpdb->queries&&!$orderReads,'Main dashboard loads shell only');
check(substr_count($shell,'<a class="nav-tab')===6&&strpos($shell,'jmi-live-config')!==false,'All six original report tabs and live configuration');
$state=call_insights('state');check($state['days']===30&&JMI_WC_Admin_Insights::BATCH_ORDERS===40&&JMI_WC_Admin_Insights::MAX_DAYS===366,'Original bounds');
check(call_insights('date','2026-02-31')===false,'Invalid calendar dates rejected');
$_GET=['from'=>'2020-01-01','to'=>wp_date('Y-m-d')];expect_error(static function(){call_insights('state');},'366');$_GET=[];
$hpos=true;$bounds=call_insights('bounds',['from'=>'2026-10-01','to'=>'2026-10-01']);check($bounds===['2026-09-30 16:00:00','2026-10-01 16:00:00'],'HPOS UTC and inclusive local day');
$hpos=false;check(call_insights('bounds',['from'=>'2026-10-01','to'=>'2026-10-01'])===['2026-10-01 00:00:00','2026-10-02 00:00:00'],'Legacy local boundaries');
expect_error(static function(){call_insights('rows','DELETE FROM orders');},'唯讀');
$wpdb->version='unknown';expect_error(static function(){call_insights('rows','SELECT 1');},'無法確認');$wpdb->version='8.0.36';
call_insights('rows','SELECT 1');check(strpos(end($wpdb->queries),'MAX_EXECUTION_TIME(8000)')!==false,'MySQL execution limit');
$wpdb->version='10.6.0-MariaDB';call_insights('rows','SELECT 1');check(strpos(end($wpdb->queries),'max_statement_time=8')!==false,'MariaDB execution limit');$wpdb->version='8.0.36';
$clock=new \ReflectionProperty('JMI_WC_Admin_Insights','started');if(PHP_VERSION_ID<80100)$clock->setAccessible(true);$clock->setValue(null,microtime(true)-26);
expect_error(static function(){call_insights('check_deadline');},'時間已達');$clock->setValue(null,0);
expect_error(static function(){call_insights('job_store','oversized-fixture',['data'=>random_bytes(3200000)]);},'暫存記憶體');
$wpdb->locked=false;expect_error(static function(){call_insights('with_lock',static function(){return 1;});},'另一份');check(!$wpdb->quiet,'DB error suppression restored');$wpdb->locked=true;
expect_error(static function(){call_insights('with_lock',static function(){throw new \RuntimeException('forced');});},'forced');check(strpos(end($wpdb->queries),'RELEASE_LOCK')!==false&&!$wpdb->quiet,'Lock always released on failure');
$caps=false;expect_error(static function(){JMI_WC_Admin_Insights::ajax();},'403');expect_error(static function(){wutm_wc_live_insights_settings();},'權限');$caps=true;$nonce=false;expect_error(static function(){JMI_WC_Admin_Insights::ajax();},'nonce');$nonce=true;
$product=new WC_Product;$product->meta=['_jmi_unit_cost'=>'50'];$_POST=['_jmi_unit_cost'=>'-1'];JMI_WC_Admin_Insights::save_cost($product);check($product->meta['_jmi_unit_cost']==='50'&&$errors,'Invalid costs preserve old values');
$_POST=['_jmi_unit_cost'=>'0'];JMI_WC_Admin_Insights::save_cost($product);check($product->meta['_jmi_unit_cost']==='0','Zero cost preserved');
$item=new WC_Order_Item_Product;JMI_WC_Admin_Insights::snapshot_cost($item,'',['data'=>$product],null);check($item->meta['_jmi_unit_cost_snapshot']==='0','Checkout snapshots known zero cost');
$product->meta['_jmi_unit_cost']='90';JMI_WC_Admin_Insights::snapshot_cost($item,'',['data'=>$product],null);check($item->meta['_jmi_unit_cost_snapshot']==='0','Existing snapshot never overwritten');
$meta[11]['_jmi_unit_cost']='50';$variant=new WC_Product(12,11);$item2=new WC_Order_Item_Product;$item2->product=$variant;JMI_WC_Admin_Insights::snapshot_saved_item(2,$item2);check($item2->meta['_jmi_unit_cost_snapshot']==='50'&&$item2->saved===1,'Admin-order cost snapshots inherit parent');
JMI_WC_Admin_Insights::snapshot_saved_item(2,$item2);check($item2->saved===1,'Repeated item hook does not rewrite');
$sold=new WC_Order_Item_Product;$sold->meta['_jmi_unit_cost_snapshot']='50';$refund=new WC_Order_Item_Product;$refund->qty=-1;$refund->total=-100;$refund->meta['_refunded_item_id']=1;
$order=new WC_Order;$order->items=[1=>$sold];$return=new WC_Order_Refund;$return->items=[$refund];$order->refunds=[$return];$orders[1]=$order;
$job=['s'=>$state,'current'=>call_insights('summary_empty'),'previous'=>call_insights('summary_empty'),'sales'=>[],'customers'=>[],'daily'=>[],'categories'=>[],'statuses'=>[]];
$rm=new \ReflectionMethod('JMI_WC_Admin_Insights','job_accumulate_orders');if(PHP_VERSION_ID<80100)$rm->setAccessible(true);$rm->invokeArgs(null,[&$job,[$order],false]);
check($job['current']['collected']===800.0&&$job['current']['net']===670.0&&$job['current']['tax']===80.0&&$job['current']['shipping']===50.0,'Refund/tax/shipping totals preserved');
$rm=new \ReflectionMethod('JMI_WC_Admin_Insights','job_accumulate_sales');if(PHP_VERSION_ID<80100)$rm->setAccessible(true);$rm->invokeArgs(null,[&$job,[$order]]);
check($job['sales'][11]['qty']===1.0&&$job['sales'][11]['cogs']===50.0&&$job['sales'][11]['revenue']===700.0,'Partial refund quantity and cost preserved');
$context=['ad_spend'=>100,'shipping_cost'=>25,'platform_fees'=>10,'payment_fees'=>10,'payroll'=>20,'overhead'=>5,'expenses_complete'=>true,'sessions'=>100,'converted_sessions'=>2,'new_customers'=>5,'attributed_revenue'=>600,'attributed_contribution'=>300,'cart_sessions'=>10,'abandoned_sessions'=>2,'contribution_margin'=>50,'average_inventory_cost'=>100];
$context_key=$state['from'].':'.$state['to'].':TWD';$options['jmi_wci_period_contexts_v2']=[$context_key=>$context];
$finance=call_insights('finance_values',$state,$job['current'],['cogs'=>50,'missing_cost'=>0,'estimated_cost'=>0]);
check($finance['gross_profit']===620.0&&$finance['net_profit']===500.0&&$finance['roas']===6.0&&$finance['cvr']===0.02&&$finance['inventory_days']===60.0,'Finance definitions and original context retained');
$missing=call_insights('finance_values',$state,$job['current'],['cogs'=>50,'missing_cost'=>1,'estimated_cost'=>0]);check($missing['cogs']===null&&$missing['net_profit']===null,'Missing costs are not fabricated');
$state['tab']='products';$pending=call_insights('job_start',$state);check($pending['pending']&&max($GLOBALS['ttls'])===600,'User-bound temporary job starts without report query');
$wpdb->batches=[range(1,41),[41]];for($id=1;$id<=41;$id++){$orders[$id]=clone $order;$orders[$id]->id=$id;}
$beforeReads=$orderReads;$progress=call_insights('job_step',$pending['token'],0);check($progress['pending']&&$progress['done']===40&&$orderReads-$beforeReads===40,'One step reads at most 40 original orders');
expect_error(static function()use($pending){call_insights('job_step',$pending['token'],0);},'重複');
$done=call_insights('job_step',$pending['token'],1);check(!isset($done['pending'])&&isset($done['html'],$done['csv'])&&strpos($done['html'],'FIXTURE')!==false,'Complete products report and CSV');
check(!isset($transients[call_insights('job_key',$pending['token'])]),'Completed report not retained in transient');
expect_error(static function()use($pending){call_insights('job_step',$pending['token'],0);},'過期');
$pending=call_insights('job_start',$state);$user=18;check(call_insights('job_load',$pending['token'])===false,'Another user cannot read job');$user=17;
$saved=call_insights('job_load',$pending['token']);$saved['phase_done']=20000;call_insights('job_store',$pending['token'],$saved);$wpdb->batches=[[1]];
expect_error(static function()use($pending){call_insights('job_step',$pending['token'],0);},'20,000');
$pending=call_insights('job_start',$state);$currency='USD';expect_error(static function()use($pending){call_insights('job_step',$pending['token'],0);},'幣別');$currency='TWD';
$_SERVER['REQUEST_METHOD']='POST';$_POST=['jmi_save_context'=>1,'context'=>['converted_sessions'=>'10','sessions'=>'2']];$before=$options;expect_error(static function(){JMI_WC_Admin_Insights::save_context();},'不得大於');check($options===$before,'Invalid manual context does not overwrite old settings');$_POST=[];
$mode=$argv[1]??'policy';
if($mode==='shell'){if(isset($argv[2])){$_GET=['tab'=>$argv[2]];ob_start();JMI_WC_Admin_Insights::render();$shell=ob_get_clean();}echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:18px;background:#f0f0f1}input{max-width:100%}</style>'.$shell;exit;}
if($mode==='settings'){echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:18px;background:#f0f0f1}a{display:inline-block}</style>'.$settings_html;exit;}
if($mode==='overview'){$state['tab']='overview';$job['s']=$state;$job['previous_s']=$state;$job['current']['customers']=1;$data=call_insights('job_finish',$job);echo json_encode(call_insights('report_payload',$state,$data));exit;}
if($mode==='orders'){echo json_encode(call_insights('orders_page',2));exit;}
if($mode==='customers'){$state['tab']='customers';echo json_encode(call_insights('report_payload',$state,['more'=>false,'rows'=>[['first_name'=>'=2+2','last_name'=>'Fixture','email'=>'buyer@example.test','user_id'=>3,'orders'=>2,'collected'=>1600,'refunds'=>400,'refunded_orders'=>2,'last_order'=>wp_date('Y-m-d H:i:s')]]]));exit;}
if($mode==='products'){echo json_encode($done);exit;}
if($mode==='abc'){$state['tab']='abc';$job['s']=$state;echo json_encode(call_insights('report_payload',$state,call_insights('job_finish',$job)));exit;}
if($mode==='finance'){$state['tab']='finance';echo json_encode(call_insights('report_payload',$state,$finance));exit;}
if($mode==='stock'){$state['tab']='stock';echo json_encode(call_insights('report_payload',$state,['summary'=>['total'=>1,'low'=>1,'outofstock'=>0,'onbackorder'=>0,'unmanaged'=>0],'more'=>false,'rows'=>[['product_id'=>11,'post_parent'=>0,'post_type'=>'product','name'=>'Stock fixture','sku'=>'FIXTURE','stock_quantity'=>2,'stock_status'=>'instock','managed'=>'yes','threshold'=>2,'is_low'=>1]]]));exit;}
echo "Live insights passed: scope, native menus, HPOS/timezone, permissions, query limits, costs, refunds, finance, bounded jobs, preservation and CSV.\n";
}
