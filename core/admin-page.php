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
    $licensed = function_exists('wutm_license_is_valid') && wutm_license_is_valid();
    $enabled = count(array_filter(array_keys($modules), 'wutm_is_enabled'));
    ?>
    <div class="wrap wutm-wrap">
      <header class="wutm-header"><div><span class="wutm-header-kicker">MODULAR ADMINISTRATION</span><h1>WU Toolbox Modular</h1><p>已啟用 <strong><?php echo esc_html((string) $enabled); ?></strong> / <?php echo esc_html((string) count($modules)); ?> 個模組 · 未啟用的模組完全不載入</p></div><span class="wutm-version"><i class="wutm-running-dot" aria-hidden="true"></i>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
      <?php if (!$licensed): ?><div class="notice notice-warning inline"><p><strong>尚未完成授權驗證。</strong>既有模組會繼續運作，但啟用新模組前必須先完成授權。 <a class="button button-small" href="#wutm-license">前往授權與更新</a></p></div><?php endif; ?>
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
        <article id="wutm-module-<?php echo esc_attr($key); ?>" class="wutm-card <?php echo $on ? 'on' : ''; ?>" tabindex="-1" data-module-name="<?php echo esc_attr($module['name']); ?>" data-search="<?php echo esc_attr($module['name'] . ' ' . $module['description']); ?>" data-settings-url="<?php echo esc_url($settings_url); ?>"><div class="wutm-icon"><?php echo esc_html($module['icon']); ?></div><div class="wutm-card-body"><h3><?php echo esc_html($module['name']); ?><?php if (!empty($module['development'])) : ?> <span class="wutm-development-badge">開發中</span><?php endif; ?><?php if (!empty($module['tag'])) : ?> <span class="wutm-card-badge"><?php echo esc_html($module['tag']); ?></span><?php endif; ?><?php if ($third_party_active) : ?> <span class="wutm-card-badge">已開啟</span><?php endif; ?></h3><p><?php echo esc_html($module['description']); ?></p><?php if (!$available): ?><small>需要 <?php echo esc_html($requires === 'translatepress' ? 'TranslatePress Multilingual' : 'WooCommerce'); ?></small><?php elseif (!$licensed && !$on): ?><small>完成授權後可啟用</small><?php endif; ?><?php if ($module_enabled && $available): ?><a class="wutm-settings" href="<?php echo esc_url($settings_url); ?>">設定</a><?php endif; ?></div><label class="wutm-switch"><input type="checkbox" data-module="<?php echo esc_attr($key); ?>" <?php checked($on); disabled(!$available || $third_party_active || (!$licensed && !$on)); ?>><span></span></label></article>
        <?php endforeach; ?></div></section><?php endforeach; ?>
      <?php WUTM_License_Manager::instance()->render_panel(); ?>
      <footer class="wutm-footer">此模組由 <a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">Wumetax</a> 開發與維護 - <a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">Wumetax</a> 提供主機管理及網站開發</footer>
      <div class="wutm-floating-actions"><button type="button" class="wutm-float-button wutm-back-to-top">回到最上面</button></div>
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
    document.addEventListener('change',function(e){if(!e.target.matches('.wutm-switch input'))return;const i=e.target;i.disabled=true;fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'wutm_toggle_module',module:i.dataset.module,enabled:i.checked?1:0,nonce:'<?php echo esc_js(wp_create_nonce('wutm_toggle_module')); ?>'})}).then(r=>r.json()).then(r=>{const card=i.closest('.wutm-card');if(!r.success){i.checked=!i.checked;const message=r.data&&r.data.message?r.data.message:'設定儲存失敗。';const slot=document.querySelector('.wutm-notice-slot');if(slot){const notice=document.createElement('div');notice.className='notice notice-error';notice.innerHTML='<p></p>';notice.querySelector('p').textContent=message;slot.replaceChildren(notice)}return}card.classList.toggle('on',i.checked);const link=card.querySelector('.wutm-settings');if(i.checked&&!link){const a=document.createElement('a');a.className='wutm-settings';a.href=card.dataset.settingsUrl;a.textContent='設定';card.querySelector('.wutm-card-body').appendChild(a)}else if(!i.checked&&link){link.remove()}}).catch(()=>i.checked=!i.checked).finally(()=>i.disabled=false)});
    (function(){
        const topButton=document.querySelector('.wutm-back-to-top');
        if(topButton)topButton.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});
    }());
    </script>
    <?php
}
