<?php
/** Module: Branded page transition. */
defined('ABSPATH') || exit;

if (!class_exists('WUTM_Brand_Page_Transition')) {
final class WUTM_Brand_Page_Transition {
    const OPTION = 'wumetax_brand_transition_settings';
    const GROUP = 'wutm_brand_transition_group';
    const PAGE = 'wu-page-transition';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_head', [__CLASS__, 'head'], 1);
        add_action('wp_body_open', [__CLASS__, 'markup'], 1);
        add_action('wp_footer', [__CLASS__, 'script'], 100);
    }

    private static function defaults() {
        return ['color' => '#171b19', 'logo_id' => 0, 'brand_text' => get_bloginfo('name')];
    }

    private static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function sanitize($input) {
        $defaults = self::defaults();
        $color = isset($input['color']) ? sanitize_hex_color($input['color']) : '';
        $logo_id = isset($input['logo_id']) ? absint($input['logo_id']) : 0;
        if ($logo_id && !wp_attachment_is_image($logo_id)) $logo_id = 0;
        $brand = isset($input['brand_text']) ? sanitize_text_field($input['brand_text']) : '';
        return ['color' => $color ?: $defaults['color'], 'logo_id' => $logo_id, 'brand_text' => $brand ?: $defaults['brand_text']];
    }

    public static function register() {
        register_setting(self::GROUP, self::OPTION, ['sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    public static function menu() {
        add_submenu_page('wu-toolbox-modular', '頁面轉場動畫', '頁面轉場動畫', 'manage_options', self::PAGE, [__CLASS__, 'admin_page']);
    }

    public static function admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (self::PAGE === $page) wp_enqueue_media();
    }

    private static function logo_url($id) {
        return $id ? (string) wp_get_attachment_image_url($id, 'medium') : '';
    }

    private static function first($text) {
        return function_exists('mb_substr') ? mb_substr($text, 0, 1) : substr($text, 0, 1);
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings(); $logo = self::logo_url($s['logo_id']);
        ?>
        <div class="wrap wupt-admin"><h1>頁面轉場動畫</h1>
        <p class="description">站內正常換頁時顯示品牌轉場；購物車 AJAX、同頁錨點、下載及外部連結會自動略過。</p>
        <form method="post" action="options.php"><?php settings_fields(self::GROUP); ?>
        <div class="wupt-card"><h2>品牌設定</h2><div class="wupt-grid"><div class="wupt-fields">
        <label for="wupt-color"><strong>品牌主色</strong></label><div><input id="wupt-color" type="color" name="<?php echo esc_attr(self::OPTION); ?>[color]" value="<?php echo esc_attr($s['color']); ?>"> <input id="wupt-color-text" type="text" value="<?php echo esc_attr($s['color']); ?>" maxlength="7"></div>
        <label><strong>品牌 Logo</strong></label><input id="wupt-logo-id" type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[logo_id]" value="<?php echo esc_attr($s['logo_id']); ?>"><div><button type="button" class="button" id="wupt-select-logo">選擇圖片</button> <button type="button" class="button" id="wupt-remove-logo"<?php echo $logo ? '' : ' hidden'; ?>>移除</button></div><p class="description">建議使用透明背景正方形圖片；未設定時顯示品牌文字首字。</p>
        <label for="wupt-brand-text"><strong>品牌文字</strong></label><input class="regular-text" id="wupt-brand-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[brand_text]" value="<?php echo esc_attr($s['brand_text']); ?>">
        </div><div class="wupt-preview" id="wupt-preview" style="--c:<?php echo esc_attr($s['color']); ?>"><div class="wupt-preview-mark"><span id="wupt-letter"<?php echo $logo ? ' hidden' : ''; ?>><?php echo esc_html(self::first($s['brand_text'])); ?></span><img id="wupt-logo-preview" src="<?php echo esc_url($logo); ?>" alt=""<?php echo $logo ? '' : ' hidden'; ?>></div><strong id="wupt-text-preview"><?php echo esc_html($s['brand_text']); ?></strong><span>頁面載入中</span></div></div></div>
        <?php submit_button('儲存設定'); ?></form></div>
        <style>.wupt-admin{max-width:1080px}.wupt-card{margin-top:22px;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:10px}.wupt-card h2{margin-top:0}.wupt-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px}.wupt-fields{display:grid;gap:12px;align-content:start}.wupt-fields label:not(:first-child){margin-top:10px}#wupt-color{width:56px;height:40px;vertical-align:middle}#wupt-color-text{width:105px}.wupt-preview{min-height:270px;border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#fff;background:radial-gradient(circle,color-mix(in srgb,var(--c) 82%,white),var(--c))}.wupt-preview-mark{width:90px;height:90px;border:2px solid #fffd;border-radius:50%;display:grid;place-items:center}.wupt-preview-mark img{width:58px;height:58px;object-fit:contain}#wupt-letter{font-size:34px;font-weight:700}.wupt-preview strong{font-size:22px}.wupt-preview>span:last-child{opacity:.7}@media(max-width:800px){.wupt-grid{grid-template-columns:1fr}.wupt-preview{min-height:220px}}</style>
        <script>(function(){const c=document.getElementById('wupt-color'),ct=document.getElementById('wupt-color-text'),p=document.getElementById('wupt-preview'),id=document.getElementById('wupt-logo-id'),img=document.getElementById('wupt-logo-preview'),letter=document.getElementById('wupt-letter'),brand=document.getElementById('wupt-brand-text'),text=document.getElementById('wupt-text-preview'),remove=document.getElementById('wupt-remove-logo');function setColor(v){if(/^#[0-9a-f]{6}$/i.test(v)){c.value=v;ct.value=v;p.style.setProperty('--c',v)}}c.addEventListener('input',()=>setColor(c.value));ct.addEventListener('change',()=>setColor(ct.value));brand.addEventListener('input',()=>{const v=brand.value.trim()||<?php echo wp_json_encode(get_bloginfo('name')); ?>;text.textContent=v;letter.textContent=Array.from(v)[0]||''});document.getElementById('wupt-select-logo').addEventListener('click',()=>{const f=wp.media({title:'選擇品牌 Logo',button:{text:'使用這張圖片'},multiple:false});f.on('select',()=>{const a=f.state().get('selection').first().toJSON();id.value=a.id;img.src=a.url;img.hidden=false;letter.hidden=true;remove.hidden=false});f.open()});remove.addEventListener('click',()=>{id.value='';img.src='';img.hidden=true;letter.hidden=false;remove.hidden=true})})();</script>
        <?php
    }

    public static function head() {
        $s = self::settings(); $color = sanitize_hex_color($s['color']) ?: '#171b19'; ?>
        <style id="wutm-page-transition-css">:root{--wupt-brand:<?php echo esc_html($color); ?>}#wumetax-page-transition{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle,color-mix(in srgb,var(--wupt-brand) 84%,white),var(--wupt-brand));opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s}html.wupt-show #wumetax-page-transition{opacity:1;visibility:visible;pointer-events:auto;transition-delay:0s}.wupt-stage{display:flex;flex-direction:column;align-items:center;gap:18px;color:#fff;transform:translateY(10px) scale(.97);opacity:.75;transition:.26s ease}.wupt-show .wupt-stage{transform:none;opacity:1}.wupt-logo{position:relative;width:96px;height:96px;display:grid;place-items:center;border:2px solid #fffe;border-radius:50%}.wupt-logo:before,.wupt-logo:after{content:"";position:absolute;border-radius:50%;border:1px solid #fff7;animation:wupt-pulse 1.25s ease-out infinite}.wupt-logo:before{inset:-11px}.wupt-logo:after{inset:-23px;animation-delay:.18s}.wupt-logo img{width:62px;height:62px;object-fit:contain}.wupt-letter{font:700 34px/1 system-ui}.wupt-brand-text{font:600 20px/1.4 system-ui;letter-spacing:.08em}.wupt-loading{font:400 13px/1.4 system-ui;opacity:.72;letter-spacing:.12em}@keyframes wupt-pulse{0%{transform:scale(.92);opacity:.15}55%{opacity:.7}100%{transform:scale(1.08);opacity:0}}@media(prefers-reduced-motion:reduce){#wumetax-page-transition,.wupt-stage{transition:none}.wupt-logo:before,.wupt-logo:after{animation:none}}</style>
        <script>(function(){try{var t=parseInt(sessionStorage.getItem('wutm_page_transition_active')||'0',10);if(t&&Date.now()-t<8000&&!matchMedia('(prefers-reduced-motion: reduce)').matches)document.documentElement.classList.add('wupt-show');else sessionStorage.removeItem('wutm_page_transition_active')}catch(e){}})();</script><?php
    }

    public static function markup() {
        $s = self::settings(); $logo = self::logo_url($s['logo_id']); ?>
        <div id="wumetax-page-transition" aria-hidden="true"><div class="wupt-stage"><div class="wupt-logo"><?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php else: ?><span class="wupt-letter"><?php echo esc_html(self::first($s['brand_text'])); ?></span><?php endif; ?></div><div class="wupt-brand-text"><?php echo esc_html($s['brand_text']); ?></div><div class="wupt-loading">頁面載入中</div></div></div><?php
    }

    public static function script() { ?>
        <script>(function(){'use strict';const root=document.documentElement,key='wutm_page_transition_active',reduce=matchMedia('(prefers-reduced-motion: reduce)');function clear(){root.classList.remove('wupt-show');try{sessionStorage.removeItem(key)}catch(e){}}if(reduce.matches){clear();return}function allowed(e,a){if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||!a)return false;if(a.closest('#wpadminbar')||a.hasAttribute('download')||a.target==='_blank'||a.hasAttribute('data-no-transition'))return false;if(a.matches('.add_to_cart_button,.ajax_add_to_cart,.remove_from_cart_button,.wc-block-cart-item__remove-link'))return false;const h=a.getAttribute('href');if(!h||h==='#'||/^(mailto:|tel:|javascript:)/i.test(h))return false;let u;try{u=new URL(h,location.href)}catch(x){return false}if(u.origin!==location.origin||/^\/(wp-admin|wp-login\.php)(\/|$)/.test(u.pathname)||u.href===location.href)return false;if(u.pathname===location.pathname&&u.search===location.search&&u.hash)return false;return true}document.addEventListener('click',e=>{const a=e.target.closest&&e.target.closest('a[href]');if(!allowed(e,a))return;e.preventDefault();try{sessionStorage.setItem(key,String(Date.now()))}catch(x){}root.classList.add('wupt-show');setTimeout(()=>location.assign(a.href),260)},false);function reveal(){root.classList.contains('wupt-show')?setTimeout(clear,220):clear()}document.readyState==='loading'?document.addEventListener('DOMContentLoaded',reveal,{once:true}):reveal();addEventListener('pageshow',e=>{if(e.persisted)clear()});reduce.addEventListener&&reduce.addEventListener('change',e=>{if(e.matches)clear()})})();</script><?php
    }
}
WUTM_Brand_Page_Transition::init();
}
