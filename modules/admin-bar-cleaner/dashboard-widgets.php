<?php
defined('ABSPATH') || exit;
final class WUTM_Dashboard_Widgets {
    static function registry(){return (array)get_option('wutm_dashboard_widget_registry',[]);}
    static function selected(){
        $saved=get_option('wutm_disabled_dashboard_widgets',null);
        if(is_array($saved))return $saved;
        return get_option('wu_disable_all_dashboard_widgets',false)?array_values(array_diff(array_keys(self::registry()),['wu_email_tracker_dashboard','wu_unified_dashboard'])):[];
    }
    static function collect(){
        if(!current_user_can('manage_options'))return;
        global $wp_meta_boxes;$found=['welcome_panel'=>'歡迎使用 WordPress'];
        foreach((array)($wp_meta_boxes['dashboard']??[]) as $priorities)foreach((array)$priorities as $boxes)foreach((array)$boxes as $id=>$box)if(is_array($box)&&!empty($box['title']))$found[$id]=trim(wp_strip_all_tags($box['title']));
        $old=self::registry();$new=array_merge($old,$found);if($new!==$old)update_option('wutm_dashboard_widget_registry',$new,false);
    }
    static function apply(){
        self::collect();global $wp_meta_boxes;$selected=self::selected();
        $legacy=get_option('wutm_disabled_dashboard_widgets',null)===null&&get_option('wu_disable_all_dashboard_widgets',false);
        if($legacy)$selected[]='welcome_panel';
        if(in_array('welcome_panel',$selected,true))remove_action('welcome_panel','wp_welcome_panel');
        foreach((array)($wp_meta_boxes['dashboard']??[]) as $context=>$priorities)foreach((array)$priorities as $boxes)foreach(array_keys((array)$boxes) as $id)if(in_array($id,$selected,true)||($legacy&&!in_array($id,['wu_email_tracker_dashboard','wu_unified_dashboard'],true)))remove_meta_box($id,'dashboard',$context);
    }
    static function save(){
        if(!isset($_POST['wutm_dashboard_widget_list'])||!self::registry())return;
        $ids=(array)($_POST['wutm_disabled_dashboard_widgets']??[]);$ids=array_values(array_intersect(array_filter($ids,'is_string'),array_keys(self::registry())));
        update_option('wutm_disabled_dashboard_widgets',$ids,false);update_option('wu_disable_all_dashboard_widgets',0);
    }
    static function render(){
        echo '<input type="hidden" name="wutm_dashboard_widget_list" value="1"><p>勾選要關閉的小工具，再儲存設定。未勾選的項目保留。</p><p><a class="button" href="'.esc_url(admin_url('index.php')).'">前往儀表板偵測／更新列表</a></p>';
        $registry=self::registry();if(!$registry){echo '<p>請先開啟儀表板，再返回這裡查看實際的小工具。</p>';return;}
        echo '<ul>';foreach($registry as $id=>$title)echo '<li><label><input type="checkbox" name="wutm_disabled_dashboard_widgets[]" value="'.esc_attr($id).'" '.checked(in_array($id,self::selected(),true),true,false).'> '.esc_html($title).' <code>'.esc_html($id).'</code></label></li>';echo '</ul><p>清單保留先前偵測過的條件式小工具；目前未註冊的項目不會影響其他功能。</p>';
    }
}
