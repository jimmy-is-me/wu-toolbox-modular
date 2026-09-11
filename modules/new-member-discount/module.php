<?php
defined('ABSPATH') || exit;

const WUTM_NMD_USED = '_wutm_new_member_discount_order';
const WUTM_NMD_LOG = '_wutm_new_member_discount_log';
const WUTM_NMD_ORDER_DISCOUNT = '_wutm_new_member_discount_amount';
const WUTM_NMD_RESET_ALLOWED = '_wutm_new_member_discount_reset_allowed';
const WUTM_NMD_ORDER_RESET = '_wutm_new_member_discount_from_reset';

function wutm_nmd_defaults(): array { return ['enabled'=>1,'minimum'=>500,'discount'=>100,'notice'=>1]; }
function wutm_nmd_options(): array { return wp_parse_args((array)get_option('wutm_new_member_discount',[]),wutm_nmd_defaults()); }
function wutm_nmd_sanitize($input): array {
    $input=is_array($input)?$input:[];
    return ['enabled'=>empty($input['enabled'])?0:1,'minimum'=>max(0,(float)($input['minimum']??500)),'discount'=>max(0,(float)($input['discount']??100)),'notice'=>empty($input['notice'])?0:1];
}
function wutm_nmd_eligible(int $user_id): bool {
    if(!$user_id||metadata_exists('user',$user_id,WUTM_NMD_USED))return false;
    if(metadata_exists('user',$user_id,WUTM_NMD_RESET_ALLOWED))return true;
    return empty(wc_get_orders(['customer_id'=>$user_id,'status'=>['processing','completed'],'limit'=>1,'return'=>'ids']));
}
function wutm_nmd_fee_name(array $o): string { return sprintf("新會員優惠\n（滿 %s 折 %s）",wp_strip_all_tags(wc_price($o['minimum'])),wp_strip_all_tags(wc_price($o['discount']))); }
function wutm_nmd_log_entries($value):array{
    if(is_string($value)){$decoded=json_decode($value,true);if(is_array($decoded))$value=$decoded;}
    if(!is_array($value))return [];
    if(isset($value['order_id']))$value=[$value];
    return array_values(array_filter($value,'is_array'));
}

add_action('woocommerce_cart_calculate_fees',function($cart):void{
    if((is_admin()&&!wp_doing_ajax())||!is_user_logged_in())return;
    $o=wutm_nmd_options();
    if(!$o['enabled']||$o['discount']<=0||$cart->get_subtotal()<$o['minimum']||!wutm_nmd_eligible(get_current_user_id()))return;
    $cart->add_fee(wutm_nmd_fee_name($o),-min($o['discount'],$cart->get_subtotal()),false);
});

function wutm_nmd_capture_order($order,bool $backfill=false):void{
    if(!($order instanceof WC_Order))return;
    $uid=(int)$order->get_user_id();if(!$uid)return;
    $discount=0.0;$discount_fee=null;
    foreach($order->get_fees() as $fee){if(strpos($fee->get_name(),'新會員優惠')===0){$discount=abs((float)$fee->get_total());$discount_fee=$fee;break;}}
    if($discount<=0)return;
    $from_manual_reset=metadata_exists('user',$uid,WUTM_NMD_RESET_ALLOWED);
    $locked=(int)get_user_meta($uid,WUTM_NMD_USED,true);
    if(!$backfill&&$locked&&$locked!==(int)$order->get_id()){
        if($discount_fee){$order->remove_item($discount_fee->get_id());$order->calculate_totals();$order->save();}
        return;
    }
    if(!$locked&&!in_array($order->get_status(),['cancelled','refunded','failed'],true)){
        // 唯一 user meta 是訂單建立當下的原子鎖，避免同一會員同時送出兩張優惠訂單。
        if(!add_user_meta($uid,WUTM_NMD_USED,(int)$order->get_id(),true)){
            $locked=(int)get_user_meta($uid,WUTM_NMD_USED,true);
            if(!$backfill&&$locked!==(int)$order->get_id()&&$discount_fee){$order->remove_item($discount_fee->get_id());$order->calculate_totals();$order->save();return;}
        }
    }
    if(!$backfill&&$from_manual_reset){delete_user_meta($uid,WUTM_NMD_RESET_ALLOWED);$order->update_meta_data(WUTM_NMD_ORDER_RESET,1);}
    if((float)$order->get_meta(WUTM_NMD_ORDER_DISCOUNT)!==$discount){$order->update_meta_data(WUTM_NMD_ORDER_DISCOUNT,$discount);$order->save_meta_data();}
    $log=wutm_nmd_log_entries(get_user_meta($uid,WUTM_NMD_LOG,true));
    foreach($log as $entry){if((int)($entry['order_id']??0)===(int)$order->get_id())return;}
    $created=$order->get_date_created();
    $log[]=['order_id'=>(int)$order->get_id(),'date'=>$created?$created->date('Y-m-d H:i:s'):current_time('mysql'),'status'=>$order->get_status(),'total'=>(float)$order->get_total(),'discount'=>$discount];
    update_user_meta($uid,WUTM_NMD_LOG,$log);
}
add_action('woocommerce_checkout_order_created','wutm_nmd_capture_order',10,1);
add_action('woocommerce_store_api_checkout_order_processed','wutm_nmd_capture_order',10,1);

function wutm_nmd_backfill_records():void{
    global $wpdb;
    $like=$wpdb->esc_like('新會員優惠').'%';
    $sql=$wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'fee' AND order_item_name LIKE %s ORDER BY order_item_id DESC LIMIT 1000",$like);
    foreach(array_map('absint',(array)$wpdb->get_col($sql)) as $order_id){$order=wc_get_order($order_id);if($order)wutm_nmd_capture_order($order,true);}
}

add_action('woocommerce_order_status_changed',function($order_id,$old,$new):void{
    $order=wc_get_order($order_id);if(!$order)return;$uid=(int)$order->get_user_id();if(!$uid)return;
    $log=wutm_nmd_log_entries(get_user_meta($uid,WUTM_NMD_LOG,true));$found=false;
    foreach($log as &$entry){if((int)($entry['order_id']??0)!==(int)$order_id)continue;$entry['status']=$new;$entry['total']=(float)$order->get_total();$found=true;}unset($entry);
    if($found)update_user_meta($uid,WUTM_NMD_LOG,$log);
    $locked=(int)get_user_meta($uid,WUTM_NMD_USED,true);
    if($locked===(int)$order_id&&in_array($new,['cancelled','refunded','failed'],true)){
        delete_user_meta($uid,WUTM_NMD_USED,(string)$order_id);
        if($order->get_meta(WUTM_NMD_ORDER_RESET))update_user_meta($uid,WUTM_NMD_RESET_ALLOWED,1);
    }
},10,3);

add_filter('woocommerce_cart_totals_fee_html',function($html,$fee){return $fee->amount<0&&strpos($fee->name,'新會員優惠')===0?'<span style="color:#18794e">'.$html.'</span>':$html;},10,2);
add_action('wp_enqueue_scripts',function():void{
    if(!function_exists('is_cart')||(!is_cart()&&!is_checkout()))return;
    wp_register_style('wutm-new-member-discount',false,[],WUTM_VERSION);
    wp_enqueue_style('wutm-new-member-discount');
    wp_add_inline_style('wutm-new-member-discount','.woocommerce tr.fee th,.wc-block-components-totals-fees__name{white-space:pre-line}');
});
function wutm_nmd_notice():void{
    $o=wutm_nmd_options();if(!$o['enabled']||!$o['notice'])return;if(is_user_logged_in()&&!wutm_nmd_eligible(get_current_user_id()))return;
    echo '<div class="wutm-nmd-notice" style="margin:15px 0;padding:12px 16px;border:1px solid #d6e7dc;border-radius:7px;background:#f4faf6;color:#185c37">🎉 <strong>新會員優惠：</strong>首次下單滿 '.wp_kses_post(wc_price($o['minimum'])).'，自動折抵 '.wp_kses_post(wc_price($o['discount'])).'。'.(!is_user_logged_in()?'登入會員後即可套用。':'').'</div>';
}
add_action('woocommerce_single_product_summary','wutm_nmd_notice',25);
add_action('woocommerce_before_checkout_form','wutm_nmd_notice',8);

add_action('admin_init',function():void{register_setting('wutm_nmd_group','wutm_new_member_discount',['type'=>'array','sanitize_callback'=>'wutm_nmd_sanitize','default'=>wutm_nmd_defaults()]);});
add_action('admin_menu',function():void{add_submenu_page('wu-toolbox-modular','新會員優惠','新會員優惠','manage_woocommerce','wu-new-member-discount','wutm_nmd_admin_page');});
add_action('admin_post_wutm_nmd_reset_member',function():void{
    if(!current_user_can('manage_woocommerce'))wp_die('權限不足。');
    $uid=absint($_POST['user_id']??0);check_admin_referer('wutm_nmd_reset_member_'.$uid);if(!$uid||!get_userdata($uid))wp_die('找不到會員。');
    // 保留歷史紀錄，只解除目前資格鎖，方便後續稽核多次使用狀況。
    delete_user_meta($uid,WUTM_NMD_USED);update_user_meta($uid,WUTM_NMD_RESET_ALLOWED,1);
    wp_safe_redirect(add_query_arg(['page'=>'wu-new-member-discount','tab'=>'records','wutm_nmd_notice'=>'reset'],admin_url('admin.php')));exit;
});

function wutm_nmd_admin_url(string $tab,array $args=[]):string{return add_query_arg(array_merge(['page'=>'wu-new-member-discount','tab'=>$tab],$args),admin_url('admin.php'));}
function wutm_nmd_admin_page():void{
    if(!current_user_can('manage_woocommerce'))return;
    $tab=sanitize_key(wp_unslash($_GET['tab']??'settings'));if(!in_array($tab,['settings','records'],true))$tab='settings';?>
    <div class="wrap wutm-nmd-admin"><h1>新會員優惠</h1><p class="wutm-module-subtitle">會員首次消費達指定金額時自動折抵；取消、退款或付款失敗會自動恢復資格。</p>
    <nav class="nav-tab-wrapper"><a class="nav-tab <?php echo $tab==='settings'?'nav-tab-active':'';?>" href="<?php echo esc_url(wutm_nmd_admin_url('settings'));?>">優惠設定</a><a class="nav-tab <?php echo $tab==='records'?'nav-tab-active':'';?>" href="<?php echo esc_url(wutm_nmd_admin_url('records'));?>">使用紀錄</a></nav>
    <?php if(sanitize_key(wp_unslash($_GET['wutm_nmd_notice']??''))==='reset'):?><div class="notice notice-success is-dismissible"><p>已恢復該會員的新會員優惠資格，歷史使用紀錄仍完整保留。</p></div><?php endif;?>
    <?php
    if ($tab === 'records') {
        wutm_nmd_render_records();
    } else {
        wutm_nmd_render_settings();
    }
    ?>
    </div>
    <style>.wutm-nmd-admin{max-width:1400px}.wutm-nmd-admin .nav-tab-wrapper{margin-bottom:20px}.wutm-nmd-panel{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:22px;margin-top:20px}.wutm-nmd-panel h2{margin-top:0}.wutm-nmd-guide{border-left:4px solid #2271b1}.wutm-nmd-stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px;margin:20px 0}.wutm-nmd-stat{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:18px;text-align:center}.wutm-nmd-stat strong{display:block;font-size:28px;color:#2271b1}.wutm-nmd-stat:nth-child(2) strong{color:#2e8b57}.wutm-nmd-stat:nth-child(3) strong{color:#d97706}.wutm-nmd-stat:nth-child(4) strong{color:#d63638}.wutm-nmd-badge{display:inline-block;padding:3px 9px;border-radius:999px;background:#f0f0f1;color:#646970}.wutm-nmd-badge-used{background:#edfaef;color:#176b35}.wutm-nmd-form-table input[type=number]{width:180px}.wutm-nmd-actions{display:flex;gap:6px}@media(max-width:782px){.wutm-nmd-stats{grid-template-columns:repeat(2,1fr)}.wutm-nmd-admin .widefat{display:block;overflow-x:auto}}@media(max-width:480px){.wutm-nmd-stats{grid-template-columns:1fr}}</style><?php
}

function wutm_nmd_render_settings():void{$o=wutm_nmd_options();?>
    <section class="wutm-nmd-panel wutm-nmd-guide"><h2>設定說明</h2><p>優惠只提供給已登入、沒有成功購買紀錄且尚未使用此優惠的會員。折扣會在訂單建立時鎖定，避免同時重複下單。</p></section>
    <section class="wutm-nmd-panel"><h2>滿額折扣設定</h2><form method="post" action="options.php"><?php settings_fields('wutm_nmd_group');?><table class="form-table wutm-nmd-form-table" role="presentation">
    <tr><th scope="row">啟用優惠</th><td><label><input type="checkbox" name="wutm_new_member_discount[enabled]" value="1" <?php checked($o['enabled']);?>> 啟用新會員首次消費折扣</label><p class="description">關閉後不會套用折扣，也不顯示前台活動提示。</p></td></tr>
    <tr><th scope="row">最低消費金額</th><td><input type="number" min="0" step="1" name="wutm_new_member_discount[minimum]" value="<?php echo esc_attr($o['minimum']);?>"><p class="description">購物車商品小計達到此金額後才會自動折抵。</p></td></tr>
    <tr><th scope="row">折扣金額</th><td><input type="number" min="0" step="1" name="wutm_new_member_discount[discount]" value="<?php echo esc_attr($o['discount']);?>"><p class="description">實際折扣不會超過購物車商品小計。</p></td></tr>
    <tr><th scope="row">前台活動提示</th><td><label><input type="checkbox" name="wutm_new_member_discount[notice]" value="1" <?php checked($o['notice']);?>> 在商品頁與結帳頁顯示</label><p class="description">已使用優惠或已有成功訂單的會員不會看到提示。</p></td></tr></table><?php submit_button('儲存設定');?></form></section><?php
}

function wutm_nmd_status_label(string $status):string{$labels=['pending'=>'待付款','processing'=>'處理中','on-hold'=>'保留','completed'=>'已完成','cancelled'=>'已取消','refunded'=>'已退款','failed'=>'失敗'];return $labels[$status]??wc_get_order_status_name($status);}
function wutm_nmd_reset_form(int $uid):void{?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('確定恢復此會員的優惠資格？歷史使用紀錄會保留。')"><input type="hidden" name="action" value="wutm_nmd_reset_member"><input type="hidden" name="user_id" value="<?php echo absint($uid);?>"><?php wp_nonce_field('wutm_nmd_reset_member_'.$uid);?><button type="submit" class="button button-small">恢復資格</button></form><?php
}

function wutm_nmd_render_records():void{
    wutm_nmd_backfill_records();
    $locked_ids=array_map('intval',get_users(['meta_key'=>WUTM_NMD_USED,'number'=>-1,'fields'=>'ids']));
    $log_users=get_users(['meta_key'=>WUTM_NMD_LOG,'number'=>-1,'fields'=>['ID','user_login','user_email']]);$records=[];$total_discount=0.0;$excluded=['cancelled','refunded','failed'];
    $used_member_ids=[];
    foreach($log_users as $user){foreach(wutm_nmd_log_entries(get_user_meta($user->ID,WUTM_NMD_LOG,true)) as $entry){$entry['user_id']=(int)$user->ID;$entry['user_login']=$user->user_login;$entry['user_email']=$user->user_email;$entry['date']=(string)($entry['date']??$entry['order_date']??'');$entry['status']=(string)($entry['status']??'');$records[]=$entry;$used_member_ids[(int)$user->ID]=true;if(!in_array($entry['status'],$excluded,true))$total_discount+=(float)($entry['discount']??0);}}
    usort($records,static function(array $a,array $b):int{return strcmp((string)($b['date']??''),(string)($a['date']??''));});
    $page=max(1,absint($_GET['member_page']??1));$per_page=30;$query=new WP_User_Query(['number'=>$per_page,'offset'=>($page-1)*$per_page,'orderby'=>'registered','order'=>'DESC','fields'=>['ID','user_login','user_email','user_registered'],'count_total'=>true]);$members=$query->get_results();$total_members=(int)$query->get_total();$total_pages=max(1,(int)ceil($total_members/$per_page));?>
    <section class="wutm-nmd-panel wutm-nmd-guide"><h2>功能說明</h2><ul><li>訂單建立當下即鎖定資格，避免同一會員短時間重複使用。</li><li>訂單取消、退款或失敗時會自動恢復資格，歷史紀錄仍會保留。</li><li>「恢復資格」可供客服人工允許會員再次使用，不會刪除原有紀錄。</li><li>下方會員列表可查看所有會員目前是否仍被鎖定及歷史使用次數。</li></ul></section>
    <div class="wutm-nmd-stats"><div class="wutm-nmd-stat"><strong><?php echo number_format_i18n($total_members);?></strong>總會員數</div><div class="wutm-nmd-stat"><strong><?php echo number_format_i18n(count($used_member_ids));?></strong>曾使用優惠</div><div class="wutm-nmd-stat"><strong><?php echo number_format_i18n(max(0,$total_members-count($used_member_ids)));?></strong>尚無使用紀錄</div><div class="wutm-nmd-stat"><strong><?php echo wp_kses_post(wc_price($total_discount));?></strong>有效折扣總額</div></div>
    <section class="wutm-nmd-panel"><h2>優惠訂單紀錄</h2><p class="description">依使用時間由新到舊排列，最多顯示最近 200 筆。</p><table class="widefat striped"><thead><tr><th>會員</th><th>訂單</th><th>使用時間</th><th>狀態</th><th>訂單金額</th><th>折扣金額</th></tr></thead><tbody>
    <?php if(!$records):?><tr><td colspan="6">目前尚無使用紀錄。</td></tr><?php endif;foreach(array_slice($records,0,200) as $record):$order_id=absint($record['order_id']??0);$order=$order_id?wc_get_order($order_id):false;$order_url=$order&&method_exists($order,'get_edit_order_url')?$order->get_edit_order_url():'';$total=isset($record['total'])?(float)$record['total']:($order?(float)$order->get_total():0);?>
    <tr><td><strong><?php echo esc_html($record['user_login']);?></strong><br><?php echo esc_html($record['user_email']);?></td><td><?php if($order_url):?><a href="<?php echo esc_url($order_url);?>">#<?php echo absint($order_id);?></a><?php else:?>#<?php echo absint($order_id);?><?php endif;?></td><td><?php echo esc_html($record['date']);?></td><td><?php echo esc_html(wutm_nmd_status_label($record['status']));?></td><td><?php echo wp_kses_post(wc_price($total));?></td><td>-<?php echo wp_kses_post(wc_price((float)($record['discount']??0)));?></td></tr><?php endforeach;?></tbody></table></section>
    <section class="wutm-nmd-panel"><h2>所有會員（共 <?php echo number_format_i18n($total_members);?> 人）</h2><table class="widefat striped"><thead><tr><th>會員帳號</th><th>Email</th><th>註冊時間</th><th>目前資格</th><th>歷史使用次數</th><th>操作</th></tr></thead><tbody>
    <?php if(!$members):?><tr><td colspan="6">目前沒有會員。</td></tr><?php endif;foreach($members as $member):$is_locked=in_array((int)$member->ID,$locked_ids,true);$is_reset=metadata_exists('user',$member->ID,WUTM_NMD_RESET_ALLOWED);$member_log=wutm_nmd_log_entries(get_user_meta($member->ID,WUTM_NMD_LOG,true));$qualification=$is_locked?'已使用／鎖定':($is_reset?'已人工恢復':'未鎖定');?>
    <tr><td><strong><?php echo esc_html($member->user_login);?></strong></td><td><?php echo esc_html($member->user_email);?></td><td><?php echo esc_html(date_i18n('Y-m-d H:i',strtotime($member->user_registered)));?></td><td><span class="wutm-nmd-badge <?php echo $is_locked?'wutm-nmd-badge-used':'';?>"><?php echo esc_html($qualification);?></span></td><td><?php echo number_format_i18n(count($member_log));?></td><td><div class="wutm-nmd-actions"><?php if($is_locked)wutm_nmd_reset_form((int)$member->ID);else echo '—';?></div></td></tr><?php endforeach;?></tbody></table>
    <?php if($total_pages>1):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(['base'=>add_query_arg('member_page','%#%',wutm_nmd_admin_url('records')),'format'=>'','current'=>min($page,$total_pages),'total'=>$total_pages]));?></div></div><?php endif;?></section><?php
}
