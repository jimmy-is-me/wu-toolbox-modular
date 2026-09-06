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
    $modules = wutm_modules(); $groups = [];
    foreach ($modules as $key => $module) $groups[$module['group']][$key] = $module;
    // License manager is retained for future use but is currently disabled.
    $licensed = true;
    $enabled = count(array_filter(array_keys($modules), 'wutm_is_enabled'));
    ?>
    <div class="wrap wutm-wrap">
      <header class="wutm-header"><div><h1>🧰 WU Toolbox Modular</h1><p>已啟用 <strong><?php echo esc_html((string) $enabled); ?></strong> / <?php echo esc_html((string) count($modules)); ?> 個模組 · 未啟用的模組完全不載入</p></div><span>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
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
        <article class="wutm-card <?php echo $on ? 'on' : ''; ?>" data-settings-url="<?php echo esc_url($settings_url); ?>"><div class="wutm-icon"><?php echo esc_html($module['icon']); ?></div><div class="wutm-card-body"><h3><?php echo esc_html($module['name']); ?><?php if (!empty($module['development'])) : ?> <span class="wutm-development-badge">開發中</span><?php endif; ?><?php if (!empty($module['tag'])) : ?> <span class="wutm-card-badge"><?php echo esc_html($module['tag']); ?></span><?php endif; ?><?php if ($third_party_active) : ?> <span class="wutm-card-badge">已開啟</span><?php endif; ?></h3><p><?php echo esc_html($module['description']); ?></p><?php if (!$available): ?><small>需要 <?php echo esc_html($requires === 'translatepress' ? 'TranslatePress Multilingual' : 'WooCommerce'); ?></small><?php endif; ?><?php if ($module_enabled && $available): ?><a class="wutm-settings" href="<?php echo esc_url($settings_url); ?>">設定</a><?php endif; ?></div><label class="wutm-switch"><input type="checkbox" data-module="<?php echo esc_attr($key); ?>" <?php checked($on); disabled(!$available || $third_party_active); ?>><span></span></label></article>
        <?php endforeach; ?></div></section><?php endforeach; ?>
      <footer class="wutm-footer">由 WUMETAX 開發與維護</footer>
    </div>
    <script>(function(){const slot=document.querySelector('.wutm-notice-slot');if(slot){document.querySelectorAll('#wpbody-content > .notice,.wutm-wrap > .notice,.wutm-header .notice').forEach(function(n){if(!slot.contains(n))slot.appendChild(n);});}})();document.addEventListener('change',function(e){if(!e.target.matches('.wutm-switch input'))return;const i=e.target;i.disabled=true;fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'wutm_toggle_module',module:i.dataset.module,enabled:i.checked?1:0,nonce:'<?php echo esc_js(wp_create_nonce('wutm_toggle_module')); ?>'})}).then(r=>r.json()).then(r=>{const card=i.closest('.wutm-card');if(!r.success)i.checked=!i.checked;card.classList.toggle('on',i.checked);const link=card.querySelector('.wutm-settings');if(i.checked&&!link){const a=document.createElement('a');a.className='wutm-settings';a.href=card.dataset.settingsUrl;a.textContent='設定';card.querySelector('.wutm-card-body').appendChild(a)}else if(!i.checked&&link){link.remove()}}).catch(()=>i.checked=!i.checked).finally(()=>i.disabled=false)})</script>
    <?php
}
