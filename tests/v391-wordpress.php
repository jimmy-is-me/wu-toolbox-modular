<?php
// Native APIs against the disposable CI database only. No merchant traffic.
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
define('WUTM_VERSION','fixture');define('WUTM_URL','https://fixture.test/');
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/class-wp-posts-list-table.php';
set_current_screen('edit-product');
require dirname(__DIR__).'/core/admin-design.php';
require dirname(__DIR__).'/modules/product-list-layout/module.php';
require dirname(__DIR__).'/modules/postbox-manager/module.php';
require dirname(__DIR__).'/modules/seo-core/editor-content.php';
require dirname(__DIR__).'/modules/seo-core/module.php';
require_once WC_ABSPATH.'includes/admin/list-tables/class-wc-admin-list-table-products.php';
new WC_Admin_List_Table_Products();
function v391_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function v391_page($class,$post){$_POST=$post;$_REQUEST=$post;$_POST['_wpnonce']=$_REQUEST['_wpnonce']=wp_create_nonce($class==='WUTM_Postbox_Manager'?'wutm_pbm_save':'wutm_product_columns');ob_start();$class::page();return ob_get_clean();}
$uid=get_current_user_id();$post=0;$attachment=0;
add_filter('pre_http_request',static fn()=>new WP_Error('fixture','No external calls'));
try{
 delete_option(WUTM_Product_List_Layout::OPTION);delete_option(WUTM_Product_List_Layout::REGISTRY);
 add_filter('manage_product_posts_columns',static function($cols){$cols['shipping_temperature']='運送溫層';return $cols;},100);
 $table=new WP_Posts_List_Table(['screen'=>get_current_screen()]);
 $before=get_column_headers(get_current_screen());
 foreach(['cb','thumb','name','sku','price','shipping_temperature'] as $id)v391_assert(isset($before[$id]),'Actual WooCommerce and foreign column detected: '.$id);
 $sortable=apply_filters('manage_edit-product_sortable_columns',[]);
 v391_page('WUTM_Product_List_Layout',['wutm_columns_action'=>'save','order'=>['sku','name','thumb','shipping_temperature','unknown'],'labels'=>['sku'=>'<b>庫存代碼</b>','unknown'=>'bad'],'hide'=>['shipping_temperature'=>1]]);
 $after=apply_filters('manage_edit-product_columns',$before);
 v391_assert(array_slice(array_keys($after),0,4)===['cb','sku','name','thumb']&&$after['sku']==='庫存代碼','Order and sanitized custom label');
 v391_assert(count($after)===count($before)&&$after['cb']===$before['cb']&&!isset($after['unknown']),'Native columns and checkbox preserved; unknown POST rejected');
 v391_assert(apply_filters('manage_edit-product_sortable_columns',[])===$sortable,'Sorting untouched');
 update_user_option($uid,'manageedit-productcolumnshidden',['price'],true);
 v391_assert(in_array('shipping_temperature',get_hidden_columns(get_current_screen()),true)&&in_array('price',get_hidden_columns(get_current_screen()),true),'Configured and unrelated hidden fields');
 v391_assert(get_user_option('manageedit-productcolumnshidden')===['price'],'Native personal preference never overwritten');
 v391_assert(WUTM_Product_List_Layout::hidden(['title'],(object)['id'=>'edit-post'])===['title'],'Other lists untouched');
 v391_page('WUTM_Product_List_Layout',['wutm_columns_action'=>'reset']);v391_assert(get_hidden_columns(get_current_screen())===['price'],'Reset restores personal preference');

 update_option('pbm_registry',['product'=>['conditional'=>['title'=>'條件式區塊','context'=>'normal']]]);
 set_current_screen('product');add_meta_box('fixture-box','Fixture',static function(){echo 'callback retained';},'product','normal');
 do_action('add_meta_boxes','product',null);
 add_meta_box('late-box','Late fixture',static function(){},'product','side');
 ob_start();WUTM_Postbox_Manager::head();ob_end_clean();
 $registry=WUTM_Postbox_Manager::registry();v391_assert(isset($registry['product']['conditional'],$registry['product']['fixture-box'],$registry['product']['late-box']),'Historical and late boxes retained');
 v391_page('WUTM_Postbox_Manager',['pbm_action'=>'save','hide'=>['product'=>['fixture-box','invalid"id']],'state'=>['product'=>['fixture-box'=>'closed','late-box'=>'open','unknown'=>'closed']]]);
 v391_assert(WUTM_Postbox_Manager::settings()['hide']['product']===['fixture-box'],'Known safe IDs only');
 v391_assert(WUTM_Postbox_Manager::closed(false,'product')===['fixture-box'],'Default collapsed for untouched users');
 v391_assert(WUTM_Postbox_Manager::closed([],'product')===[],'Explicit expanded preference respected');
 v391_assert(WUTM_Postbox_Manager::closed(['late-box','other'],'product')===['other'],'Forced open preserves other preferences');
 ob_start();WUTM_Postbox_Manager::head();$css=ob_get_clean();v391_assert(strpos($css,'[id="fixture-box"]')!==false&&is_callable($GLOBALS['wp_meta_boxes']['product']['normal']['default']['fixture-box']['callback']),'CSS only, callback remains registered');
 update_user_meta($uid,'closedpostboxes_product',['fixture-box']);update_user_meta($uid,$GLOBALS['wpdb']->get_blog_prefix().'closedpostboxes_product',['late-box']);update_user_meta($uid,'closedpostboxes_post',['keep']);
 v391_page('WUTM_Postbox_Manager',['pbm_action'=>'reset_users','reset_pt'=>'product']);
 v391_assert(!metadata_exists('user',$uid,'closedpostboxes_product')&&!metadata_exists('user',$uid,$GLOBALS['wpdb']->get_blog_prefix().'closedpostboxes_product')&&get_user_meta($uid,'closedpostboxes_post',true)===['keep'],'Reset handles native/legacy keys and preserves unrelated preferences');
 $saved=WUTM_Postbox_Manager::settings();wp_set_current_user(0);v391_page('WUTM_Postbox_Manager',['pbm_action'=>'save']);v391_assert(WUTM_Postbox_Manager::settings()===$saved,'Unauthorized POST cannot save');wp_set_current_user($uid);

 v391_assert(wutm_seo_keywords("園藝資材，培養土\nGarden;garden；培養土")==='園藝資材, 培養土, Garden','Multiple groups and deduplication');
 v391_assert(wutm_seo_keywords(['invalid'])==='','Invalid keyword type rejected');
 $post=wp_insert_post(['post_title'=>'SEO fixture','post_type'=>'page','post_status'=>'draft']);
 Wumetax_SEO_Core_v120::register_post_meta_fields();
 update_post_meta($post,'_wu_seo_topic',"培養土，園藝資材\n培養土");v391_assert(get_post_meta($post,'_wu_seo_topic',true)==='培養土, 園藝資材','Registered metadata normalizes block editor save');
 $_POST=[Wumetax_SEO_Core_v120::META_NONCE_KEY=>'invalid','wu_seo_topic'=>'bad'];Wumetax_SEO_Core_v120::save_meta_box($post,get_post($post));v391_assert(get_post_meta($post,'_wu_seo_topic',true)==='培養土, 園藝資材','Invalid classic nonce cannot change keywords');
 $_POST=[Wumetax_SEO_Core_v120::META_NONCE_KEY=>wp_create_nonce(Wumetax_SEO_Core_v120::META_NONCE_ACT),'wu_seo_topic'=>"培養土\nGarden;GARDEN"];Wumetax_SEO_Core_v120::save_meta_box($post,get_post($post));v391_assert(get_post_meta($post,'_wu_seo_topic',true)==='培養土, Garden','Classic editor preserves newline groups');$_POST=$_REQUEST=[];
 $attachment=wp_insert_post(['post_title'=>'Image fixture','post_type'=>'attachment','post_status'=>'inherit']);update_post_meta($attachment,'_wp_attachment_image_alt','培養土圖片');
 add_shortcode('v391_throw',static function(){throw new RuntimeException('Shortcode must not run');});
 update_post_meta($post,'_elementor_data',wp_slash(wp_json_encode([['widgetType'=>'heading','settings'=>['title'=>'培養土','header_size'=>'h2'],'elements'=>[['widgetType'=>'text-editor','settings'=>['editor'=>'<p>園藝資材 [v391_throw]</p>']],['widgetType'=>'image','settings'=>['image'=>['id'=>$attachment]]]]]])));
 $html=wutm_seo_elementor_text($post);v391_assert(strpos($html,'<h2>培養土</h2>')!==false&&strpos($html,'園藝資材')!==false&&strpos($html,'alt="培養土圖片"')!==false,'Saved nested Elementor text/heading/alt detected without rendering');
 update_post_meta($post,'_elementor_data','invalid');v391_assert(wutm_seo_elementor_text($post)==='','Malformed builder data safe');
 echo "v3.9.1 native modules passed: real WooCommerce columns/sorting/preferences, Postbox collection/CSS/state/reset/permissions, multiple keywords and saved Elementor detection.\n";
}finally{wp_set_current_user($uid);$_POST=$_REQUEST=[];if($post)wp_delete_post($post,true);if($attachment)wp_delete_post($attachment,true);}
