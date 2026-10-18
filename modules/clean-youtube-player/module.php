<?php
/** Clean YouTube module adapted from the supplied v2.1.0 reference. */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WUTM_CYP_PLYR_VER', '3.7.8' );

/* =========================================================
 * 1. 設定值
 * ======================================================= */
function wutm_cyp_defaults() {
    return array(
        'enable'          => 1,
        'plyr_clean_mode' => 1,
        'lazy_assets'     => 1,
        'bigplay'         => 1,
        'brand_color'     => '#000000',
        'rel'             => 1,
        'modestbranding'  => 1,
        'iv_load_policy'  => 1,
        'nocookie'        => 1,
        'controls'        => 1,
        'fs'              => 1,
    );
}

function wutm_cyp_bool_keys() {
    return array( 'enable', 'plyr_clean_mode', 'lazy_assets', 'bigplay', 'rel', 'modestbranding', 'iv_load_policy', 'nocookie', 'controls', 'fs' );
}

function wutm_cyp_get_options() {
    $saved = get_option( 'cyp_options', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), wutm_cyp_defaults() );
}

function wutm_cyp_plyr_active() {
    $o = wutm_cyp_get_options();
    return ! empty( $o['enable'] ) && ! empty( $o['plyr_clean_mode'] );
}

/* =========================================================
 * 2. 後台選單與儲存
 * ======================================================= */
add_action( 'admin_menu', 'wutm_cyp_add_admin_menu' );
function wutm_cyp_add_admin_menu() {
    add_submenu_page(
        'wu-toolbox-modular',
        '極簡 YT 播放器',
        '極簡 YT 播放器',
        'manage_options',
        'wu-clean-youtube-player',
        'wutm_cyp_options_page_html'
    );
}

add_action( 'admin_init', 'wutm_cyp_settings_init' );
function wutm_cyp_settings_init() {
    register_setting( 'wutm_cyp_settings_group', 'cyp_options', array(
        'type'              => 'array',
        'sanitize_callback' => 'wutm_cyp_sanitize_options',
        'default'           => wutm_cyp_defaults(),
    ) );
}

function wutm_cyp_sanitize_options( $input ) {
    $input = is_array( $input ) ? $input : array();
    $out   = array();

    // 未勾選的 checkbox 不會被送出，必須明確寫成 0
    foreach ( wutm_cyp_bool_keys() as $key ) {
        $out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    $color              = isset( $input['brand_color'] ) && is_string($input['brand_color']) ? sanitize_hex_color( $input['brand_color'] ) : '';
    $out['brand_color'] = $color ? $color : '#000000';

    return $out;
}

// Bounded cache invalidation after a real Settings API save, never inside sanitize.
add_action('update_option_cyp_options', 'wutm_cyp_queue_cleanup', 10, 0);
add_action('add_option_cyp_options', 'wutm_cyp_queue_cleanup', 10, 0);
add_action('admin_init', function () {
    if (($_POST['option_page'] ?? '') !== 'wutm_cyp_settings_group' || !current_user_can('manage_options')) return;
    if (!is_string($_POST['_wpnonce'] ?? null) || !wp_verify_nonce($_POST['_wpnonce'], 'wutm_cyp_settings_group-options')) return;
    // Also retain the reference's explicit-save cleanup when values are unchanged.
    add_action('shutdown', 'wutm_cyp_queue_cleanup');
});
function wutm_cyp_queue_cleanup() {
    update_option('wutm_cyp_cleanup_cursor', 0, false);
    if (!wp_next_scheduled('wutm_cyp_cleanup')) wp_schedule_single_event(time() + 5, 'wutm_cyp_cleanup');
}
add_action('wutm_cyp_cleanup', 'wutm_cyp_cleanup_batch');
function wutm_cyp_cleanup_batch() {
    global $wpdb;
    $cursor = (int) get_option('wutm_cyp_cleanup_cursor', 0);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key LIKE %s ORDER BY meta_id ASC LIMIT 100", $cursor, $wpdb->esc_like('_oembed_') . '%'));
    if (!$rows) { delete_option('wutm_cyp_cleanup_cursor'); return; }
    $ids = array_map(static function ($row) { return (int) $row->meta_id; }, $rows);
    $deleted = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode(',', $ids) . ")");
    if ($deleted === false) return; // Keep cursor for a later explicit settings save/retry.
    foreach (array_unique(array_map(static function ($row) { return (int) $row->post_id; }, $rows)) as $post_id) wp_cache_delete($post_id, 'post_meta');
    update_option('wutm_cyp_cleanup_cursor', max($ids), false);
    if (count($rows) === 100 && !wp_next_scheduled('wutm_cyp_cleanup')) wp_schedule_single_event(time() + 20, 'wutm_cyp_cleanup');
    else delete_option('wutm_cyp_cleanup_cursor');
}

function wutm_cyp_field_checkbox( $key, $label, $options, $desc = '' ) {
    printf(
        '<label><input type="checkbox" name="cyp_options[%1$s]" value="1" %2$s> %3$s</label>',
        esc_attr( $key ),
        checked( 1, ! empty( $options[ $key ] ) ? 1 : 0, false ),
        $label // 內部固定字串
    );
    if ( $desc ) {
        echo '<p class="description">' . $desc . '</p>';
    }
}

function wutm_cyp_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $o = wutm_cyp_get_options();
    ?>
    <div class="wrap">
        <h1>YouTube 極簡播放器設定</h1>
        <?php if (function_exists('cyp_filter_youtube_embed')): ?><div class="notice notice-warning"><p>偵測到原獨立播放器仍啟用，目前優先沿用其過濾器以避免重複處理。請停用舊版本後再使用此模組；既有設定會保留。</p></div><?php endif; ?>
        <p>控制全站 YouTube oEmbed 嵌入影片的外觀（不影響媒體庫上傳影片）。儲存後會在背景分批清除舊嵌入快取；若有頁面快取，請一併清除。自訂 HTML／第三方播放器不會強制改寫。</p>
        <p class="description">播放器不能保證隱藏 YouTube 廣告、品牌或所有推薦內容；官方參數仍受 YouTube 支援範圍限制。延遲模式仍會讀取 YouTube 縮圖，點擊後會連線至播放器服務。</p>

        <form action="options.php" method="post">
            <?php settings_fields( 'wutm_cyp_settings_group' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">啟用功能</th>
                    <td><?php wutm_cyp_field_checkbox( 'enable', '啟用 YouTube 播放器優化過濾器', $o ); ?></td>
                </tr>

                <tr>
                    <th scope="row">純淨播放器 (Plyr)</th>
                    <td>
                        <fieldset>
                            <?php
                            wutm_cyp_field_checkbox( 'plyr_clean_mode', '<strong>套用極簡控制條</strong>（隱藏標題、分享、稍後觀看、更多影片）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'bigplay', '顯示中央大播放鍵', $o );
                            ?>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row">效能</th>
                    <td>
                        <?php
                        wutm_cyp_field_checkbox( 'lazy_assets', '<strong>延遲載入 Plyr JS / CSS</strong>', $o,
                            '開啟後：影片先顯示縮圖（Facade），滑過或點擊時才載入播放器與 YouTube 腳本。關閉則改為在頁尾以 <code>defer</code> 載入。' );
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">播放器顏色</th>
                    <td>
                        <input type="color" name="cyp_options[brand_color]" value="<?php echo esc_attr( $o['brand_color'] ); ?>">
                        <p class="description">播放鍵、進度條與滑鼠懸停的顏色（預設黑色）。</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">YouTube 官方參數</th>
                    <td>
                        <fieldset>
                            <?php
                            wutm_cyp_field_checkbox( 'rel', '減少推薦影片（<code>rel=0</code>，僅顯示同頻道影片）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'modestbranding', '保留相容參數（<code>modestbranding=1</code>，YouTube 已棄用，無法藉此隱藏品牌）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'iv_load_policy', '隱藏資訊卡與註解（<code>iv_load_policy=3</code>）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'nocookie', '隱私強化模式（<code>youtube-nocookie.com</code>）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'controls', '顯示播放控制條（<code>controls=1</code>）', $o );
                            echo '<br><br>';
                            wutm_cyp_field_checkbox( 'fs', '允許全螢幕按鈕（<code>fs=1</code>）', $o );
                            ?>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <?php submit_button( '儲存變更並清除影片快取' ); ?>
        </form>
    </div>
    <?php
}

/* =========================================================
 * 3. 前台是否需要載入資源的旗標
 * ======================================================= */
function wutm_cyp_needs_assets( $set = false ) {
    static $flag = false;
    if ( $set ) {
        $flag = true;
    }
    return $flag;
}

/* =========================================================
 * 4. YouTube oEmbed 過濾
 * ======================================================= */
add_filter( 'embed_oembed_html', 'wutm_cyp_filter_youtube_embed', 99, 4 );
function wutm_cyp_filter_youtube_embed( $html, $url, $attr, $post_id ) {
    $o = wutm_cyp_get_options();
    if ( empty( $o['enable'] ) || function_exists('cyp_filter_youtube_embed') ) {
        return $html;
    }
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (!in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com','youtu.be','www.youtu.be'], true)) {
        return $html;
    }
    if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return $html;
    }

    $fallback = wutm_cyp_build_iframe_html( $html, $o );

    if ( empty( $o['plyr_clean_mode'] ) ) {
        return $fallback;
    }

    // 播放清單 / 指定起始時間等情況，改用 iframe 以免功能遺失
    $video_id = '';
    if ( ! preg_match( '/[?&](list|t|start|end|loop|playlist)=/', $url ) && false === strpos( $url, 'youtube.com/playlist' ) ) {
        $pattern = '#(?:youtube\.com/(?:watch\?(?:[^"\s]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})#';
        if ( preg_match( $pattern, $url, $m ) ) {
            $video_id = $m[1];
        } elseif ( preg_match( $pattern, $html, $m ) && ! preg_match( '/[?&;](list|start|end|loop|playlist)=/', $html ) ) {
            $video_id = $m[1];
        }
    }
    if ( ! $video_id ) {
        return $fallback;
    }

    $title = '';
    if ( preg_match( '/title="([^"]*)"/', $html, $tm ) ) {
        $title = wp_strip_all_tags( html_entity_decode( $tm[1], ENT_QUOTES, 'UTF-8' ) );
    }
    $label = $title ? '播放影片：' . $title : '播放影片';

    wutm_cyp_needs_assets( true );

    return sprintf(
        '<div class="cyp-video cyp-yt" data-id="%1$s"><button type="button" class="cyp-facade" aria-label="%2$s"><img src="https://i.ytimg.com/vi/%1$s/hqdefault.jpg" alt="" loading="lazy" decoding="async"><span class="cyp-facade-btn" aria-hidden="true"></span></button><noscript>%3$s</noscript></div>',
        esc_attr( $video_id ),
        esc_attr( $label ),
        $fallback
    );
}

function wutm_cyp_build_iframe_html( $html, $o ) {
    if ( ! empty( $o['nocookie'] ) ) {
        $html = str_replace( 'youtube.com/embed/', 'youtube-nocookie.com/embed/', $html );
    }

    $args = array(
        'rel'            => ! empty( $o['rel'] ) ? 0 : 1,
        'modestbranding' => ! empty( $o['modestbranding'] ) ? 1 : 0,
        'iv_load_policy' => ! empty( $o['iv_load_policy'] ) ? 3 : 1,
        'controls'       => ! empty( $o['controls'] ) ? 1 : 0,
        'fs'             => ! empty( $o['fs'] ) ? 1 : 0,
        'playsinline'    => 1,
    );

    $html = preg_replace_callback( '/src="([^"]+)"/', function ( $m ) use ( $args ) {
        return 'src="' . esc_url( add_query_arg( $args, html_entity_decode( $m[1] ) ) ) . '"';
    }, $html, 1 );

    if ( false === strpos( $html, 'loading=' ) ) {
        $html = str_replace( '<iframe', '<iframe loading="lazy"', $html );
    }
    return $html;
}

/* =========================================================
 * 5. 前台 CSS（極小，內嵌於 head；全部限定在 .cyp-video 內）
 * ======================================================= */
add_action( 'wp_enqueue_scripts', 'wutm_cyp_enqueue_inline_css' );
function wutm_cyp_enqueue_inline_css() {
    if ( ! wutm_cyp_plyr_active() ) {
        return;
    }
    $o     = wutm_cyp_get_options();
    $color = sanitize_hex_color( $o['brand_color'] );
    $color = $color ? $color : '#000000';

    $css = '.cyp-video{--plyr-color-main:' . $color . ';--cyp-color:' . $color . ';position:relative;width:100%;margin:1.5em 0;border-radius:8px;overflow:hidden;background:#000;aspect-ratio:16/9;box-shadow:0 4px 15px rgba(0,0,0,.1)}'
        . '.cyp-video.cyp-ready{aspect-ratio:auto}'
        . '.cyp-video .cyp-facade{position:absolute;inset:0;z-index:3;display:block;width:100%;height:100%;margin:0;padding:0;border:0;border-radius:0;background:#000;box-shadow:none;cursor:pointer;appearance:none;-webkit-appearance:none}'
        . '.cyp-video .cyp-facade img{display:block;width:100%;height:100%;object-fit:cover;opacity:.95}'
        . '.cyp-video .cyp-facade-btn{position:absolute;left:50%;top:50%;width:68px;height:68px;margin:-34px 0 0 -34px;border-radius:50%;background:var(--cyp-color);transition:transform .15s}'
        . '.cyp-video .cyp-facade-btn:after{content:"";position:absolute;left:27px;top:20px;border-style:solid;border-width:14px 0 14px 22px;border-color:transparent transparent transparent #fff}'
        . '.cyp-video .cyp-facade:hover .cyp-facade-btn,.cyp-video .cyp-facade:focus-visible .cyp-facade-btn{transform:scale(1.08)}'
        . '.cyp-video .cyp-facade.cyp-loading{cursor:progress}'
        . '.cyp-video .cyp-facade.cyp-loading .cyp-facade-btn{opacity:.6}'
        . '.cyp-video .plyr{width:100%}'
        . '.wp-block-embed__wrapper .cyp-video{margin:0}'
        . '.wp-block-embed__wrapper:has(.cyp-video):before{display:none!important}';

    wp_register_style( 'cyp-inline', false, array(), WUTM_CYP_PLYR_VER );
    wp_enqueue_style( 'cyp-inline' );
    wp_add_inline_style( 'cyp-inline', $css );
}

/* =========================================================
 * 6. 前台 JS / Plyr 載入
 * ======================================================= */
function wutm_cyp_plyr_urls() {
    return array(
        'js'  => apply_filters( 'wutm_cyp_plyr_js_url', 'https://cdn.jsdelivr.net/npm/plyr@' . WUTM_CYP_PLYR_VER . '/dist/plyr.min.js' ),
        'css' => apply_filters( 'wutm_cyp_plyr_css_url', 'https://cdn.jsdelivr.net/npm/plyr@' . WUTM_CYP_PLYR_VER . '/dist/plyr.css' ),
    );
}

// 非延遲模式：頁尾以 defer 載入
add_action( 'wp_footer', 'wutm_cyp_footer_enqueue', 5 );
function wutm_cyp_footer_enqueue() {
    if ( ! wutm_cyp_plyr_active() || ! wutm_cyp_needs_assets() ) {
        return;
    }
    $o = wutm_cyp_get_options();
    if ( ! empty( $o['lazy_assets'] ) ) {
        return;
    }
    $u = wutm_cyp_plyr_urls();
    wp_enqueue_style( 'cyp-plyr', $u['css'], array(), WUTM_CYP_PLYR_VER );
    wp_print_styles('cyp-plyr'); // Content discovery occurs after wp_head.
    wp_enqueue_script( 'cyp-plyr', $u['js'], array(), WUTM_CYP_PLYR_VER, true );
}

add_filter( 'script_loader_tag', 'wutm_cyp_defer_tag', 10, 2 );
function wutm_cyp_defer_tag( $tag, $handle ) {
    if ( 'cyp-plyr' === $handle && false === strpos( $tag, ' defer' ) ) {
        return str_replace( ' src=', ' defer src=', $tag );
    }
    return $tag;
}

add_action( 'wp_footer', 'wutm_cyp_footer_loader', 30 );
function wutm_cyp_footer_loader() {
    if ( ! wutm_cyp_plyr_active() || ! wutm_cyp_needs_assets() ) {
        return;
    }
    $o = wutm_cyp_get_options();
    $u = wutm_cyp_plyr_urls();

    $cfg = array(
        'lazy'     => ! empty( $o['lazy_assets'] ) ? 1 : 0,
        'js'       => $u['js'],
        'css'      => $u['css'],
        'bigplay'  => ! empty( $o['bigplay'] ) ? 1 : 0,
        'controls' => ! empty( $o['controls'] ) ? 1 : 0,
        'fs'       => ! empty( $o['fs'] ) ? 1 : 0,
        'rel'      => ! empty( $o['rel'] ) ? 1 : 0,
        'modest'   => ! empty( $o['modestbranding'] ) ? 1 : 0,
        'iv'       => ! empty( $o['iv_load_policy'] ) ? 1 : 0,
        'nocookie' => ! empty( $o['nocookie'] ) ? 1 : 0,
    );

    $js = 'window.cypCfg=' . wp_json_encode( $cfg ) . ';' . wutm_cyp_loader_js();

    if ( function_exists( 'wp_print_inline_script_tag' ) ) {
        wp_print_inline_script_tag( $js, array( 'id' => 'cyp-loader' ) );
    } else {
        echo '<script id="cyp-loader">' . $js . '</script>'; // phpcs:ignore
    }
}

function wutm_cyp_loader_js() {
    return <<<'JS'
(function(){
var C=window.cypCfg||{},P=null,all=[];
function loadCss(){return new Promise(function(r){
  if(document.getElementById('cyp-dynamic-css')||document.getElementById('cyp-plyr-css'))return r();
  var l=document.createElement('link');l.id='cyp-dynamic-css';l.rel='stylesheet';l.href=C.css;l.onload=l.onerror=function(){r()};document.head.appendChild(l);});}
function loadJs(){return new Promise(function(r,j){
  var s=document.createElement('script'),timer=setTimeout(function(){s.remove();j(new Error('播放器載入逾時'));},15000);s.src=C.js;s.async=true;s.onload=function(){clearTimeout(timer);r()};s.onerror=function(){clearTimeout(timer);s.remove();j(new Error('播放器載入失敗'))};document.head.appendChild(s);});}
function ensure(){
  if(P)return P;
  if(window.Plyr){P=loadCss();return P;}
  if(!C.lazy){
    P=new Promise(function(r){
      if(document.readyState!=='loading')return r();
      document.addEventListener('DOMContentLoaded',function(){r()},{once:true});
    }).then(function(){return window.Plyr?loadCss():Promise.all([loadCss(),loadJs()]);}).catch(function(error){P=null;throw error;});
    return P;
  }
  P=Promise.all([loadCss(),loadJs()]).catch(function(error){P=null;throw error;});
  return P;
}
function opts(){
  var ctl=[];
  if(C.controls){
    if(C.bigplay)ctl.push('play-large');
    ctl.push('play','progress','current-time','mute','volume');
    if(C.fs)ctl.push('fullscreen');
  }else if(C.bigplay){ctl.push('play-large');}
  return {
    controls:ctl,hideControls:true,clickToPlay:true,
    tooltips:{controls:false,seek:true},
    fullscreen:{enabled:!!C.fs,iosNative:true},
    youtube:{noCookie:!!C.nocookie,rel:C.rel?0:1,showinfo:0,iv_load_policy:C.iv?3:1,modestbranding:C.modest?1:0}
  };
}
function mountYT(box){
  if(!box||box.__busy)return;
  box.__busy=1;
  var fac=box.querySelector('.cyp-facade');
  box.__html=box.innerHTML;
  if(fac)fac.classList.add('cyp-loading');
  var h;
  ensure().then(function(){
    h=document.createElement('div');
    h.setAttribute('data-plyr-provider','youtube');
    h.setAttribute('data-plyr-embed-id',box.getAttribute('data-id'));
    box.insertBefore(h,box.firstChild);
    var p=new Plyr(h,opts());
    var readyTimer=setTimeout(function(){
      var index=all.indexOf(p);if(index>-1)all.splice(index,1);
      p.destroy(function(){box.innerHTML=box.__html;box.classList.remove('cyp-ready');box.__busy=0;var button=box.querySelector('.cyp-facade');if(button)button.setAttribute('aria-label','影片尚未就緒，點擊重試');});
    },30000);
    all.push(p);
    p.on('play',function(){all.forEach(function(o){if(o!==p&&o.playing)o.pause();});});
    p.once('ready',function(){
      clearTimeout(readyTimer);
      box.classList.add('cyp-ready');
      if(fac&&fac.parentNode)fac.parentNode.removeChild(fac);
      var playing=p.play();if(playing&&playing.catch)playing.catch(function(){});
    });
    p.on('ended',function(){
      clearTimeout(readyTimer);
      var i=all.indexOf(p);if(i>-1)all.splice(i,1);
      p.destroy(function(){
        box.innerHTML=box.__html;
        box.classList.remove('cyp-ready');
        box.__busy=0;
      });
    });
  }).catch(function(){
    if(h&&h.parentNode)h.remove();
    box.__busy=0;
    if(fac){fac.classList.remove('cyp-loading');fac.setAttribute('aria-label','播放器載入失敗，點擊重試');}
  });
}
document.addEventListener('click',function(e){
  var b=e.target.closest&&e.target.closest('.cyp-facade');
  if(b){e.preventDefault();mountYT(b.closest('.cyp-video'));}
});
['pointerover','touchstart','focusin'].forEach(function(n){
  document.addEventListener(n,function(e){
    if(e.target.closest&&e.target.closest('.cyp-facade'))ensure().catch(function(){});
  },{passive:true});
});
})();
JS;
}
