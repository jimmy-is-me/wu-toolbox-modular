<?php
defined('ABSPATH') || exit;
if(!is_admin())return;
if(function_exists('pbm_get_settings')||defined('PBM_OPT_SETTINGS')){add_action('admin_notices',static function(){if(current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>Postbox 管理器：請先停用原程式，設定與已偵測記錄會保留。</p></div>';});return;}
final class WUTM_Postbox_Manager {
    static function settings(){return wp_parse_args((array)get_option('pbm_settings',[]),['hide'=>[],'state'=>[]]);}
    static function registry(){ $data=get_option('pbm_registry',[]);return is_array($data)?$data:[]; }
    static function id($id){return is_string($id)&&preg_match('/^[A-Za-z0-9_-]+$/D',$id)?$id:'';}
    static function init(){
        add_action('add_meta_boxes',[__CLASS__,'collect'],99999);
        add_action('admin_head',[__CLASS__,'head'],99999);
        add_action('admin_init',[__CLASS__,'states']);
        add_action('admin_menu',static function(){add_submenu_page('wu-toolbox-modular','Postbox 管理器','Postbox 管理器','manage_options','wu-postbox-manager',[__CLASS__,'page']);});
    }
    static function collect($type){
        if(!current_user_can('manage_options')||!is_string($type))return;
        global $wp_meta_boxes;$registry=self::registry();$found=[];
        foreach((array)($wp_meta_boxes[$type]??[]) as $context=>$priorities)foreach((array)$priorities as $boxes)foreach((array)$boxes as $id=>$box){if(!self::id($id)||!is_array($box)||empty($box['title']))continue;$found[$id]=['title'=>trim(wp_strip_all_tags($box['title'])),'context'=>sanitize_key($context)];}
        if(!$found)return;$merged=array_merge((array)($registry[$type]??[]),$found);
        if(($registry[$type]??null)!==$merged){$registry[$type]=$merged;update_option('pbm_registry',$registry,false);}
    }
    static function head(){
        $screen=get_current_screen();if(!$screen||$screen->base!=='post')return;
        self::collect($screen->post_type);$selectors=[];
        foreach((array)(self::settings()['hide'][$screen->post_type]??[]) as $id)if(self::id($id)){$selectors[]='[id="'.$id.'"]';$selectors[]='#screen-options-wrap label[for="'.$id.'-hide"]';}
        if($selectors)echo '<style id="wutm-pbm-hide">'.implode(',',$selectors).'{display:none!important}</style>';
    }
    static function states(){foreach((array)self::settings()['state'] as $type=>$state){if(!is_string($type)||sanitize_key($type)!==$type)continue;add_filter('get_user_option_closedpostboxes_'.$type,static function($result)use($type){return WUTM_Postbox_Manager::apply_defaults($result,$type);});}}
    static function apply_defaults($result,$type){
        $screen=get_current_screen();$uid=get_current_user_id();
        if($uid&&$screen&&$screen->base==='post'&&$screen->post_type===$type){
            $state=(array)(self::settings()['state'][$type]??[]);$revision=hash('sha256',wp_json_encode($state));$key='wutm_pbm_revision_'.$type;
            if(get_user_option($key,$uid)!==$revision){
                $result=is_array($result)?$result:[];
                foreach($state as $id=>$mode)if(self::id($id)&&$mode==='closed')$result[]=$id;
                $result=array_values(array_unique($result));
                // Persist once: subsequent native expand/collapse actions remain authoritative.
                update_user_meta($uid,'closedpostboxes_'.$type,$result);
                delete_user_option($uid,'closedpostboxes_'.$type,true);
                update_user_option($uid,$key,$revision,true);
            }
        }
        return self::closed($result,$type);
    }
    static function closed($result,$type){
        $state=(array)(self::settings()['state'][$type]??[]);$closed=[];$open=[];
        foreach($state as $id=>$mode)if(self::id($id)){if($mode==='closed')$closed[]=$id;elseif($mode==='open')$open[]=$id;}
        if($result===false||$result==='')$result=$closed;
        return is_array($result)?array_values(array_diff($result,$open)):$result;
    }
    static function page(){
        if(!current_user_can('manage_options'))return;$registry=self::registry();$notice='';
        if(isset($_POST['pbm_action'])){check_admin_referer('wutm_pbm_save');
            if($_POST['pbm_action']==='save'){$new=['hide'=>[],'state'=>[]];foreach($registry as $type=>$boxes)foreach((array)$boxes as $id=>$box){if(!self::id($id))continue;if(in_array($id,(array)($_POST['hide'][$type]??[]),true))$new['hide'][$type][]=$id;$mode=$_POST['state'][$type][$id]??'';if(in_array($mode,['closed','open'],true))$new['state'][$type][$id]=$mode;}update_option('pbm_settings',$new,false);$notice='設定已儲存。';}
            elseif($_POST['pbm_action']==='reset_users'){$type=$_POST['reset_pt']??'';if(is_string($type)&&array_key_exists($type,$registry)&&(!is_multisite()||current_user_can('manage_network_options'))){global $wpdb;delete_metadata('user',0,'closedpostboxes_'.$type,'',true);delete_metadata('user',0,$wpdb->get_blog_prefix().'closedpostboxes_'.$type,'',true);$notice='已重設「'.$type.'」所有使用者的收合記錄。';}}
        }
        wutm_admin_page_open('Postbox 管理器','偵測各編輯頁的設定區塊，使用 CSS 隱藏而不移除欄位或停用儲存；可設定預設收合、強制展開及重設使用者記錄。');
        if($notice)echo '<p role="status">'.esc_html($notice).'</p>';
        echo '<p>先開啟對應的文章或商品編輯頁，再回來查看。也會在頁首補掃特定文章類型才註冊的區塊；條件式區塊的舊記錄會保留。</p><p>';
        foreach(get_post_types(['show_ui'=>true],'objects') as $type){if(in_array($type->name,['wp_block','wp_navigation','wp_template','wp_template_part','wp_global_styles'],true))continue;echo '<a class="button" href="'.esc_url(admin_url('post-new.php?post_type='.$type->name)).'">'.esc_html($type->labels->singular_name).'</a> ';}echo '</p>';
        if(!$registry){echo '<p>尚未偵測到任何 Postbox，請先進入編輯頁。</p>';wutm_admin_page_close();return;}
        $settings=self::settings();echo '<form method="post">';wp_nonce_field('wutm_pbm_save');
        foreach($registry as $type=>$boxes){$object=get_post_type_object($type);echo '<h2>'.esc_html($object?$object->labels->name:$type).'</h2><div class="wutm-ui-table-scroll"><table class="widefat striped"><thead><tr><th>標題／ID</th><th>位置</th><th>隱藏</th><th>預設狀態</th></tr></thead><tbody>';
            foreach((array)$boxes as $id=>$box){if(!self::id($id))continue;echo '<tr><td>'.esc_html($box['title']).'<br><code>'.esc_html($id).'</code></td><td>'.esc_html($box['context']).'</td><td><input type="checkbox" name="hide['.esc_attr($type).'][]" value="'.esc_attr($id).'" '.checked(in_array($id,(array)($settings['hide'][$type]??[]),true),true,false).'></td><td><select name="state['.esc_attr($type).']['.esc_attr($id).']">';foreach([''=>'不處理','closed'=>'預設收合','open'=>'強制展開'] as $value=>$label)echo '<option value="'.$value.'" '.selected($settings['state'][$type][$id]??'',$value,false).'>'.$label.'</option>';echo '</select></td></tr>';}
            echo '</tbody></table></div>';
        }
        echo '<p><button class="button button-primary" name="pbm_action" value="save">儲存設定</button></p></form><h2>重設使用者收合記錄</h2><p>收合設定變更後，各帳號下次進入編輯頁會套用一次，之後可自行展開。強制展開會持續生效。重設會清除所選文章類型所有使用者的收合偏好，其他類型與其他偏好保留。多站網路可能共用此記錄，因此僅網路管理員可重設。</p>';
        if(!is_multisite()||current_user_can('manage_network_options')){echo '<form method="post" onsubmit="return confirm(\'確定重設所選文章類型的所有使用者收合記錄？多站網路可能共用此記錄。\')">';wp_nonce_field('wutm_pbm_save');echo '<select name="reset_pt">';foreach($registry as $type=>$boxes)echo '<option value="'.esc_attr($type).'">'.esc_html($type).'</option>';echo '</select> <button class="button" name="pbm_action" value="reset_users">重設使用者記錄</button></form>';}
        wutm_admin_page_close();
    }
}
WUTM_Postbox_Manager::init();
