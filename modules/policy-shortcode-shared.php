<?php
defined('ABSPATH') || exit;

if (!function_exists('wutm_policy_defaults')) {
    function wutm_policy_defaults(string $type): array {
        $sets = [
            'faq' => [
                'title' => '常見問題', 'shortcode' => 'wutm_faq', 'primary' => '#111111', 'accent' => '#111111', 'text_color' => '#111111', 'width' => '100%',
                'intro' => '',
                'items' => [
                    ['title' => '出貨時間多久？', 'content' => '訂單成立後將依網站標示的工作天安排出貨。'],
                    ['title' => '如何查詢訂單狀態？', 'content' => '登入會員中心即可查看訂單紀錄與最新狀態。'],
                ],
            ],
            'refund' => [
                'title' => '退換貨政策', 'shortcode' => 'wutm_refund_policy', 'primary' => '#111111', 'accent' => '#111111', 'text_color' => '#111111', 'width' => '100%',
                'intro' => '',
                'items' => [
                    ['title' => '申請期限', 'intro' => '申請退換貨前請先確認期限。', 'content' => '如需退換貨，請於收到商品後七日內與客服聯繫。'],
                    ['title' => '商品狀態', 'intro' => '寄回商品前請確認商品完整性。', 'content' => '商品須保持完整、未使用，並保留包裝、配件及贈品。'],
                ],
            ],
            'privacy' => [
                'title' => '隱私權政策', 'shortcode' => 'wutm_privacy_policy', 'primary' => '#111111', 'accent' => '#111111', 'text_color' => '#111111', 'width' => '100%',
                'intro' => '歡迎光臨「Unhurried」（以下簡稱本網站）。為保障您使用本網站服務時的權益，特此說明本站蒐集、處理及利用個人資料之方式：',
                'items' => [
                    ['title' => '資料蒐集範圍', 'content' => '訂購商品時，本站會蒐集收件人姓名、電話、地址與付款方式。'],
                    ['title' => '資料使用目的', 'content' => '僅用於處理訂單、出貨聯繫及客服回覆，不會作其他用途。'],
                    ['title' => '資料保護', 'content' => '本站採取合理安全措施防止個人資料被竊取、竄改或洩漏。'],
                    ['title' => 'Cookie 使用', 'content' => '本站使用 Cookie 以優化瀏覽體驗與分析網站流量。'],
                    ['title' => '您的權利', 'content' => '您可隨時要求查詢、更正或刪除您的個人資料，請透過客服信箱或 IG 私訊聯繫。'],
                    ['title' => '政策修訂', 'content' => '本站得隨時修改本政策內容，修改後將公告於網站上。'],
                ],
            ],
        ];
        return $sets[$type] ?? $sets['faq'];
    }

    function wutm_policy_option_name(string $type): string { return 'wutm_' . $type . '_shortcode_options'; }
    function wutm_policy_css_size($value): string { $value=trim((string)$value);return preg_match('/^\d+(?:\.\d+)?(?:px|%|vw|rem|em)$/',$value)?$value:'100%'; }

    function wutm_policy_options(string $type): array {
        return wp_parse_args((array) get_option(wutm_policy_option_name($type), []), wutm_policy_defaults($type));
    }

    function wutm_policy_sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $clean = [
            'primary' => sanitize_hex_color($input['primary'] ?? '') ?: '#111111',
            'accent' => sanitize_hex_color($input['accent'] ?? '') ?: '#111111',
            'text_color' => sanitize_hex_color($input['text_color'] ?? '') ?: '#111111',
            'width' => wutm_policy_css_size($input['width'] ?? ''),
            'intro' => wp_kses_post($input['intro'] ?? ''),
            'items' => [],
        ];
        foreach ((array) ($input['items'] ?? []) as $item) {
            $title = wp_kses_post($item['title'] ?? '');
            $intro = wp_kses_post($item['intro'] ?? '');
            $content = wp_kses_post($item['content'] ?? '');
            if ($title === '' && trim(wp_strip_all_tags($content)) === '') continue;
            $clean['items'][] = ['title' => $title, 'intro' => $intro, 'content' => $content];
        }
        return $clean;
    }

    function wutm_register_policy_module(string $type, string $page_slug): void {
        $defaults = wutm_policy_defaults($type);
        add_action('admin_init', function () use ($type): void {
            register_setting('wutm_' . $type . '_policy_group', wutm_policy_option_name($type), [
                'type' => 'array', 'sanitize_callback' => 'wutm_policy_sanitize', 'default' => wutm_policy_defaults($type),
            ]);
        });
        add_action('admin_menu', function () use ($type, $page_slug, $defaults): void {
            add_submenu_page('wu-toolbox-modular', $defaults['title'] . '設定', $defaults['title'], 'manage_options', $page_slug, function () use ($type): void { wutm_render_policy_admin($type); });
        });
        add_shortcode($defaults['shortcode'], function () use ($type): string { return wutm_render_policy_shortcode($type); });
    }

    function wutm_render_policy_admin(string $type): void {
        if (!current_user_can('manage_options')) return;
        $defaults = wutm_policy_defaults($type);
        $options = wutm_policy_options($type);
        $option_name = wutm_policy_option_name($type);
        ?>
        <div class="wrap wutm-policy-admin">
            <style>.wutm-policy-admin{max-width:1040px}.wutm-policy-hero{margin:18px 0;padding:24px 28px;border-radius:16px;background:linear-gradient(135deg,#0b2949,#24649a);color:#fff}.wutm-policy-hero h1{margin:0 0 8px;color:#fff}.wutm-policy-code{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border:1px solid #b7d5ee;border-radius:9px;background:#edf7ff;color:#124f7e;font:600 13px ui-monospace,monospace;cursor:pointer}.wutm-policy-panel{margin-top:18px;padding:22px 24px;border:1px solid #d9e2ec;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(20,45,75,.06)}.wutm-policy-row{display:grid;grid-template-columns:minmax(180px,1fr) minmax(280px,2fr) auto;gap:12px;margin:12px 0;padding:14px;border-radius:10px;background:#f7f9fc}.wutm-policy-row input,.wutm-policy-row textarea{width:100%}.wutm-policy-remove{color:#b32d2e!important;border-color:#e6a5a5!important}.wutm-policy-colors{display:flex;gap:28px;flex-wrap:wrap}.wutm-policy-colors label{font-weight:600}.wutm-policy-colors input{display:block;margin-top:8px;width:64px;height:38px}.wutm-policy-admin textarea[name$="[intro]"]{width:100%;max-width:800px}.wutm-policy-admin .button-primary{border-radius:8px;padding-inline:22px}@media(max-width:720px){.wutm-policy-row{grid-template-columns:1fr}}</style>
            <style>.wutm-policy-admin .wutm-policy-code{display:inline-block;margin-left:8px;padding:5px 10px;border-radius:6px}.wutm-policy-admin .wutm-policy-panel{border-color:#dcdcde;border-radius:8px;box-shadow:none}.wutm-policy-admin .wutm-policy-panel>h2{padding-bottom:10px;border-bottom:1px solid #e5e7eb}.wutm-policy-admin .wutm-policy-row{grid-template-columns:1fr;border:1px solid #e5e7eb;border-radius:8px}.wutm-policy-admin .wutm-policy-row .wp-editor-wrap{margin-top:7px}.wutm-policy-admin .wutm-policy-remove{justify-self:start}.wutm-policy-admin .wutm-module-subtitle{margin:0 0 22px}</style>
            <h1><?php echo esc_html($defaults['title']); ?>設定</h1><p class="wutm-module-subtitle">編輯內容後儲存，再將短代碼放入文章、頁面或小工具。<button type="button" class="wutm-policy-code" data-copy="[<?php echo esc_attr($defaults['shortcode']); ?>]">[<?php echo esc_html($defaults['shortcode']); ?>]　點擊複製</button></p>
            <form method="post" action="options.php">
                <?php settings_fields('wutm_' . $type . '_policy_group'); ?>
                <section class="wutm-policy-panel"><h2>外觀設定</h2><div class="wutm-policy-colors"><label>標題文字顏色<input type="color" name="<?php echo esc_attr($option_name); ?>[primary]" value="<?php echo esc_attr($options['primary']); ?>"></label><label>互動重點顏色<input type="color" name="<?php echo esc_attr($option_name); ?>[accent]" value="<?php echo esc_attr($options['accent']); ?>"></label><label>內文文字顏色<input type="color" name="<?php echo esc_attr($option_name); ?>[text_color]" value="<?php echo esc_attr($options['text_color']); ?>"></label><label>區塊寬度<input class="regular-text" name="<?php echo esc_attr($option_name); ?>[width]" value="<?php echo esc_attr($options['width']); ?>"><small>預設 100%，跟隨網站內容區。</small></label></div></section>
                <?php if ($type === 'privacy'): ?><section class="wutm-policy-panel"><h2>頁面開場說明</h2><?php wp_editor($options['intro'],'wutm_policy_intro_'.$type,['textarea_name'=>$option_name.'[intro]','textarea_rows'=>5,'media_buttons'=>false,'teeny'=>true]); ?></section><?php endif; ?>
                <section class="wutm-policy-panel"><h2><?php echo $type === 'faq' ? '問題與回答' : ($type === 'refund' ? '退換貨說明區塊' : '政策內容清單'); ?></h2><div class="wutm-policy-items">
                    <?php foreach ((array) $options['items'] as $index => $item): ?><div class="wutm-policy-row"><div><strong><?php echo $type === 'faq'?'問題':'區塊標題'; ?></strong><?php wp_editor($item['title'],'wutm_'.$type.'_title_'.$index,['textarea_name'=>$option_name.'[items]['.$index.'][title]','textarea_rows'=>2,'media_buttons'=>false,'teeny'=>true]); ?></div><?php if($type==='refund'):?><div><strong>簡短說明</strong><?php wp_editor($item['intro']??'','wutm_refund_intro_'.$index,['textarea_name'=>$option_name.'[items]['.$index.'][intro]','textarea_rows'=>3,'media_buttons'=>false,'teeny'=>true]);?></div><?php endif;?><div><strong><?php echo $type==='faq'?'回答':'詳細內容'; ?></strong><?php wp_editor($item['content'],'wutm_'.$type.'_content_'.$index,['textarea_name'=>$option_name.'[items]['.$index.'][content]','textarea_rows'=>5,'media_buttons'=>false,'teeny'=>true]); ?></div><button type="button" class="button wutm-policy-remove">刪除</button></div><?php endforeach; ?>
                </div><button type="button" class="button wutm-policy-add">＋ 新增項目</button></section>
                <?php submit_button('儲存設定'); ?>
            </form>
            <template class="wutm-policy-template"><div class="wutm-policy-row"><input type="text" data-name="title" placeholder="標題"><textarea rows="3" data-name="content" placeholder="內容"></textarea><button type="button" class="button wutm-policy-remove">刪除</button></div></template>
        </div>
        <script>(function(){const root=document.querySelector('.wutm-policy-admin');if(!root)return;root.addEventListener('click',function(e){const copy=e.target.closest('[data-copy]');if(copy&&navigator.clipboard){navigator.clipboard.writeText(copy.dataset.copy);copy.textContent='已複製：'+copy.dataset.copy;}const remove=e.target.closest('.wutm-policy-remove');if(remove)remove.closest('.wutm-policy-row').remove();const add=e.target.closest('.wutm-policy-add');if(add){const list=root.querySelector('.wutm-policy-items'),row=root.querySelector('.wutm-policy-template').content.firstElementChild.cloneNode(true),i=Date.now();row.querySelector('[data-name=title]').name='<?php echo esc_js($option_name); ?>[items]['+i+'][title]';row.querySelector('[data-name=content]').name='<?php echo esc_js($option_name); ?>[items]['+i+'][content]';list.appendChild(row);}});})();</script>
        <?php
    }

    function wutm_render_policy_shortcode(string $type): string {
        $options = wutm_policy_options($type);
        $uid = wp_unique_id('wutm-policy-');
        ob_start(); ?>
        <div id="<?php echo esc_attr($uid); ?>" class="wutm-policy wutm-policy-<?php echo esc_attr($type); ?>" style="--wutm-policy-primary:<?php echo esc_attr($options['primary']); ?>;--wutm-policy-accent:<?php echo esc_attr($options['accent']); ?>;--wutm-policy-text:<?php echo esc_attr($options['text_color']); ?>;--wutm-policy-width:<?php echo esc_attr($options['width']); ?>">
            <?php if ($type !== 'faq' && trim(wp_strip_all_tags($options['intro'])) !== ''): ?><div class="wutm-policy-intro"><?php echo wpautop(wp_kses_post($options['intro'])); ?></div><?php endif; ?>
            <?php foreach ((array) $options['items'] as $index => $item): if ($type === 'faq'): ?>
                <details class="wutm-faq-item" <?php echo $index === 0 ? 'open' : ''; ?>><summary><span><?php echo wp_kses_post($item['title']); ?></span><b aria-hidden="true">＋</b></summary><div class="wutm-policy-content"><?php echo wpautop(wp_kses_post($item['content'])); ?></div></details>
            <?php elseif ($type === 'privacy'): ?><?php if ($index === 0): ?><ul class="wutm-privacy-list"><?php endif; ?><li><strong><?php echo esc_html($item['title']); ?>：</strong> <?php echo wp_kses_post($item['content']); ?></li><?php if ($index === count($options['items']) - 1): ?></ul><?php endif; ?>
            <?php else: ?><article class="wutm-policy-card"><h3><?php echo wp_kses_post($item['title']); ?></h3><?php if(trim(wp_strip_all_tags($item['intro']??''))!==''):?><div class="wutm-policy-lead"><?php echo wpautop(wp_kses_post($item['intro']));?></div><?php endif;?><?php echo wpautop(wp_kses_post($item['content'])); ?></article><?php endif; endforeach; ?>
        </div>
        <style>#<?php echo esc_attr($uid); ?>{max-width:960px;margin:24px auto;color:#344054;font-family:inherit;line-height:1.75}#<?php echo esc_attr($uid); ?> .wutm-policy-intro{margin-bottom:20px;padding:18px 20px;border-left:4px solid var(--wutm-policy-accent);border-radius:8px;background:#f7f9fc}#<?php echo esc_attr($uid); ?> .wutm-policy-card{display:flex;gap:14px;margin:12px 0;padding:18px 20px;border:1px solid #e4e9f0;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(20,40,70,.05)}#<?php echo esc_attr($uid); ?> .wutm-policy-card i{width:9px;height:9px;margin-top:10px;border-radius:50%;background:var(--wutm-policy-accent);flex:none}#<?php echo esc_attr($uid); ?> h3{margin:0 0 5px;color:var(--wutm-policy-primary);font-size:17px}#<?php echo esc_attr($uid); ?> p{margin:0 0 7px}#<?php echo esc_attr($uid); ?> .wutm-faq-item{margin:10px 0;border:1px solid #e2e8f0;border-radius:12px;background:#fff;overflow:hidden}#<?php echo esc_attr($uid); ?> .wutm-faq-item summary{display:flex;justify-content:space-between;gap:14px;padding:17px 20px;color:var(--wutm-policy-primary);font-weight:700;cursor:pointer;list-style:none}#<?php echo esc_attr($uid); ?> .wutm-faq-item summary::-webkit-details-marker{display:none}#<?php echo esc_attr($uid); ?> .wutm-faq-item[open]{border-color:var(--wutm-policy-accent);box-shadow:0 6px 20px rgba(20,40,70,.06)}#<?php echo esc_attr($uid); ?> .wutm-faq-item[open] summary span{transform:rotate(45deg)}#<?php echo esc_attr($uid); ?> .wutm-faq-item summary span{color:var(--wutm-policy-accent);transition:transform .2s}#<?php echo esc_attr($uid); ?> .wutm-policy-content{padding:15px 20px;border-top:1px solid #edf0f4;background:#fafbfd}</style>
        <style>#<?php echo esc_attr($uid); ?>.wutm-policy-privacy .wutm-policy-intro{padding:0;border:0;background:transparent}#<?php echo esc_attr($uid); ?> .wutm-privacy-list{margin:18px 0;padding-left:24px}#<?php echo esc_attr($uid); ?> .wutm-privacy-list li{margin:0 0 18px;padding-left:4px}</style>
        <style>#<?php echo esc_attr($uid); ?>{width:100%;max-width:var(--wutm-policy-width)!important;color:var(--wutm-policy-text)!important}#<?php echo esc_attr($uid); ?>.wutm-policy-refund .wutm-policy-intro{padding:0;border:0;background:transparent}#<?php echo esc_attr($uid); ?>.wutm-policy-refund .wutm-policy-card{display:block;border:0;background:#f5f5f5;box-shadow:none}#<?php echo esc_attr($uid); ?>.wutm-policy-refund .wutm-policy-card i{display:none}#<?php echo esc_attr($uid); ?> .wutm-policy-lead{margin-bottom:10px;font-weight:600}</style>
        <?php return ob_get_clean();
    }

    add_shortcode('shop_policy', function ($atts): string {
        $atts = shortcode_atts(['type' => 'faq'], $atts, 'shop_policy');
        $type = sanitize_key((string) $atts['type']);
        $module_keys = ['faq' => 'faq-shortcode', 'refund' => 'refund-policy', 'privacy' => 'privacy-policy'];
        if (!isset($module_keys[$type]) || !wutm_is_enabled($module_keys[$type])) return '';
        return wutm_render_policy_shortcode($type);
    });
}
