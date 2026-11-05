<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/class-wp-posts-list-table.php';
require_once WC_ABSPATH.'includes/admin/wc-admin-functions.php';
set_current_screen('edit-shop_order');
require dirname(__DIR__).'/modules/order-list-layout/module.php';
require_once WC_ABSPATH.'includes/admin/list-tables/class-wc-admin-list-table-orders.php';
new WC_Admin_List_Table_Orders();
function v395_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function v395_save($scope,$post){$_POST=$post+['view'=>$scope];$_POST['_wpnonce']=wp_create_nonce('wutm_order_columns_'.$scope);$_REQUEST=$_POST;ob_start();WUTM_Order_List_Layout::page();return ob_get_clean();}
$uid=get_current_user_id();
try{
 delete_option(WUTM_Order_List_Layout::OPTION);delete_option(WUTM_Order_List_Layout::REGISTRY);
 foreach(['legacy'=>'edit-shop_order','hpos'=>'woocommerce_page_wc-orders'] as $scope=>$screen){
  set_current_screen($screen);
  if($scope==='hpos'){$table=wc_get_container()->get(\Automattic\WooCommerce\Internal\Admin\Orders\ListTable::class);$table->setup();}
  else $table=new WP_Posts_List_Table(['screen'=>get_current_screen()]);
  add_filter('manage_'.$screen.'_columns',static function($cols){$cols['fixture_tracking']='物流查詢編號';return $cols;},100);
  $before=get_column_headers(get_current_screen());
  foreach(['cb','order_number','order_date','order_status','order_total','fixture_tracking'] as $id)v395_assert(isset($before[$id]),'Native '.$scope.' column '.$id);
  $sortable=apply_filters('manage_'.$screen.'_sortable_columns',[]);
  v395_save($scope,['wutm_order_columns_action'=>'save','order'=>['order_total','order_number','fixture_tracking','unknown'],'labels'=>['order_total'=>'<b>訂單金額</b>'],'hide'=>['fixture_tracking'=>1]]);
  $after=apply_filters('manage_'.$screen.'_columns',$before);
  v395_assert(array_slice(array_keys($after),0,4)===['cb','order_total','order_number','fixture_tracking']&&$after['order_total']==='訂單金額','Order/label '.$scope);
  v395_assert(count($before)===count($after)&&!isset($after['unknown'])&&$after['cb']===$before['cb'],'Preserved columns '.$scope);
  v395_assert(in_array('fixture_tracking',get_hidden_columns(get_current_screen()),true),'Hidden '.$scope);
  v395_assert(apply_filters('manage_'.$screen.'_sortable_columns',[])===$sortable,'Sortable '.$scope);
 }
 v395_save('legacy',['wutm_order_columns_action'=>'reset']);
 v395_assert(!WUTM_Order_List_Layout::config('legacy')&&WUTM_Order_List_Layout::config('hpos'),'Independent reset');
 v395_assert(WUTM_Order_List_Layout::hidden(['title'],(object)['id'=>'edit-post'])===['title'],'Other screens untouched');
 wp_set_current_user(0);$before=get_option(WUTM_Order_List_Layout::OPTION);v395_save('hpos',['wutm_order_columns_action'=>'reset']);v395_assert($before===get_option(WUTM_Order_List_Layout::OPTION),'Permission required');
 echo "v3.9.5 native orders passed: legacy/HPOS native and third-party columns, labels, reordering, hidden fields, independent reset, sorting and permissions.\n";
}finally{wp_set_current_user($uid);$_POST=$_REQUEST=[];delete_option(WUTM_Order_List_Layout::OPTION);delete_option(WUTM_Order_List_Layout::REGISTRY);delete_option('wutm_order_list_native_hidden');}
