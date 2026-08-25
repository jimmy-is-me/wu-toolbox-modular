<?php
defined('ABSPATH') || exit;

/**
 * 首頁彈出視窗模組。
 * 從 WM Homepage Popup 改為 WU Toolbox 模組格式，所有函式與選項皆使用 wutm_ 前綴避免衝突。
 */
add_action('admin_menu', 'wutm_homepage_popup_menu', 30);
function wutm_homepage_popup_menu(): void {
    add_submenu_page('wu-toolbox-modular', '首頁彈出視窗', '首頁彈出視窗', 'manage_options', 'wu-homepage-popup', 'wutm_homepage_popup_settings');
}
add_action('admin_init', 'wutm_homepage_popup_register');
function wutm_homepage_popup_register(): void {
    register_setting('wutm_homepage_popup_group', 'wutm_popup_enabled', ['sanitize_callback' => 'absint']);
    register_setting('wutm_homepage_popup_group', 'wutm_popup_slides', ['sanitize_callback' => 'wutm_homepage_popup_sanitize_slides']);
}
function wutm_homepage_popup_sanitize_slides($input): array {
    if (!is_array($input)) return [];
    $clean = [];
    foreach ($input as $slide) {
        if (!is_array($slide)) continue;
        $clean[] = [
            'image_id' => absint($slide['image_id'] ?? 0),
            'description' => sanitize_textarea_field($slide['description'] ?? ''),
            'link' => esc_url_raw($slide['link'] ?? ''),
            'link_target' => (($slide['link_target'] ?? '') === '_blank') ? '_blank' : '_self',
        ];
    }
    return $clean;
}
add_action('admin_enqueue_scripts', 'wutm_homepage_popup_assets');
function wutm_homepage_popup_assets(string $hook): void {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'wu-homepage-popup') return;
    wp_enqueue_media();
    wp_enqueue_script('jquery');
}
function wutm_homepage_popup_settings(): void {
    if (!current_user_can('manage_options')) return;
    $enabled = (bool) get_option('wutm_popup_enabled', 0);
    $slides = get_option('wutm_popup_slides', []);
    if (!is_array($slides) || !$slides) $slides = [['image_id' => 0, 'description' => '', 'link' => '', 'link_target' => '_self']];
    ?>
    <div class="wrap wutm-wrap wutm-module-settings">
      <header class="wutm-header"><div><h1><?php echo esc_html__('首頁彈出視窗', 'wu-toolbox-modular'); ?></h1><p><?php echo esc_html__('設定首頁載入時顯示的圖文彈出視窗。', 'wu-toolbox-modular'); ?></p></div><span>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
      <form method="post" action="options.php">
        <?php settings_fields('wutm_homepage_popup_group'); ?>
        <section class="wutm-panel">
          <h2><?php echo esc_html__('基本設定', 'wu-toolbox-modular'); ?></h2>
          <p><label><input type="checkbox" name="wutm_popup_enabled" value="1" <?php checked($enabled); ?>> <?php echo esc_html__('在首頁啟用彈出視窗', 'wu-toolbox-modular'); ?></label></p>
          <p class="description"><?php echo esc_html__('設定儲存後前台立即生效；內容會透過 AJAX 載入，不受整頁快取影響。', 'wu-toolbox-modular'); ?></p>
        </section>
        <section class="wutm-panel">
          <h2><?php echo esc_html__('幻燈片內容', 'wu-toolbox-modular'); ?></h2>
          <p class="description"><?php echo esc_html__('可新增多張圖片，每張可設定說明文字與點擊連結。', 'wu-toolbox-modular'); ?></p>
          <div id="wutm-popup-slides">
          <?php foreach ($slides as $i => $slide):
              $id = absint($slide['image_id'] ?? 0);
              $url = $id ? wp_get_attachment_image_url($id, 'medium') : '';
          ?>
            <div class="wutm-popup-slide" style="border:1px solid #dcdcde;border-radius:8px;padding:24px;margin:0 0 16px;background:#fff;">
              <p><strong><?php echo esc_html(sprintf(__('第 %d 張', 'wu-toolbox-modular'), $i + 1)); ?></strong> <button type="button" class="button-link-delete wutm-popup-remove" style="float:right;"><?php echo esc_html__('移除', 'wu-toolbox-modular'); ?></button></p>
              <p><img class="wutm-popup-preview" src="<?php echo esc_url($url); ?>" style="max-width:220px;display:<?php echo $url ? 'block' : 'none'; ?>;margin-bottom:8px;"></p>
              <input type="hidden" class="wutm-popup-image-id" name="wutm_popup_slides[<?php echo $i; ?>][image_id]" value="<?php echo esc_attr($id); ?>">
              <p><button type="button" class="button wutm-popup-upload"><?php echo esc_html__('選擇圖片', 'wu-toolbox-modular'); ?></button> <button type="button" class="button wutm-popup-clear" <?php disabled(!$url); ?>><?php echo esc_html__('移除圖片', 'wu-toolbox-modular'); ?></button></p>
              <p><label><?php echo esc_html__('說明文字', 'wu-toolbox-modular'); ?><br><textarea class="large-text" rows="2" name="wutm_popup_slides[<?php echo $i; ?>][description]"><?php echo esc_textarea($slide['description'] ?? ''); ?></textarea></label></p>
              <p><label><?php echo esc_html__('點擊連結網址', 'wu-toolbox-modular'); ?><br><input class="regular-text" type="url" name="wutm_popup_slides[<?php echo $i; ?>][link]" value="<?php echo esc_attr($slide['link'] ?? ''); ?>" placeholder="https://"></label>
              <label style="margin-left:12px;"><input type="checkbox" name="wutm_popup_slides[<?php echo $i; ?>][link_target]" value="_blank" <?php checked($slide['link_target'] ?? '_self', '_blank'); ?>> <?php echo esc_html__('新分頁開啟', 'wu-toolbox-modular'); ?></label></p>
            </div>
          <?php endforeach; ?>
          </div>
          <p><button type="button" class="button" id="wutm-popup-add"><?php echo esc_html__('＋ 新增圖片', 'wu-toolbox-modular'); ?></button></p>
        </section>
        <?php submit_button(__('儲存設定', 'wu-toolbox-modular')); ?>
      </form>
    </div>
    <script>
    jQuery(function($){
      function reindex(){ $('#wutm-popup-slides .wutm-popup-slide').each(function(i){ $(this).find('strong').text('<?php echo esc_js(__('第', 'wu-toolbox-modular')); ?> '+(i+1)+' <?php echo esc_js(__('張', 'wu-toolbox-modular')); ?>'); $(this).find('[name]').each(function(){ var n=$(this).attr('name'); if(n) $(this).attr('name',n.replace(/wutm_popup_slides\\[\\d+\\]/,'wutm_popup_slides['+i+']')); }); }); }
      function bind(ctx){
        ctx.find('.wutm-popup-remove').on('click',function(){ if($('#wutm-popup-slides .wutm-popup-slide').length>1){$(this).closest('.wutm-popup-slide').remove();reindex();} });
        ctx.find('.wutm-popup-upload').on('click',function(){ var b=$(this), f=wp.media({title:'<?php echo esc_js(__('選擇圖片', 'wu-toolbox-modular')); ?>',button:{text:'<?php echo esc_js(__('使用圖片', 'wu-toolbox-modular')); ?>'},multiple:false}); f.on('select',function(){var a=f.state().get('selection').first().toJSON();b.closest('.wutm-popup-slide').find('.wutm-popup-image-id').val(a.id);b.closest('.wutm-popup-slide').find('.wutm-popup-preview').attr('src',a.url).show();b.closest('.wutm-popup-slide').find('.wutm-popup-clear').prop('disabled',false);});f.open();});
        ctx.find('.wutm-popup-clear').on('click',function(){var s=$(this).closest('.wutm-popup-slide');s.find('.wutm-popup-image-id').val('0');s.find('.wutm-popup-preview').hide();$(this).prop('disabled',true);});
      }
      $('#wutm-popup-slides .wutm-popup-slide').each(function(){bind($(this));});
      $('#wutm-popup-add').on('click',function(){var n=$('#wutm-popup-slides .wutm-popup-slide').first().clone();n.find('input,textarea').val('');n.find('.wutm-popup-image-id').val('0');n.find('.wutm-popup-preview').hide();n.find('.wutm-popup-clear').prop('disabled',true);$('#wutm-popup-slides').append(n);reindex();bind(n);});
    });
    </script>
    <?php
}
add_action('wp_ajax_wutm_homepage_popup_data', 'wutm_homepage_popup_ajax');
add_action('wp_ajax_nopriv_wutm_homepage_popup_data', 'wutm_homepage_popup_ajax');
function wutm_homepage_popup_ajax(): void {
    if (!get_option('wutm_popup_enabled', 0)) wp_send_json(['show' => false]);
    $slides = get_option('wutm_popup_slides', []);
    $out = [];
    foreach (is_array($slides) ? $slides : [] as $slide) {
        $id = absint($slide['image_id'] ?? 0);
        $url = $id ? wp_get_attachment_image_url($id, 'large') : '';
        if ($url) $out[] = ['image_url' => $url, 'description' => sanitize_text_field($slide['description'] ?? ''), 'link' => esc_url_raw($slide['link'] ?? ''), 'link_target' => (($slide['link_target'] ?? '') === '_blank') ? '_blank' : '_self'];
    }
    wp_send_json(['show' => !empty($out), 'slides' => $out]);
}
add_action('wp_footer', 'wutm_homepage_popup_frontend');
function wutm_homepage_popup_frontend(): void {
    if (!is_front_page()) return;
    $url = admin_url('admin-ajax.php');
    ?>
    <style>
    #wutm-popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99998;align-items:center;justify-content:center}
    #wutm-popup-overlay.active{display:flex} #wutm-popup-box{position:relative;width:90vw;max-width:800px;max-height:90vh;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 6px 36px rgba(0,0,0,.38)}
    #wutm-popup-close{position:absolute;top:10px;right:14px;width:34px;height:34px;line-height:34px;text-align:center;font-size:24px;cursor:pointer;color:#333;background:rgba(255,255,255,.85);border-radius:50%;z-index:2}
    #wutm-popup-slider{position:relative;overflow:hidden}.wutm-popup-slide-front{display:none}.wutm-popup-slide-front.active{display:block}.wutm-popup-slide-front a{display:block;line-height:0}.wutm-popup-slide-front img{width:100%;max-height:65vh;object-fit:contain;display:block}.wutm-popup-desc{padding:16px 22px;margin:0;text-align:center;color:#444}.wutm-popup-dots{display:flex;justify-content:center;gap:8px;padding:10px 0 14px}.wutm-popup-dot{width:10px;height:10px;border-radius:50%;background:#ccc;cursor:pointer}.wutm-popup-dot.active{background:#555}.wutm-popup-arrow{position:absolute;top:50%;transform:translateY(-50%);border:0;border-radius:50%;width:36px;height:36px;font-size:22px;cursor:pointer;background:rgba(255,255,255,.75);z-index:2}.wutm-popup-prev{left:10px}.wutm-popup-next{right:10px}
    </style>
    <div id="wutm-popup-overlay"><div id="wutm-popup-box"><span id="wutm-popup-close">&times;</span><div id="wutm-popup-slider"></div><div id="wutm-popup-dots" class="wutm-popup-dots" style="display:none"></div></div></div>
    <script>
    (function(){document.addEventListener('DOMContentLoaded',function(){var o=document.getElementById('wutm-popup-overlay'),s=document.getElementById('wutm-popup-slider'),d=document.getElementById('wutm-popup-dots'),c=0;
      fetch('<?php echo esc_url($url); ?>?action=wutm_homepage_popup_data&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.json()}).then(function(x){if(!x.show)return;x.slides.forEach(function(v,i){var e=document.createElement('div');e.className='wutm-popup-slide-front'+(!i?' active':'');var w=document.createElement(v.link?'a':'div');if(v.link){w.href=v.link;w.target=v.link_target||'_self'}var im=document.createElement('img');im.src=v.image_url;w.appendChild(im);e.appendChild(w);if(v.description){var p=document.createElement('p');p.className='wutm-popup-desc';p.textContent=v.description;e.appendChild(p)}s.appendChild(e)});
      if(x.slides.length>1){var p=document.createElement('button'),n=document.createElement('button');p.className='wutm-popup-arrow wutm-popup-prev';p.innerHTML='&#8249;';n.className='wutm-popup-arrow wutm-popup-next';n.innerHTML='&#8250;';s.append(p,n);x.slides.forEach(function(_,i){var q=document.createElement('span');q.className='wutm-popup-dot'+(!i?' active':'');q.onclick=function(){go(i)};d.appendChild(q)});d.style.display='flex';function go(i){var es=s.querySelectorAll('.wutm-popup-slide-front'),ds=d.querySelectorAll('.wutm-popup-dot');es[c].classList.remove('active');ds[c].classList.remove('active');c=(i+x.slides.length)%x.slides.length;es[c].classList.add('active');ds[c].classList.add('active')}p.onclick=function(){go(c-1)};n.onclick=function(){go(c+1)}}o.classList.add('active')}).catch(function(){});document.getElementById('wutm-popup-close').onclick=function(){o.classList.remove('active')};o.onclick=function(e){if(e.target===o)o.classList.remove('active')}})})();
    </script>
    <?php
}
