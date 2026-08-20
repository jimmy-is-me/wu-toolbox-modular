<?php
defined('ABSPATH') || exit;

function wutm_render_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $modules = wutm_modules(); $groups = [];
    foreach ($modules as $key => $module) $groups[$module['group']][$key] = $module;
    $enabled = count(array_filter(array_keys($modules), 'wutm_is_enabled'));
    ?>
    <div class="wrap wutm-wrap">
      <header class="wutm-header"><div><h1>🧰 WU Toolbox Modular</h1><p>已啟用 <strong><?php echo esc_html((string) $enabled); ?></strong> / <?php echo esc_html((string) count($modules)); ?> 個模組 · 未啟用的模組完全不載入</p></div><span>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
      <?php foreach ($groups as $group => $items): ?><section><h2><?php echo esc_html($group); ?></h2><div class="wutm-grid">
        <?php foreach ($items as $key => $module): $on = wutm_is_enabled($key); $requires = $module['requires'] ?? ''; $available = !$requires || ($requires === 'woocommerce' && class_exists('WooCommerce')); ?>
        <article class="wutm-card <?php echo $on ? 'on' : ''; ?>" data-settings-url="<?php echo esc_url(admin_url('admin.php?page=' . ($module['settings_page'] ?? ('wu-' . $key)))); ?>"><div class="wutm-icon"><?php echo esc_html($module['icon']); ?></div><div class="wutm-card-body"><h3><?php echo esc_html($module['name']); ?></h3><p><?php echo esc_html($module['description']); ?></p><?php if (!$available): ?><small>需要 WooCommerce</small><?php endif; ?><?php if ($on && $available): $page = $module['settings_page'] ?? ('wu-' . $key); ?><a class="wutm-settings" href="<?php echo esc_url(admin_url('admin.php?page=' . $page)); ?>">設定</a><?php endif; ?></div><label class="wutm-switch"><input type="checkbox" data-module="<?php echo esc_attr($key); ?>" <?php checked($on); disabled(!$available); ?>><span></span></label></article>
        <?php endforeach; ?></div></section><?php endforeach; ?>
    </div>
    <script>document.addEventListener('change',function(e){if(!e.target.matches('.wutm-switch input'))return;const i=e.target;i.disabled=true;fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'wutm_toggle_module',module:i.dataset.module,enabled:i.checked?1:0,nonce:'<?php echo esc_js(wp_create_nonce('wutm_toggle_module')); ?>'})}).then(r=>r.json()).then(r=>{const card=i.closest('.wutm-card');if(!r.success)i.checked=!i.checked;card.classList.toggle('on',i.checked);const link=card.querySelector('.wutm-settings');if(i.checked&&!link){const a=document.createElement('a');a.className='wutm-settings';a.href=card.dataset.settingsUrl;a.textContent='設定';card.querySelector('.wutm-card-body').appendChild(a)}else if(!i.checked&&link){link.remove()}}).catch(()=>i.checked=!i.checked).finally(()=>i.disabled=false)})</script>
    <?php
}
