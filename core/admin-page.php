<?php
defined('ABSPATH') || exit;

function wutm_third_party_plugin_file(string $key): string {
    $plugins = [
        'wp-downgrade' => 'wp-downgrade/wp-downgrade.php',
        'wordfence' => 'wordfence/wordfence.php',
        'rank-math-seo' => 'seo-by-rank-math/rank-math.php',
        'instant-images' => 'instant-images/instant-images.php',
        'updraftplus' => 'updraftplus/updraftplus.php',
        'wpvivid' => 'wpvivid-backuprestore/wpvivid-backuprestore.php',
        'translatepress' => 'translatepress-multilingual/index.php',
        'gtranslate' => 'gtranslate/gtranslate.php',
        'loco-translate' => 'loco-translate/loco.php',
        'fluent-smtp' => 'fluent-smtp/fluent-smtp.php',
        'wpcode' => 'insert-headers-and-footers/ihaf.php',
        'woocommerce' => 'woocommerce/woocommerce.php',
    ];
    return $plugins[$key] ?? '';
}

function wutm_third_party_plugin_active(string $key): bool {
    $plugin = wutm_third_party_plugin_file($key);
    if ($plugin === '') return false;
    if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active($plugin) || (is_multisite() && is_plugin_active_for_network($plugin));
}

function wutm_module_settings_url(array $module, string $key): string {
    if (!empty($module['settings_url'])) return admin_url(ltrim((string) $module['settings_url'], '/'));
    return admin_url('admin.php?page=' . ($module['settings_page'] ?? ('wu-' . $key)));
}

function wutm_render_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $modules = wutm_modules();
    $groups = wutm_grouped_modules();
    // License manager is retained for future use but is currently disabled.
    $licensed = true;
    $enabled = count(array_filter(array_keys($modules), 'wutm_is_enabled'));
    ?>
    <div class="wrap wutm-wrap">
      <header class="wutm-header"><div><span class="wutm-header-kicker">MODULAR ADMINISTRATION</span><h1>WU Toolbox Modular</h1><p>已啟用 <strong><?php echo esc_html((string) $enabled); ?></strong> / <?php echo esc_html((string) count($modules)); ?> 個模組 · 未啟用的模組完全不載入</p></div><span class="wutm-version"><i class="wutm-running-dot" aria-hidden="true"></i>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
      <div class="wutm-search-panel"><form class="wutm-module-search" role="search"><label class="screen-reader-text" for="wutm-module-search-input">搜尋功能</label><input id="wutm-module-search-input" type="search" placeholder="搜尋功能，例如：工具、版本、通知、結帳" autocomplete="off"><button type="submit" class="button button-primary">搜尋</button></form><div class="wutm-search-results" hidden><strong class="wutm-search-status" aria-live="polite"></strong><div class="wutm-search-result-list"></div></div></div>
      <div class="wutm-notice-slot" aria-live="polite"></div>
      <?php foreach ($groups as $group => $items): ?><section><h2><?php echo esc_html($group); ?></h2><div class="wutm-grid">
        <?php foreach ($items as $key => $module):
            $module_enabled = wutm_is_enabled($key);
            $third_party_active = !empty($module['tag']) && $module['tag'] === '第三方外掛' && wutm_third_party_plugin_active($key);
            $on = $module_enabled || $third_party_active;
            $requires = $module['requires'] ?? '';
            $available = !$requires || ($requires === 'woocommerce' && class_exists('WooCommerce')) || ($requires === 'translatepress' && class_exists('TRP_Translate_Press'));
            $settings_url = wutm_module_settings_url($module, $key);
        ?>
        <article id="wutm-module-<?php echo esc_attr($key); ?>" class="wutm-card <?php echo $on ? 'on' : ''; ?>" tabindex="-1" data-module-name="<?php echo esc_attr($module['name']); ?>" data-search="<?php echo esc_attr($module['name'] . ' ' . $module['description']); ?>" data-settings-url="<?php echo esc_url($settings_url); ?>"><div class="wutm-icon"><?php echo esc_html($module['icon']); ?></div><div class="wutm-card-body"><h3><?php echo esc_html($module['name']); ?><?php if (!empty($module['development'])) : ?> <span class="wutm-development-badge">開發中</span><?php endif; ?><?php if (!empty($module['tag'])) : ?> <span class="wutm-card-badge"><?php echo esc_html($module['tag']); ?></span><?php endif; ?><?php if ($third_party_active) : ?> <span class="wutm-card-badge">已開啟</span><?php endif; ?></h3><p><?php echo esc_html($module['description']); ?></p><?php if (!$available): ?><small>需要 <?php echo esc_html($requires === 'translatepress' ? 'TranslatePress Multilingual' : 'WooCommerce'); ?></small><?php endif; ?><?php if ($module_enabled && $available): ?><a class="wutm-settings" href="<?php echo esc_url($settings_url); ?>">設定</a><?php endif; ?></div><label class="wutm-switch"><input type="checkbox" data-module="<?php echo esc_attr($key); ?>" <?php checked($on); disabled(!$available || $third_party_active); ?>><span></span></label></article>
        <?php endforeach; ?></div></section><?php endforeach; ?>
      <footer class="wutm-footer">此模組由 <a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">Wumetax</a> 開發與維護 - <a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">Wumetax</a> 提供主機管理及網站開發</footer>
      <div class="wutm-floating-actions"><button type="button" class="wutm-float-button wutm-back-to-top">回到最上面</button><button type="button" class="wutm-float-button wutm-contact-open">聯絡我們</button></div>
      <div class="wutm-contact-modal" hidden><div class="wutm-contact-dialog" role="dialog" aria-modal="true" aria-labelledby="wutm-contact-title"><button type="button" class="wutm-contact-close" aria-label="關閉聯絡表單">×</button><h2 id="wutm-contact-title">聯絡我們</h2><p>訊息會由網站後端安全傳送至 Wumetax 的 Discord，不會在瀏覽器公開 Webhook。</p><form class="wutm-contact-form"><label>聯絡人<input type="text" name="contact_name" maxlength="80" value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>" required></label><label>電子郵件<input type="email" name="contact_email" maxlength="190" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required></label><label>訊息<textarea name="message" rows="6" maxlength="1500" required></textarea></label><details class="wutm-webhook-setting"><summary>Discord Webhook 設定</summary><label>Webhook 網址<input type="password" name="webhook_url" autocomplete="new-password" placeholder="<?php echo get_option('wutm_contact_discord_webhook') ? '已設定；留空即可繼續使用' : '第一次使用請貼上 Discord Webhook'; ?>"></label><small>網址只儲存在此網站資料庫，不會輸出到頁面或公開 Release。</small></details><button type="submit" class="button button-primary">傳送訊息</button><span class="wutm-contact-status" aria-live="polite"></span></form></div></div>
    </div>
    <script>
    (function(){
        const slot=document.querySelector('.wutm-notice-slot');
        if(slot) document.querySelectorAll('#wpbody-content > .notice,.wutm-wrap > .notice,.wutm-header .notice').forEach(function(n){if(!slot.contains(n))slot.appendChild(n);});
        const form=document.querySelector('.wutm-module-search'),input=document.getElementById('wutm-module-search-input'),results=document.querySelector('.wutm-search-results'),status=document.querySelector('.wutm-search-status'),list=document.querySelector('.wutm-search-result-list');
        function clearMatches(){document.querySelectorAll('.wutm-card.is-search-match').forEach(function(card){card.classList.remove('is-search-match');});}
        if(form&&input&&results&&status&&list){
            form.addEventListener('submit',function(e){
                e.preventDefault();clearMatches();list.replaceChildren();
                const raw=input.value.trim(),query=raw.toLocaleLowerCase();
                if(!query){results.hidden=false;status.textContent='請輸入要尋找的功能名稱。';input.focus();return;}
                const matches=Array.from(document.querySelectorAll('.wutm-card')).filter(function(card){return (card.dataset.search||'').toLocaleLowerCase().includes(query);});
                results.hidden=false;
                if(!matches.length){status.textContent='找不到符合「'+raw+'」的功能。';return;}
                status.textContent='已找到 '+matches.length+' 筆：'+matches.map(function(card){return card.dataset.moduleName||'';}).join('、');
                matches.forEach(function(card){const button=document.createElement('button');button.type='button';button.className='wutm-search-result';button.dataset.target=card.id;button.textContent=card.dataset.moduleName||'';list.appendChild(button);});
            });
            list.addEventListener('click',function(e){
                const button=e.target.closest('.wutm-search-result');if(!button)return;
                const card=document.getElementById(button.dataset.target);if(!card)return;
                clearMatches();card.classList.add('is-search-match');card.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(function(){card.focus({preventScroll:true});},450);
            });
            input.addEventListener('input',function(){if(!input.value.trim()){results.hidden=true;list.replaceChildren();clearMatches();}});
        }
    }());
    document.addEventListener('change',function(e){if(!e.target.matches('.wutm-switch input'))return;const i=e.target;i.disabled=true;fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'wutm_toggle_module',module:i.dataset.module,enabled:i.checked?1:0,nonce:'<?php echo esc_js(wp_create_nonce('wutm_toggle_module')); ?>'})}).then(r=>r.json()).then(r=>{const card=i.closest('.wutm-card');if(!r.success)i.checked=!i.checked;card.classList.toggle('on',i.checked);const link=card.querySelector('.wutm-settings');if(i.checked&&!link){const a=document.createElement('a');a.className='wutm-settings';a.href=card.dataset.settingsUrl;a.textContent='設定';card.querySelector('.wutm-card-body').appendChild(a)}else if(!i.checked&&link){link.remove()}}).catch(()=>i.checked=!i.checked).finally(()=>i.disabled=false)});
    (function(){
        const topButton=document.querySelector('.wutm-back-to-top'),openButton=document.querySelector('.wutm-contact-open'),modal=document.querySelector('.wutm-contact-modal'),closeButton=document.querySelector('.wutm-contact-close'),form=document.querySelector('.wutm-contact-form'),status=document.querySelector('.wutm-contact-status');
        if(topButton)topButton.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});
        function setModal(open){if(!modal)return;modal.hidden=!open;document.body.classList.toggle('wutm-modal-open',open);if(open){const first=modal.querySelector('input,textarea,button');if(first)first.focus();}}
        if(openButton)openButton.addEventListener('click',function(){setModal(true);});if(closeButton)closeButton.addEventListener('click',function(){setModal(false);});if(modal)modal.addEventListener('click',function(e){if(e.target===modal)setModal(false);});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal&&!modal.hidden)setModal(false);});
        if(form&&status)form.addEventListener('submit',function(e){e.preventDefault();const submit=form.querySelector('[type=submit]');submit.disabled=true;status.textContent='傳送中…';const data=new FormData(form);data.append('action','wutm_send_contact');data.append('nonce','<?php echo esc_js(wp_create_nonce('wutm_send_contact')); ?>');fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'}).then(function(response){return response.json();}).then(function(response){if(!response.success)throw new Error(response.data&&response.data.message?response.data.message:'傳送失敗');status.textContent='訊息已成功送出。';form.querySelector('textarea').value='';form.querySelector('[name=webhook_url]').value='';}).catch(function(error){status.textContent=error.message||'傳送失敗，請稍後再試。';}).finally(function(){submit.disabled=false;});});
    }());
    </script>
    <?php
}

function wutm_valid_discord_webhook(string $url): bool {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') return false;
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($host, ['discord.com', 'canary.discord.com', 'ptb.discord.com', 'discordapp.com'], true)) return false;
    return (bool) preg_match('#^/api/webhooks/[0-9]+/[A-Za-z0-9._-]+/?$#', (string) ($parts['path'] ?? ''));
}

add_action('wp_ajax_wutm_send_contact', function (): void {
    check_ajax_referer('wutm_send_contact', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '權限不足。'], 403);
    $rate_key = 'wutm_contact_rate_' . get_current_user_id();
    if (get_transient($rate_key)) wp_send_json_error(['message' => '請稍候一分鐘再傳送下一則訊息。'], 429);

    $name = sanitize_text_field(wp_unslash($_POST['contact_name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['contact_email'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    if ($name === '' || !is_email($email) || $message === '') wp_send_json_error(['message' => '請完整填寫聯絡人、電子郵件與訊息。'], 400);

    $submitted_webhook = esc_url_raw(wp_unslash($_POST['webhook_url'] ?? ''));
    $webhook = $submitted_webhook !== '' ? $submitted_webhook : (string) get_option('wutm_contact_discord_webhook', '');
    if (!wutm_valid_discord_webhook($webhook)) wp_send_json_error(['message' => '請先輸入有效的 Discord Webhook 網址。'], 400);

    $message = function_exists('mb_substr') ? mb_substr($message, 0, 1500) : substr($message, 0, 1500);
    $safe_message = str_replace(['@everyone', '@here'], ['＠everyone', '＠here'], $message);
    $content = "**WU Toolbox 聯絡訊息**\n網站：" . home_url('/') . "\n聯絡人：{$name}\nEmail：{$email}\n\n{$safe_message}";
    $response = wp_remote_post($webhook, [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if (is_wp_error($response) || !in_array(wp_remote_retrieve_response_code($response), [200, 204], true)) {
        wp_send_json_error(['message' => 'Discord 暫時無法接收訊息，請稍後再試。'], 502);
    }
    if ($submitted_webhook !== '') update_option('wutm_contact_discord_webhook', $submitted_webhook, false);
    set_transient($rate_key, 1, MINUTE_IN_SECONDS);
    wp_send_json_success(['message' => '訊息已成功送出。']);
});
