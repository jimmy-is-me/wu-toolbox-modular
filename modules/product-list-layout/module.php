<?php
defined('ABSPATH') || exit;
if(!class_exists('WooCommerce')||!is_admin())return;
final class WUTM_Product_List_Layout {
    const OPTION='wutm_product_list_layout';const REGISTRY='wutm_product_list_columns';
    static function init(){add_filter('manage_edit-product_columns',[__CLASS__,'columns'],PHP_INT_MAX);add_filter('hidden_columns',[__CLASS__,'hidden'],PHP_INT_MAX,3);add_action('admin_menu',static function(){add_submenu_page('wu-toolbox-modular','商品列表排列','商品列表排列','manage_options','wu-product-list-layout',[__CLASS__,'page']);});}
    static function config(){ $data=get_option(self::OPTION,[]);return is_array($data)?$data:[]; }
    static function columns($columns){
        if(current_user_can('manage_options')){$found=[];$names=['thumb'=>'圖片','featured'=>'精選','cb'=>'選取'];foreach($columns as $key=>$label)$found[$key]=trim(wp_strip_all_tags($label))?:($names[$key]??$key);$old=get_option(self::REGISTRY,[]);$merged=array_merge(is_array($old)?$old:[],$found);if($merged!==$old)update_option(self::REGISTRY,$merged,false);}
        $config=self::config();$out=[];if(isset($columns['cb']))$out['cb']=$columns['cb'];
        foreach($config as $id=>$row)if($id!=='cb'&&isset($columns[$id]))$out[$id]=!empty($row['label'])?esc_html($row['label']):$columns[$id];
        return $out+$columns;
    }
    static function hidden($hidden,$screen,$use_defaults=false){
        if(!$screen||$screen->id!=='edit-product')return $hidden;
        if(current_user_can('manage_options')&&get_option('wutm_product_list_native_hidden',null)!==$hidden)update_option('wutm_product_list_native_hidden',$hidden,false);
        foreach(self::config() as $id=>$row){if($id==='cb')continue;$hidden=array_values(array_diff($hidden,[$id]));if(!empty($row['hide']))$hidden[]=$id;}
        return array_values(array_unique($hidden));
    }
    static function page(){
        if(!current_user_can('manage_options'))return;
        $registry=get_option(self::REGISTRY,[]);if(!is_array($registry))$registry=[];
        if(isset($_POST['wutm_columns_action'])){check_admin_referer('wutm_product_columns');
            if($_POST['wutm_columns_action']==='reset')delete_option(self::OPTION);
            elseif($_POST['wutm_columns_action']==='save'){$new=[];foreach((array)($_POST['order']??[]) as $id){if(!is_string($id)||$id==='cb'||!array_key_exists($id,$registry))continue;$label=$_POST['labels'][$id]??'';$new[$id]=['label'=>is_string($label)?sanitize_text_field(wp_unslash($label)):'','hide'=>!empty($_POST['hide'][$id])];}update_option(self::OPTION,$new,false);}
        }
        wutm_admin_page_open('商品列表排列','偵測後台商品清單的原生與外掛欄位，可更名、調整順序及隱藏；不改動商品資料、排序功能或欄位回呼。');
        echo '<p><a class="button" href="'.esc_url(admin_url('edit.php?post_type=product')).'">前往商品列表偵測／更新欄位</a></p><p>首次使用請先開啟商品列表再返回。新欄位會自動加入，條件式欄位的舊記錄會保留。儲存後覆蓋各使用者的欄位顯示設定；重設可恢復原生偏好。選取核取方塊保留在最前方。</p>';
        $config=self::config();$ids=array_unique(array_merge(array_keys($config),array_keys($registry)));$defaults=(array)get_option('wutm_product_list_native_hidden',[]);
        echo '<form method="post">';wp_nonce_field('wutm_product_columns');echo '<div class="wutm-ui-table-scroll"><table class="widefat striped"><thead><tr><th>原標籤／欄位 ID</th><th>自訂標籤（留空沿用）</th><th>隱藏</th><th>順序</th></tr></thead><tbody class="wutm-layout-rows">';
        foreach($ids as $id){if($id==='cb'||!array_key_exists($id,$registry))continue;$row=$config[$id]??[];echo '<tr><td>'.esc_html($registry[$id]?:$id).'<br><code>'.esc_html($id).'</code><input type="hidden" name="order[]" value="'.esc_attr($id).'"></td><td><input type="text" name="labels['.esc_attr($id).']" value="'.esc_attr($row['label']??'').'"></td><td><input type="checkbox" name="hide['.esc_attr($id).']" value="1" '.checked($row['hide']??in_array($id,$defaults,true),true,false).'></td><td><button type="button" class="button wutm-layout-up">上移</button> <button type="button" class="button wutm-layout-down">下移</button></td></tr>';}
        echo '</tbody></table></div><p><button class="button button-primary" name="wutm_columns_action" value="save">儲存</button> <button class="button" name="wutm_columns_action" value="reset">重設排列</button></p></form><p>隱藏名稱欄也會隱藏該欄的編輯連結；可隨時回到此頁恢復。</p>';
        echo '<script>document.querySelectorAll(".wutm-layout-up,.wutm-layout-down").forEach(function(button){button.addEventListener("click",function(){var row=button.closest("tr");if(button.classList.contains("wutm-layout-up")&&row.previousElementSibling)row.parentNode.insertBefore(row,row.previousElementSibling);else if(button.classList.contains("wutm-layout-down")&&row.nextElementSibling)row.parentNode.insertBefore(row.nextElementSibling,row);});});</script>';
        wutm_admin_page_close();
    }
}
WUTM_Product_List_Layout::init();
