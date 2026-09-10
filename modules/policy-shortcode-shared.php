<?php
defined('ABSPATH') || exit;

if (!function_exists('wutm_policy_defaults')) {
    function wutm_policy_defaults(string $type): array {
        $sets = [
            'faq' => ['title'=>'常見問題','menu_title'=>'常見問題設定','shortcode'=>'wutm_faq','intro'=>'','faq_behavior'=>'accordion','items'=>[
                ['title'=>'出貨時間多久？','content'=>'訂單成立後將依網站標示的工作天安排出貨。'],
                ['title'=>'如何查詢訂單狀態？','content'=>'登入會員中心即可查看訂單紀錄與最新狀態。'],
            ]],
            'refund' => ['title'=>'退換貨政策','menu_title'=>'退換貨政策','shortcode'=>'wutm_refund_policy','intro'=>'','items'=>[
                ['title'=>'申請期限','content'=>'如需退換貨，請於收到商品後七日內與客服聯繫。'],
                ['title'=>'商品狀態','content'=>'商品須保持完整、未使用，並保留包裝、配件及贈品。'],
            ]],
            'privacy' => ['title'=>'隱私權政策','menu_title'=>'隱私權政策','shortcode'=>'wutm_privacy_policy','content'=>'<p>歡迎光臨「網站名稱」（以下簡稱本網站）。為保障您使用本網站服務時的權益，特此說明本站蒐集、處理及利用個人資料之方式：</p><ul><li>資料蒐集範圍：訂購商品時，本站會蒐集收件人姓名、電話、地址與付款方式。</li><li>資料使用目的：僅用於處理訂單、出貨聯繫及客服回覆，不會作其他用途。</li><li>資料保護：本站採取合理安全措施防止個人資料被竊取、竄改或洩漏。</li><li>Cookie 使用：本站使用 Cookie 以優化瀏覽體驗與分析網站流量。</li><li>您的權利：您可隨時要求查詢、更正或刪除您的個人資料，請透過客服信箱或 IG 私訊聯繫。</li><li>政策修訂：本站得隨時修改本政策內容，修改後將公告於網站上。</li></ul>','intro'=>'','items'=>[]],
        ];
        return $sets[$type] ?? $sets['faq'];
    }

    function wutm_policy_option_name(string $type): string { return 'wutm_' . $type . '_shortcode_options'; }
    function wutm_policy_options(string $type): array { return wp_parse_args((array)get_option(wutm_policy_option_name($type), []), array_merge(wutm_policy_defaults($type),['max_width'=>'1200px'])); }
    function wutm_policy_size($value): string { $value=trim((string)$value);return preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|vw|%)$/',$value)?$value:'1200px'; }
    function wutm_policy_sanitize($input): array {
        $input=is_array($input)?$input:[];
        $behavior=($input['faq_behavior']??'accordion')==='multiple'?'multiple':'accordion';
        $clean=['intro'=>wp_kses_post($input['intro']??''),'content'=>wp_kses_post($input['content']??''),'faq_behavior'=>$behavior,'max_width'=>wutm_policy_size($input['max_width']??''),'items'=>[]];
        foreach((array)($input['items']??[]) as $item){
            $title=sanitize_text_field($item['title']??'');$intro=sanitize_textarea_field($item['intro']??'');$content=wp_kses_post($item['content']??'');
            if($title===''&&trim(wp_strip_all_tags($content))==='')continue;
            $clean['items'][]=['title'=>$title,'intro'=>$intro,'content'=>$content];
        }
        return $clean;
    }

    function wutm_register_policy_module(string $type,string $page_slug):void{
        $defaults=wutm_policy_defaults($type);
        add_action('admin_init',function()use($type):void{register_setting('wutm_'.$type.'_policy_group',wutm_policy_option_name($type),['type'=>'array','sanitize_callback'=>'wutm_policy_sanitize','default'=>wutm_policy_defaults($type)]);});
        add_action('admin_menu',function()use($type,$page_slug,$defaults):void{add_submenu_page('wu-toolbox-modular',$defaults['title'].'設定',$defaults['menu_title'],'manage_options',$page_slug,function()use($type):void{wutm_render_policy_admin($type);});});
        add_action('admin_enqueue_scripts',function()use($page_slug):void{if(sanitize_key(wp_unslash($_GET['page']??''))===$page_slug)wp_enqueue_editor();});
        add_shortcode($defaults['shortcode'],function()use($type):string{return wutm_render_policy_shortcode($type);});
    }

    function wutm_policy_editor(string $content,string $id,string $name):void{
        wp_editor($content,$id,['textarea_name'=>$name,'textarea_rows'=>6,'media_buttons'=>false,'teeny'=>true,'quicktags'=>true]);
    }

    function wutm_render_policy_admin(string $type):void{
        if(!current_user_can('manage_options'))return;
        $defaults=wutm_policy_defaults($type);$options=wutm_policy_options($type);$option=wutm_policy_option_name($type);?>
        <div class="wrap wutm-policy-admin"><style>.wutm-policy-admin{max-width:1040px}.wutm-policy-admin .wutm-module-subtitle{margin:0 0 22px}.wutm-policy-code{display:inline-block;margin-left:8px;padding:5px 10px;border:1px solid #9ec5e6;border-radius:6px;background:#f0f7fc;color:#135e96;cursor:pointer}.wutm-policy-panel{margin:18px 0;padding:20px 22px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.wutm-policy-panel>h2{margin:0 0 18px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;font-size:17px}.wutm-policy-row{margin:14px 0;padding:18px;border:1px solid #e1e5ea;border-radius:8px;background:#f7f8fa}.wutm-policy-field{margin-bottom:16px}.wutm-policy-field>label{display:block;margin-bottom:7px;font-weight:600}.wutm-policy-field input,.wutm-policy-field textarea{width:100%}.wutm-policy-remove{color:#b32d2e!important;border-color:#e6a5a5!important}</style>
        <h1><?php echo esc_html($defaults['title']);?>設定</h1><p class="wutm-module-subtitle">設定完成後，將短代碼放入文章或頁面。<button type="button" class="wutm-policy-code" data-copy="[<?php echo esc_attr($defaults['shortcode']);?>]">[<?php echo esc_html($defaults['shortcode']);?>]　點擊複製</button></p>
        <form method="post" action="options.php"><?php settings_fields('wutm_'.$type.'_policy_group');?><section class="wutm-policy-panel"><h2>版面寬度</h2><label class="wutm-policy-field">最大寬度<input type="text" name="<?php echo esc_attr($option);?>[max_width]" value="<?php echo esc_attr($options['max_width']);?>" placeholder="1200px"></label><p class="description">區塊不會超過所在頁面；可輸入 1200px、60rem 或 100%。手機版會自動保留左右間距。</p></section>
        <?php if($type==='privacy'):?><section class="wutm-policy-panel"><h2>隱私權政策內容</h2><p>此處為前台顯示的完整內容，可直接編輯段落與清單。</p><?php wutm_policy_editor($options['content'],'wutm_privacy_content',$option.'[content]');?></section><?php else:?>
        <?php if($type==='faq'):?><section class="wutm-policy-panel"><h2>展開方式</h2><label><input type="radio" name="<?php echo esc_attr($option);?>[faq_behavior]" value="accordion" <?php checked($options['faq_behavior'],'accordion');?>> 一次只展開一個，開啟其他問題時自動收合</label><br><label><input type="radio" name="<?php echo esc_attr($option);?>[faq_behavior]" value="multiple" <?php checked($options['faq_behavior'],'multiple');?>> 允許同時展開多個問題</label><p class="description">兩種模式都會預設展開第一個問答。</p></section><?php endif;?>
        <section class="wutm-policy-panel"><h2><?php echo $type==='faq'?'問題與回答':'退換貨內容';?></h2><div class="wutm-policy-items">
        <?php foreach((array)$options['items'] as $i=>$item):?><div class="wutm-policy-row"><div class="wutm-policy-field"><label><?php echo $type==='faq'?'問題':'標題';?></label><input type="text" name="<?php echo esc_attr($option);?>[items][<?php echo (int)$i;?>][title]" value="<?php echo esc_attr($item['title']);?>"></div><div class="wutm-policy-field"><label><?php echo $type==='faq'?'回答內容':'內容';?></label><?php wutm_policy_editor($item['content'],'wutm_'.$type.'_content_'.$i,$option.'[items]['.$i.'][content]');?></div><button type="button" class="button wutm-policy-remove">刪除</button></div><?php endforeach;?></div><button type="button" class="button wutm-policy-add">＋ 新增項目</button></section><?php endif;?><?php submit_button('儲存設定');?></form>
        <?php if($type!=='privacy'):?><template class="wutm-policy-template"><div class="wutm-policy-row"><div class="wutm-policy-field"><label><?php echo $type==='faq'?'問題':'標題';?></label><input type="text" data-name="title"></div><div class="wutm-policy-field"><label><?php echo $type==='faq'?'回答內容':'內容';?></label><textarea rows="6" data-name="content"></textarea></div><button type="button" class="button wutm-policy-remove">刪除</button></div></template><?php endif;?></div>
        <script>(function(){const root=document.querySelector('.wutm-policy-admin');if(!root)return;root.addEventListener('click',function(e){const copy=e.target.closest('[data-copy]');if(copy&&navigator.clipboard){navigator.clipboard.writeText(copy.dataset.copy);copy.textContent='已複製：'+copy.dataset.copy;}const remove=e.target.closest('.wutm-policy-remove');if(remove){remove.closest('.wutm-policy-row').remove();return;}const add=e.target.closest('.wutm-policy-add');if(!add)return;const row=root.querySelector('.wutm-policy-template').content.firstElementChild.cloneNode(true),i=Date.now(),base='<?php echo esc_js($option);?>[items]['+i+']';row.querySelector('[data-name=title]').name=base+'[title]';const content=row.querySelector('[data-name=content]'),id='wutm_<?php echo esc_js($type);?>_content_'+i;content.name=base+'[content]';content.id=id;root.querySelector('.wutm-policy-items').appendChild(row);if(window.wp&&wp.editor)wp.editor.initialize(id,{tinymce:{wpautop:true,toolbar1:'bold,italic,bullist,numlist,link,unlink,undo,redo'},quicktags:true,mediaButtons:false});});})();</script><?php
    }

    function wutm_render_policy_shortcode(string $type):string{
        $o=wutm_policy_options($type);$uid=wp_unique_id('wutm-policy-');ob_start();?><div id="<?php echo esc_attr($uid);?>" class="wutm-policy wutm-policy-<?php echo esc_attr($type);?>" data-faq-behavior="<?php echo esc_attr($o['faq_behavior']??'accordion');?>"><?php if($type==='privacy'):?><div class="wutm-policy-single-content"><?php echo wp_kses_post($o['content']);?></div><?php else:?><?php foreach((array)$o['items'] as $index=>$item):if($type==='faq'):?><details class="wutm-faq-item" <?php echo $index===0?'open':'';?>><summary><span><?php echo esc_html($item['title']);?></span><b aria-hidden="true">＋</b></summary><div class="wutm-policy-content"><?php echo wpautop(wp_kses_post($item['content']));?></div></details><?php else:?><article class="wutm-policy-card"><h3><?php echo esc_html($item['title']);?></h3><div class="wutm-policy-content"><?php echo wpautop(wp_kses_post($item['content']));?></div></article><?php endif;endforeach;?><?php endif;?></div>
        <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($uid);?>);if(!root||root.dataset.faqBehavior!=='accordion')return;root.querySelectorAll('.wutm-faq-item').forEach(function(item){item.addEventListener('toggle',function(){if(!item.open)return;root.querySelectorAll('.wutm-faq-item[open]').forEach(function(other){if(other!==item)other.open=false;});});});})();</script>
        <style>#<?php echo esc_attr($uid);?>{width:100%!important;max-width:<?php echo esc_attr($o['max_width']);?>!important;padding-left:16px!important;padding-right:16px!important}</style>
        <style>#<?php echo esc_attr($uid);?> .wutm-policy-single-content{padding:20px;border:1px solid #e2e4e7;border-radius:8px;background:#fff}#<?php echo esc_attr($uid);?> .wutm-policy-single-content ul{margin:16px 0;padding-left:24px}#<?php echo esc_attr($uid);?> .wutm-policy-single-content li{margin-bottom:10px}</style>
        <style>#<?php echo esc_attr($uid);?>{box-sizing:border-box;width:100%;max-width:var(--theme-normal-container-max-width,1200px);margin:24px auto;color:#111;font-family:inherit;line-height:1.75}#<?php echo esc_attr($uid);?> *{box-sizing:border-box}#<?php echo esc_attr($uid);?> .wutm-policy-intro,#<?php echo esc_attr($uid);?> .wutm-policy-card,#<?php echo esc_attr($uid);?> .wutm-faq-item{margin:0 0 14px;border:1px solid #e2e4e7;border-radius:8px;background:#fff;box-shadow:none;overflow:hidden}#<?php echo esc_attr($uid);?> .wutm-policy-intro,#<?php echo esc_attr($uid);?> .wutm-policy-card{padding:20px}#<?php echo esc_attr($uid);?> h3{margin:0 0 8px;color:#111;font-size:18px}#<?php echo esc_attr($uid);?> p{margin:0 0 8px}#<?php echo esc_attr($uid);?> .wutm-policy-lead{color:#50575e;font-weight:600}#<?php echo esc_attr($uid);?> .wutm-faq-item summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 20px;color:#111;font-weight:700;cursor:pointer;list-style:none}#<?php echo esc_attr($uid);?> .wutm-faq-item summary::-webkit-details-marker{display:none}#<?php echo esc_attr($uid);?> .wutm-faq-item summary b{flex:none;font-size:20px;font-weight:400}#<?php echo esc_attr($uid);?> .wutm-faq-item[open] summary b::after{content:'−';font-size:23px}#<?php echo esc_attr($uid);?> .wutm-faq-item[open] summary b{font-size:0}#<?php echo esc_attr($uid);?> .wutm-faq-item .wutm-policy-content{padding:17px 20px;border-top:1px solid #e2e4e7;background:#f7f7f7}#<?php echo esc_attr($uid);?> .wutm-policy-card .wutm-policy-content{margin-top:6px}@media(max-width:782px){#<?php echo esc_attr($uid);?>{margin:18px auto}#<?php echo esc_attr($uid);?> .wutm-policy-intro,#<?php echo esc_attr($uid);?> .wutm-policy-card,#<?php echo esc_attr($uid);?> .wutm-faq-item summary,#<?php echo esc_attr($uid);?> .wutm-faq-item .wutm-policy-content{padding:15px}}</style><?php return ob_get_clean();
    }

    add_shortcode('shop_policy',function($atts):string{$atts=shortcode_atts(['type'=>'faq'],$atts,'shop_policy');$type=sanitize_key((string)$atts['type']);$keys=['faq'=>'faq-shortcode','refund'=>'refund-policy','privacy'=>'privacy-policy'];return isset($keys[$type])&&wutm_is_enabled($keys[$type])?wutm_render_policy_shortcode($type):'';});
}
