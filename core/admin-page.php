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
    <style>
    .wutm-wrap{max-width:1200px}
    .wutm-header{margin:20px 0 28px;padding:22px 28px;background:linear-gradient(135deg,#061326,#0d3155 60%,#17466b);color:#fff;border:1px solid #ffc85777;border-radius:16px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 0 28px #ff9e3d22}
    .wutm-header h1{color:#fff7df;margin:0;font-size:20px;text-shadow:0 0 14px #ffc85755}.wutm-header p{margin:5px 0 0;color:#9ab5c9}.wutm-header strong{color:#35df92}.wutm-header span{color:#ffc857;opacity:.9}
    .wutm-wrap section{margin:26px 0}.wutm-wrap h2{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:#ffc857}
    .wutm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
    .wutm-card{display:flex;gap:12px;align-items:flex-start;padding:17px;background:linear-gradient(145deg,#0d2a4b,#081a32);border:1px solid #3a8fc566;border-radius:14px;box-shadow:0 10px 25px #020b1855;color:#eaf8ff}
    .wutm-card.on{border-color:#35df92;box-shadow:0 0 0 1px #35df92,0 0 24px #35df9244}.wutm-card-body{flex:1}.wutm-settings{display:inline-block;margin-top:9px;color:#ffc857;text-decoration:none;font-size:12px;font-weight:600}.wutm-settings:hover{text-decoration:underline;color:#fff7df}
    .wutm-card h3{margin:0 0 5px;font-size:14px;color:#fff7df}.wutm-card p{margin:0;color:#9ab5c9;font-size:12px;line-height:1.5}.wutm-icon{font-size:24px}.wutm-switch{margin-left:auto;position:relative;width:40px;height:22px;flex:none}.wutm-switch input{opacity:0}.wutm-switch span{position:absolute;inset:0;background:#244566;border-radius:20px;cursor:pointer}.wutm-switch span:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}.wutm-switch input:checked+span{background:#12a97b;box-shadow:0 0 14px #35df9277}.wutm-switch input:checked+span:before{transform:translateX(18px)}
</style>
    <script>document.addEventListener('change',function(e){if(!e.target.matches('.wutm-switch input'))return;const i=e.target;i.disabled=true;fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'wutm_toggle_module',module:i.dataset.module,enabled:i.checked?1:0,nonce:'<?php echo esc_js(wp_create_nonce('wutm_toggle_module')); ?>'})}).then(r=>r.json()).then(r=>{const card=i.closest('.wutm-card');if(!r.success)i.checked=!i.checked;card.classList.toggle('on',i.checked);const link=card.querySelector('.wutm-settings');if(i.checked&&!link){const a=document.createElement('a');a.className='wutm-settings';a.href=card.dataset.settingsUrl;a.textContent='設定';card.querySelector('.wutm-card-body').appendChild(a)}else if(!i.checked&&link){link.remove()}}).catch(()=>i.checked=!i.checked).finally(()=>i.disabled=false)})</script>
    <?php
}
