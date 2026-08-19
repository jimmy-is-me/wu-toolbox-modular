<?php
/**
 * Module: head-footer-code
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
/**
 * Head & Footer Code 模組
 * 輕鬆插入自訂代碼到網站頭部和底部
 */

if (!defined('ABSPATH')) exit;

class WU_Head_Footer_Code {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('wu_head_footer_code_settings', $this->get_default_settings());
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // 前台代碼輸出
        add_action('wp_head', array($this, 'output_head_code'), 1);
        add_action('wp_footer', array($this, 'output_footer_code'), 99);
        add_action('wp_body_open', array($this, 'output_body_open_code'));
        
        // 後台代碼輸出
        add_action('admin_head', array($this, 'output_admin_head_code'), 1);
        add_action('admin_footer', array($this, 'output_admin_footer_code'), 99);
        
        // 登入頁面代碼輸出
        add_action('login_head', array($this, 'output_login_head_code'), 1);
        add_action('login_footer', array($this, 'output_login_footer_code'), 99);
        
        // 條件性輸出鉤子
        add_action('template_redirect', array($this, 'conditional_code_output'));
        
        // 自訂 CSS 功能
        add_action('wp_head', array($this, 'output_frontend_css'), 999);
        add_action('admin_head', array($this, 'output_backend_css'), 999);
        
        // ads.txt 管理
        add_action('init', array($this, 'handle_ads_txt'));
    }
    
    private function get_default_settings() {
        return array(
            'enabled' => false,
            'head_code' => '',
            'footer_code' => '',
            'body_open_code' => '',
            'admin_head_code' => '',
            'admin_footer_code' => '',
            'login_head_code' => '',
            'login_footer_code' => '',
            'homepage_only_code' => '',
            'posts_only_code' => '',
            'pages_only_code' => '',
            'enable_admin' => false,
            'enable_login' => false,
            'enable_conditional' => true,
            'enable_mobile_only' => false,
            'enable_desktop_only' => false,
            'mobile_head_code' => '',
            'mobile_footer_code' => '',
            'desktop_head_code' => '',
            'desktop_footer_code' => '',
            'enable_custom_css' => false,
            'frontend_css' => '',
            'backend_css' => '',
            'enable_ads_txt' => false,
            'ads_txt_content' => ''
        );
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'wumetax-toolkit',
            '自訂程式碼',
            '自訂程式碼',
            'manage_options',
            'wu-head-footer-code',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        if (isset($_POST['preview'])) {
            $this->preview_codes();
        }
        
        $this->settings = get_option('wu_head_footer_code_settings', $this->get_default_settings());
        
        // 載入 CodeMirror 語法高亮
        wp_enqueue_script('wp-codemirror');
        wp_enqueue_style('wp-codemirror');
        wp_enqueue_script('csslint');
        wp_enqueue_script('jshint');
        wp_enqueue_script('jsonlint');
        ?>
        <div class="wrap">
            <h1>自訂程式碼設定</h1>
            
            <div class="notice notice-info">
                <h3>功能說明</h3>
                <p><strong>Head & Footer Code 功能</strong>讓您輕鬆在網站的不同位置插入自訂代碼，非常適合加入追蹤碼、廣告代碼等。</p>
                
                <h4>支援的代碼類型：</h4>
                <ul>
                    <li><strong>HTML 標籤</strong>：&lt;meta&gt;, &lt;link&gt;, &lt;style&gt; 等</li>
                    <li><strong>JavaScript</strong>：Google Analytics, Tag Manager, 廣告追蹤等</li>
                    <li><strong>CSS 樣式</strong>：自訂樣式和樣式覆蓋</li>
                    <li><strong>第三方服務</strong>：Facebook Pixel, TikTok Pixel 等</li>
                </ul>
                
                <h4>插入位置：</h4>
                <ul>
                    <li><strong>&lt;head&gt; 區域</strong>：網站頭部，適合 CSS 和 meta 標籤</li>
                    <li><strong>&lt;/body&gt; 前</strong>：頁面底部，適合 JavaScript 代碼</li>
                    <li><strong>&lt;body&gt; 開始後</strong>：適合某些追蹤代碼</li>
                    <li><strong>條件性插入</strong>：首頁、文章頁、頁面等特定位置</li>
                </ul>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wu_head_footer_code_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用功能</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($this->settings['enabled']); ?>>
                                啟用 Head & Footer Code 功能
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2>前台代碼</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="head_code">Head 區域代碼</label>
                            <p class="description">插入到 &lt;/head&gt; 標籤前</p>
                        </th>
                        <td>
                            <textarea name="head_code" id="head_code" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['head_code']); ?></textarea>
                            <p class="description">適合放置 CSS、meta 標籤、Google Analytics 等</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="body_open_code">Body 開始代碼</label>
                            <p class="description">插入到 &lt;body&gt; 標籤後</p>
                        </th>
                        <td>
                            <textarea name="body_open_code" id="body_open_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['body_open_code']); ?></textarea>
                            <p class="description">適合放置 Google Tag Manager noscript 等</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="footer_code">Footer 區域代碼</label>
                            <p class="description">插入到 &lt;/body&gt; 標籤前</p>
                        </th>
                        <td>
                            <textarea name="footer_code" id="footer_code" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['footer_code']); ?></textarea>
                            <p class="description">適合放置 JavaScript 代碼、像素追蹤等</p>
                        </td>
                    </tr>
                </table>
                
                <h2>條件性代碼</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用條件性插入</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_conditional" value="1" <?php checked($this->settings['enable_conditional']); ?>>
                                啟用條件性代碼插入功能
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="homepage_only_code">首頁專用代碼</label>
                        </th>
                        <td>
                            <textarea name="homepage_only_code" id="homepage_only_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['homepage_only_code']); ?></textarea>
                            <p class="description">只在首頁顯示的代碼</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="posts_only_code">文章頁專用代碼</label>
                        </th>
                        <td>
                            <textarea name="posts_only_code" id="posts_only_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['posts_only_code']); ?></textarea>
                            <p class="description">只在文章頁顯示的代碼</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pages_only_code">頁面專用代碼</label>
                        </th>
                        <td>
                            <textarea name="pages_only_code" id="pages_only_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['pages_only_code']); ?></textarea>
                            <p class="description">只在靜態頁面顯示的代碼</p>
                        </td>
                    </tr>
                </table>
                
                <h2>裝置特定代碼</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用裝置特定代碼</th>
                        <td>
                            <label style="display: block; margin: 5px 0;">
                                <input type="checkbox" name="enable_mobile_only" value="1" <?php checked($this->settings['enable_mobile_only']); ?>>
                                啟用行動裝置專用代碼
                            </label>
                            <label style="display: block; margin: 5px 0;">
                                <input type="checkbox" name="enable_desktop_only" value="1" <?php checked($this->settings['enable_desktop_only']); ?>>
                                啟用桌面裝置專用代碼
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mobile_head_code">行動裝置 Head 代碼</label>
                        </th>
                        <td>
                            <textarea name="mobile_head_code" id="mobile_head_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['mobile_head_code']); ?></textarea>
                            <p class="description">只在行動裝置的 head 區域顯示</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mobile_footer_code">行動裝置 Footer 代碼</label>
                        </th>
                        <td>
                            <textarea name="mobile_footer_code" id="mobile_footer_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['mobile_footer_code']); ?></textarea>
                            <p class="description">只在行動裝置的 footer 區域顯示</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="desktop_head_code">桌面裝置 Head 代碼</label>
                        </th>
                        <td>
                            <textarea name="desktop_head_code" id="desktop_head_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['desktop_head_code']); ?></textarea>
                            <p class="description">只在桌面裝置的 head 區域顯示</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="desktop_footer_code">桌面裝置 Footer 代碼</label>
                        </th>
                        <td>
                            <textarea name="desktop_footer_code" id="desktop_footer_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['desktop_footer_code']); ?></textarea>
                            <p class="description">只在桌面裝置的 footer 區域顯示</p>
                        </td>
                    </tr>
                </table>
                
                <h2>後台與登入頁面</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用後台代碼</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_admin" value="1" <?php checked($this->settings['enable_admin']); ?>>
                                在 WordPress 後台插入代碼
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="admin_head_code">後台 Head 代碼</label>
                        </th>
                        <td>
                            <textarea name="admin_head_code" id="admin_head_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['admin_head_code']); ?></textarea>
                            <p class="description">插入到後台 head 區域</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="admin_footer_code">後台 Footer 代碼</label>
                        </th>
                        <td>
                            <textarea name="admin_footer_code" id="admin_footer_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['admin_footer_code']); ?></textarea>
                            <p class="description">插入到後台 footer 區域</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">啟用登入頁面代碼</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_login" value="1" <?php checked($this->settings['enable_login']); ?>>
                                在登入頁面插入代碼
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="login_head_code">登入頁 Head 代碼</label>
                        </th>
                        <td>
                            <textarea name="login_head_code" id="login_head_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['login_head_code']); ?></textarea>
                            <p class="description">插入到登入頁面 head 區域</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="login_footer_code">登入頁 Footer 代碼</label>
                        </th>
                        <td>
                            <textarea name="login_footer_code" id="login_footer_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['login_footer_code']); ?></textarea>
                            <p class="description">插入到登入頁面 footer 區域</p>
                        </td>
                    </tr>
                </table>
                
                <h2>自訂 CSS 管理</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用自訂 CSS</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_custom_css" value="1" <?php checked($this->settings['enable_custom_css']); ?>>
                                啟用自訂 CSS 功能
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="frontend_css">自訂前台 CSS</label>
                            <p class="description">在所有前台頁面載入的 CSS</p>
                        </th>
                        <td>
                            <textarea name="frontend_css" id="frontend_css" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['frontend_css']); ?></textarea>
                            <p class="description">在所有使用者角色的前台頁面上新增自訂 CSS 樣式</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="backend_css">自訂後台 CSS</label>
                            <p class="description">在所有後台頁面載入的 CSS</p>
                        </th>
                        <td>
                            <textarea name="backend_css" id="backend_css" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($this->settings['backend_css']); ?></textarea>
                            <p class="description">在所有使用者角色的後台頁面上新增自訂 CSS 樣式</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Ads.txt 管理</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">啟用 ads.txt 管理</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_ads_txt" value="1" <?php checked($this->settings['enable_ads_txt']); ?>>
                                啟用 ads.txt 檔案管理功能
                            </label>
                            <p class="description">
                                <strong>ads.txt 檔案位置：</strong> <?php echo esc_url(home_url('/ads.txt')); ?><br>
                                啟用此功能後，ads.txt 檔案將由此工具管理，不需要手動上傳檔案
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ads_txt_content">ads.txt 內容</label>
                            <p class="description">輕鬆編輯您的 ads.txt 內容</p>
                        </th>
                        <td>
                            <textarea name="ads_txt_content" id="ads_txt_content" rows="15" cols="80" class="large-text code" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0
facebook.com, 0000000000000000, DIRECT, c3e20eee3f780d68"><?php echo esc_textarea($this->settings['ads_txt_content']); ?></textarea>
                            <p class="description">
                                每行一個廣告商條目，格式：domain, publisher_id, DIRECT/RESELLER, certification_authority<br>
                                <strong>範例：</strong><br>
                                • google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0<br>
                                • facebook.com, 0000000000000000, DIRECT, c3e20eee3f780d68
                            </p>
                            <?php if (!empty($this->settings['ads_txt_content'])): ?>
                            <div style="margin-top: 10px;">
                                <a href="<?php echo esc_url(home_url('/ads.txt')); ?>" target="_blank" class="button button-secondary">檢視 ads.txt 檔案</a>
                                <button type="button" class="button button-secondary" onclick="validateAdsText()" style="margin-left: 10px;">驗證內容</button>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <?php submit_button('儲存設定', 'primary', 'submit', false); ?>
                    <input type="submit" name="preview" value="預覽代碼" class="button button-secondary" style="margin-left: 10px;">
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // 初始化所有代碼編輯器
                var codeAreas = [
                    'head_code', 'body_open_code', 'footer_code', 'homepage_only_code', 
                    'posts_only_code', 'pages_only_code', 'mobile_head_code', 'mobile_footer_code',
                    'desktop_head_code', 'desktop_footer_code', 'admin_head_code', 'admin_footer_code',
                    'login_head_code', 'login_footer_code', 'frontend_css', 'backend_css', 'ads_txt_content'
                ];
                
                codeAreas.forEach(function(areaId) {
                    var textarea = document.getElementById(areaId);
                    if (textarea) {
                        var editor = wp.codeEditor.initialize(textarea, {
                            codemirror: {
                                mode: 'xml',
                                lineNumbers: true,
                                lineWrapping: true,
                                theme: 'default',
                                indentUnit: 2,
                                tabSize: 2,
                                extraKeys: {
                                    "Tab": function(cm) {
                                        cm.replaceSelection("  ", "end");
                                    }
                                }
                            }
                        });
                    }
                });
            });
            
            // ads.txt 驗證函數
            function validateAdsText() {
                var content = document.getElementById('ads_txt_content').value.trim();
                if (!content) {
                    alert('ads.txt 內容為空');
                    return;
                }
                
                var lines = content.split('\n');
                var errors = [];
                var validCount = 0;
                
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].trim();
                    if (!line || line.startsWith('#')) continue; // 跳過空行和註解
                    
                    var parts = line.split(',');
                    if (parts.length < 3) {
                        errors.push('第 ' + (i + 1) + ' 行格式錯誤：' + line);
                    } else {
                        validCount++;
                    }
                }
                
                if (errors.length > 0) {
                    alert('發現錯誤：\n' + errors.join('\n'));
                } else {
                    alert('ads.txt 內容驗證通過！\n有效條目：' + validCount + ' 個');
                }
            }
            </script>
            
            <hr>
            
            <h2>常用代碼範例</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 300px;">
                        <h3>Google Analytics 4</h3>
                        <textarea readonly rows="8" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_TRACKING_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'GA_TRACKING_ID');
</script></textarea>
                        
                        <h3>Google Tag Manager</h3>
                        <p><strong>Head 區域：</strong></p>
                        <textarea readonly rows="6" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXXXXX');</script>
<!-- End Google Tag Manager --></textarea>
                        
                        <p><strong>Body 開始區域：</strong></p>
                        <textarea readonly rows="4" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) --></textarea>
                    </div>
                    
                    <div style="flex: 1; min-width: 300px;">
                        <h3>Facebook Pixel</h3>
                        <textarea readonly rows="10" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<!-- Facebook Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window,document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
</script>
<noscript>
<img height="1" width="1" 
src="https://www.facebook.com/tr?id=YOUR_PIXEL_ID&ev=PageView&noscript=1"/>
</noscript>
<!-- End Facebook Pixel Code --></textarea>
                        
                        <h3>自訂 CSS</h3>
                        <textarea readonly rows="6" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<style>
/* 自訂 CSS 樣式 */
.custom-header {
    background-color: #333;
    color: white;
    padding: 20px;
}
</style></textarea>
                        
                        <h3>TikTok Pixel</h3>
                        <textarea readonly rows="8" cols="40" style="width: 100%; font-family: monospace; font-size: 12px; background: #f9f9f9;">
<script>
!function (w, d, t) {
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
  ttq.load('YOUR_PIXEL_ID');
  ttq.page();
}(window, document, 'ttq');
</script></textarea>
                    </div>
                </div>
            </div>
            
            <h2>使用說明</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <h3>如何使用：</h3>
                <ol>
                    <li><strong>選擇插入位置</strong>：根據代碼類型選擇合適的插入位置</li>
                    <li><strong>複製貼上代碼</strong>：將追蹤代碼或自訂代碼貼到對應的文本框</li>
                    <li><strong>設定條件</strong>：根據需要啟用條件性插入或裝置特定代碼</li>
                    <li><strong>儲存設定</strong>：點擊「儲存設定」使代碼生效</li>
                    <li><strong>測試驗證</strong>：使用瀏覽器開發者工具驗證代碼是否正確插入</li>
                </ol>
                
                <h3>最佳實踐：</h3>
                <div style="display: flex; gap: 30px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h4>Head 區域適合：</h4>
                        <ul>
                            <li>CSS 樣式表</li>
                            <li>Meta 標籤</li>
                            <li>Google Analytics</li>
                            <li>Google Tag Manager</li>
                            <li>結構化資料</li>
                        </ul>
                    </div>
                    <div style="flex: 1;">
                        <h4>Footer 區域適合：</h4>
                        <ul>
                            <li>JavaScript 代碼</li>
                            <li>追蹤像素</li>
                            <li>Facebook Pixel</li>
                            <li>TikTok Pixel</li>
                            <li>聊天工具</li>
                        </ul>
                    </div>
                </div>
                
                <div style="background: #fffbf0; padding: 15px; border-left: 4px solid #ff8c00; margin-top: 20px;">
                    <h4>安全提醒：</h4>
                    <ul>
                        <li>只插入來源可信的代碼</li>
                        <li>測試代碼不會影響網站性能</li>
                        <li>定期檢查和更新追蹤代碼</li>
                        <li>備份現有設定</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <style>
        .form-table th {
            width: 200px;
            vertical-align: top;
        }
        .large-text.code {
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 13px;
        }
        .notice {
            padding: 10px;
            margin: 15px 0;
            border-left: 4px solid;
            background: #fff;
        }
        .notice-info {
            border-left-color: #0073aa;
        }
        </style>
        <?php
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_head_footer_code_settings')) {
            wp_die('安全驗證失敗');
        }
        
        $settings = array(
            'enabled' => isset($_POST['enabled']),
            'head_code' => wp_unslash($_POST['head_code']),
            'footer_code' => wp_unslash($_POST['footer_code']),
            'body_open_code' => wp_unslash($_POST['body_open_code']),
            'admin_head_code' => wp_unslash($_POST['admin_head_code']),
            'admin_footer_code' => wp_unslash($_POST['admin_footer_code']),
            'login_head_code' => wp_unslash($_POST['login_head_code']),
            'login_footer_code' => wp_unslash($_POST['login_footer_code']),
            'homepage_only_code' => wp_unslash($_POST['homepage_only_code']),
            'posts_only_code' => wp_unslash($_POST['posts_only_code']),
            'pages_only_code' => wp_unslash($_POST['pages_only_code']),
            'enable_admin' => isset($_POST['enable_admin']),
            'enable_login' => isset($_POST['enable_login']),
            'enable_conditional' => isset($_POST['enable_conditional']),
            'enable_mobile_only' => isset($_POST['enable_mobile_only']),
            'enable_desktop_only' => isset($_POST['enable_desktop_only']),
            'mobile_head_code' => wp_unslash($_POST['mobile_head_code']),
            'mobile_footer_code' => wp_unslash($_POST['mobile_footer_code']),
            'desktop_head_code' => wp_unslash($_POST['desktop_head_code']),
            'desktop_footer_code' => wp_unslash($_POST['desktop_footer_code']),
            'enable_custom_css' => isset($_POST['enable_custom_css']),
            'frontend_css' => wp_unslash($_POST['frontend_css']),
            'backend_css' => wp_unslash($_POST['backend_css']),
            'enable_ads_txt' => isset($_POST['enable_ads_txt']),
            'ads_txt_content' => wp_unslash($_POST['ads_txt_content'])
        );
        
        update_option('wu_head_footer_code_settings', $settings);
        $this->settings = $settings;
        
        echo '<div class="notice notice-success"><p>設定已儲存！</p></div>';
    }
    
    private function preview_codes() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wu_head_footer_code_settings')) {
            wp_die('安全驗證失敗');
        }
        
        echo '<div class="notice notice-info"><h3>代碼預覽</h3>';
        
        if (!empty($_POST['head_code'])) {
            echo '<h4>Head 代碼：</h4>';
            echo '<pre style="background: #f9f9f9; padding: 10px; overflow-x: auto;">' . esc_html($_POST['head_code']) . '</pre>';
        }
        
        if (!empty($_POST['body_open_code'])) {
            echo '<h4>Body 開始代碼：</h4>';
            echo '<pre style="background: #f9f9f9; padding: 10px; overflow-x: auto;">' . esc_html($_POST['body_open_code']) . '</pre>';
        }
        
        if (!empty($_POST['footer_code'])) {
            echo '<h4>Footer 代碼：</h4>';
            echo '<pre style="background: #f9f9f9; padding: 10px; overflow-x: auto;">' . esc_html($_POST['footer_code']) . '</pre>';
        }
        
        echo '</div>';
    }
    
    public function output_head_code() {
        if (!$this->settings['enabled']) {
            return;
        }
        
        // 基本 head 代碼
        if (!empty($this->settings['head_code'])) {
            echo "\n<!-- WU Head Code -->\n";
            echo $this->settings['head_code'];
            echo "\n<!-- End WU Head Code -->\n";
        }
        
        // 裝置特定代碼
        if ($this->settings['enable_mobile_only'] && $this->is_mobile() && !empty($this->settings['mobile_head_code'])) {
            echo "\n<!-- WU Mobile Head Code -->\n";
            echo $this->settings['mobile_head_code'];
            echo "\n<!-- End WU Mobile Head Code -->\n";
        }
        
        if ($this->settings['enable_desktop_only'] && !$this->is_mobile() && !empty($this->settings['desktop_head_code'])) {
            echo "\n<!-- WU Desktop Head Code -->\n";
            echo $this->settings['desktop_head_code'];
            echo "\n<!-- End WU Desktop Head Code -->\n";
        }
    }
    
    public function output_footer_code() {
        if (!$this->settings['enabled']) {
            return;
        }
        
        // 基本 footer 代碼
        if (!empty($this->settings['footer_code'])) {
            echo "\n<!-- WU Footer Code -->\n";
            echo $this->settings['footer_code'];
            echo "\n<!-- End WU Footer Code -->\n";
        }
        
        // 裝置特定代碼
        if ($this->settings['enable_mobile_only'] && $this->is_mobile() && !empty($this->settings['mobile_footer_code'])) {
            echo "\n<!-- WU Mobile Footer Code -->\n";
            echo $this->settings['mobile_footer_code'];
            echo "\n<!-- End WU Mobile Footer Code -->\n";
        }
        
        if ($this->settings['enable_desktop_only'] && !$this->is_mobile() && !empty($this->settings['desktop_footer_code'])) {
            echo "\n<!-- WU Desktop Footer Code -->\n";
            echo $this->settings['desktop_footer_code'];
            echo "\n<!-- End WU Desktop Footer Code -->\n";
        }
    }
    
    public function output_body_open_code() {
        if (!$this->settings['enabled'] || empty($this->settings['body_open_code'])) {
            return;
        }
        
        echo "\n<!-- WU Body Open Code -->\n";
        echo $this->settings['body_open_code'];
        echo "\n<!-- End WU Body Open Code -->\n";
    }
    
    public function output_admin_head_code() {
        if (!$this->settings['enabled'] || !$this->settings['enable_admin'] || empty($this->settings['admin_head_code'])) {
            return;
        }
        
        echo "\n<!-- WU Admin Head Code -->\n";
        echo $this->settings['admin_head_code'];
        echo "\n<!-- End WU Admin Head Code -->\n";
    }
    
    public function output_admin_footer_code() {
        if (!$this->settings['enabled'] || !$this->settings['enable_admin'] || empty($this->settings['admin_footer_code'])) {
            return;
        }
        
        echo "\n<!-- WU Admin Footer Code -->\n";
        echo $this->settings['admin_footer_code'];
        echo "\n<!-- End WU Admin Footer Code -->\n";
    }
    
    public function output_login_head_code() {
        if (!$this->settings['enabled'] || !$this->settings['enable_login'] || empty($this->settings['login_head_code'])) {
            return;
        }
        
        echo "\n<!-- WU Login Head Code -->\n";
        echo $this->settings['login_head_code'];
        echo "\n<!-- End WU Login Head Code -->\n";
    }
    
    public function output_login_footer_code() {
        if (!$this->settings['enabled'] || !$this->settings['enable_login'] || empty($this->settings['login_footer_code'])) {
            return;
        }
        
        echo "\n<!-- WU Login Footer Code -->\n";
        echo $this->settings['login_footer_code'];
        echo "\n<!-- End WU Login Footer Code -->\n";
    }
    
    public function conditional_code_output() {
        if (!$this->settings['enabled'] || !$this->settings['enable_conditional']) {
            return;
        }
        
        // 首頁專用代碼
        if (is_front_page() && !empty($this->settings['homepage_only_code'])) {
            add_action('wp_footer', function() {
                echo "\n<!-- WU Homepage Only Code -->\n";
                echo $this->settings['homepage_only_code'];
                echo "\n<!-- End WU Homepage Only Code -->\n";
            }, 98);
        }
        
        // 文章頁專用代碼
        if (is_single() && !empty($this->settings['posts_only_code'])) {
            add_action('wp_footer', function() {
                echo "\n<!-- WU Posts Only Code -->\n";
                echo $this->settings['posts_only_code'];
                echo "\n<!-- End WU Posts Only Code -->\n";
            }, 98);
        }
        
        // 頁面專用代碼
        if (is_page() && !empty($this->settings['pages_only_code'])) {
            add_action('wp_footer', function() {
                echo "\n<!-- WU Pages Only Code -->\n";
                echo $this->settings['pages_only_code'];
                echo "\n<!-- End WU Pages Only Code -->\n";
            }, 98);
        }
    }
    
    private function is_mobile() {
        if (function_exists('wp_is_mobile')) {
            return wp_is_mobile();
        }
        
        // 簡單的行動裝置檢測
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone/i', $user_agent);
    }
    
    // 輸出前台 CSS
    public function output_frontend_css() {
        if (!$this->settings['enable_custom_css'] || empty($this->settings['frontend_css'])) {
            return;
        }
        
        echo "\n<!-- WU Custom Frontend CSS -->\n";
        echo '<style type="text/css">' . "\n";
        echo $this->settings['frontend_css'];
        echo "\n</style>\n";
        echo "<!-- End WU Custom Frontend CSS -->\n";
    }
    
    // 輸出後台 CSS
    public function output_backend_css() {
        if (!$this->settings['enable_custom_css'] || empty($this->settings['backend_css'])) {
            return;
        }
        
        echo "\n<!-- WU Custom Backend CSS -->\n";
        echo '<style type="text/css">' . "\n";
        echo $this->settings['backend_css'];
        echo "\n</style>\n";
        echo "<!-- End WU Custom Backend CSS -->\n";
    }
    
    // 處理 ads.txt 請求
    public function handle_ads_txt() {
        if (!$this->settings['enable_ads_txt']) {
            return;
        }
        
        // 檢查是否是 ads.txt 請求
        if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/ads.txt') {
            $content = trim($this->settings['ads_txt_content']);
            
            if (!empty($content)) {
                // 設定正確的內容類型
                header('Content-Type: text/plain; charset=utf-8');
                header('X-Robots-Tag: noindex');
                
                // 輸出 ads.txt 內容
                echo $content;
                exit;
            } else {
                // 如果沒有內容，返回 404
                status_header(404);
                exit;
            }
        }
    }
}

// 初始化模組
new WU_Head_Footer_Code();
