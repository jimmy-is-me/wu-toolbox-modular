<?php
defined('ABSPATH') || exit;
if(!class_exists('WooCommerce')||!is_admin())return;
final class WUTM_Order_List_Layout {
    const OPTION='wutm_order_list_layout';const REGISTRY='wutm_order_list_columns';
    static function init(){
        add_filter('manage_edit-shop_order_columns',static fn($cols)=>self::columns($cols,'legacy'),PHP_INT_MAX);
        add_filter('manage_woocommerce_page_wc-orders_columns',static fn($cols)=>self::columns($cols,'hpos'),PHP_INT_MAX);
        add_filter('hidden_columns',[__CLASS__,'hidden'],PHP_INT_MAX,3);
        add_action('admin_menu',static function(){add_submenu_page('wu-toolbox-modular','訂單列表排列','訂單列表排列','manage_options','wu-order-list-layout',[__CLASS__,'page']);});
    }
    static function data($option,$scope){$all=get_option($option,[]);return is_array($all)&&is_array($all[$scope]??null)?$all[$scope]:[];}
    static function config($scope){return self::data(self::OPTION,$scope);}
    static function put($option,$scope,$value){$all=get_option($option,[]);if(!is_array($all))$all=[];if(($all[$scope]??null)!==$value){$all[$scope]=$value;update_option($option,$all,false);}}
    static function columns($columns,$scope){
        if(current_user_can('manage_options')){$found=[];foreach($columns as $id=>$label)$found[$id]=trim(wp_strip_all_tags($label))?:$id;self::put(self::REGISTRY,$scope,array_merge(self::data(self::REGISTRY,$scope),$found));}
        $out=[];if(isset($columns['cb']))$out['cb']=$columns['cb'];
        foreach(self::config($scope) as $id=>$row)if($id!=='cb'&&isset($columns[$id]))$out[$id]=!empty($row['label'])?esc_html($row['label']):$columns[$id];
        return $out+$columns;
    }
    static function hidden($hidden,$screen,$defaults=false){
        $scope=['edit-shop_order'=>'legacy','woocommerce_page_wc-orders'=>'hpos'][$screen->id??'']??'';if(!$scope)return $hidden;
        if(current_user_can('manage_options'))self::put('wutm_order_list_native_hidden',$scope,$hidden);
        foreach(self::config($scope) as $id=>$row){if($id==='cb')continue;$hidden=array_values(array_diff($hidden,[$id]));if(!empty($row['hide']))$hidden[]=$id;}
        return array_values(array_unique($hidden));
    }
    static function scope(){
        $view=$_POST['view']??$_GET['view']??'';if(in_array($view,['legacy','hpos'],true))return $view;
        return class_exists('Automattic\WooCommerce\Utilities\OrderUtil')&&\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'legacy';
    }
    static function page(){
        if(!current_user_can('manage_options'))return;$scope=self::scope();$registry=self::data(self::REGISTRY,$scope);$notice='';
        if(isset($_POST['wutm_order_columns_action'])){check_admin_referer('wutm_order_columns_'.$scope);
            if($_POST['wutm_order_columns_action']==='reset'){self::put(self::OPTION,$scope,[]);$notice='已恢復此列表的原生排列與個人顯示偏好。';}
            elseif($_POST['wutm_order_columns_action']==='save'){$new=[];foreach((array)($_POST['order']??[]) as $id){if(!is_string($id)||$id==='cb'||!array_key_exists($id,$registry))continue;$label=$_POST['labels'][$id]??'';$new[$id]=['label'=>is_string($label)?sanitize_text_field(wp_unslash($label)):'','hide'=>!empty($_POST['hide'][$id])];}self::put(self::OPTION,$scope,$new);$notice='設定已儲存。';}
        }
        wutm_admin_page_open('訂單列表排列','偵測訂單清單的原生與外掛欄位，自訂標籤、上下移動或隱藏；保留訂單資料、欄位回呼與排序功能。');
        if($notice)echo '<p role="status">'.esc_html($notice).'</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="訂單儲存方式">';foreach(['legacy'=>'傳統訂單列表','hpos'=>'HPOS 訂單列表'] as $id=>$title)echo '<a class="nav-tab '.($scope===$id?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=wu-order-list-layout&view='.$id)).'">'.esc_html($title).'</a>';echo '</nav>';
        $list=$scope==='hpos'?'admin.php?page=wc-orders':'edit.php?post_type=shop_order';echo '<p><a class="button" href="'.esc_url(admin_url($list)).'">前往目前訂單列表偵測／更新欄位</a></p><p>首次使用請先進入訂單列表再返回。本頁不切換訂單儲存方式；兩種列表的設定各自保存。新欄位會自動加入，條件式欄位的偵測記錄會保留。儲存後共用欄位顯示設定，重設則恢復原生偏好。</p>';
        if(!$registry)echo '<p>尚未偵測到這種列表的欄位；請使用網站目前啟用的訂單儲存方式進入列表。</p>';
        $config=self::config($scope);$ids=array_unique(array_merge(array_keys($config),array_keys($registry)));$hidden=self::data('wutm_order_list_native_hidden',$scope);
        echo '<form method="post"><input type="hidden" name="view" value="'.$scope.'">';wp_nonce_field('wutm_order_columns_'.$scope);echo '<div class="wutm-ui-table-scroll"><table class="widefat striped"><thead><tr><th>原標籤／欄位 ID</th><th>自訂標籤（留空沿用）</th><th>隱藏</th><th>順序</th></tr></thead><tbody class="wutm-order-layout-rows">';
        foreach($ids as $id){if($id==='cb'||!array_key_exists($id,$registry))continue;$row=$config[$id]??[];echo '<tr><td>'.esc_html($registry[$id]).'<br><code>'.esc_html($id).'</code><input type="hidden" name="order[]" value="'.esc_attr($id).'"></td><td><input type="text" name="labels['.esc_attr($id).']" value="'.esc_attr($row['label']??'').'"></td><td><input aria-label="'.esc_attr('隱藏 '.$registry[$id]).'" type="checkbox" name="hide['.esc_attr($id).']" value="1" '.checked($row['hide']??in_array($id,$hidden,true),true,false).'></td><td><button type="button" class="button wutm-order-up">上移</button> <button type="button" class="button wutm-order-down">下移</button></td></tr>';}
        echo '</tbody></table></div><p><button class="button button-primary" name="wutm_order_columns_action" value="save">儲存設定</button> <button class="button" name="wutm_order_columns_action" value="reset">重設排列</button></p></form><p>核取方塊保留在最前方；隱藏訂單欄也會隱藏該欄的編輯與預覽入口，可隨時回來恢復。</p><script>document.querySelectorAll(".wutm-order-up,.wutm-order-down").forEach(function(b){b.addEventListener("click",function(){var r=b.closest("tr");if(b.classList.contains("wutm-order-up")&&r.previousElementSibling)r.parentNode.insertBefore(r,r.previousElementSibling);else if(b.classList.contains("wutm-order-down")&&r.nextElementSibling)r.parentNode.insertBefore(r.nextElementSibling,r);});});</script>';
        wutm_admin_page_close();
    }
}
WUTM_Order_List_Layout::init();
