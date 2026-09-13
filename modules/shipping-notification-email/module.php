<?php
/** WooCommerce 出貨通知信。 */
defined('ABSPATH') || exit;

final class WUTM_Shipping_Notification_Email {
    private const OPTION = 'wutm_shipping_notification_email_options';
    private const LEGACY_OPTION = 'wsn_settings';
    private const PAGE = 'wu-shipping-notification-email';
    private const LAST_SENT_META = '_wutm_shipping_email_last_sent';

    private $settings;

    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        $saved = get_option(self::OPTION, null);
        if ($saved === null) $saved = get_option(self::LEGACY_OPTION, array());
        $this->settings = wp_parse_args(is_array($saved) ? $saved : array(), $this->defaults());

        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        add_action('admin_footer', array($this, 'render_preview_modal'));
        add_action('wp_ajax_wutm_sne_preview', array($this, 'ajax_preview'));
        add_action('wp_ajax_wutm_sne_send', array($this, 'ajax_send'));
    }

    private function defaults(): array {
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        return array(
            'shop_name' => $site_name ?: '本店',
            'logo_url' => '',
            'support_email' => is_email($admin_email) ? $admin_email : '',
            'account_url' => $this->default_account_url(),
            'shop_url' => home_url('/'),
            'subject' => '【{shop_name}】您的訂單已出貨通知',
            'body' => $this->default_body(),
        );
    }

    /**
     * Build the account page URL without WordPress rewrite APIs. This module is
     * loaded on plugins_loaded, before $wp_rewrite is guaranteed to exist.
     */
    private function default_account_url(): string {
        $page_id = absint(get_option('woocommerce_myaccount_page_id'));
        $page_path = $page_id ? trim((string) get_page_uri($page_id), '/') : '';
        if ($page_path === '') $page_path = 'my-account';
        return home_url('/' . $page_path . '/');
    }

    private function default_body(): string {
        return <<<'HTML'
<div style="margin-bottom:20px;text-align:center;">{logo_html}</div>

<p>親愛的 {billing_name} 您好：</p>

<p>您於 {shop_name} 的訂單 {order_number} 已成立，商品於 {shipped_date} 寄出，請留意近期收貨。{shop_name} 感謝您的支持！</p>

<p>訂單編號：{order_number}<br>訂單日期：{order_date}</p>

<p><strong>訂購商品：</strong></p>
{items_list}

<p><strong>送貨地點：</strong><br>{shipping_address}</p>

<p>如商品延誤或瑕疵，請透過 <a href="mailto:{support_email}">顧客服務／聯絡我們</a> 反應，我們將儘快為您處理。<br>查看完整訂單內容請至 <a href="{account_url}" target="_blank" rel="noopener">會員中心／訂單查詢</a>。</p>

<p>本封郵件相關內容（如售價、規格、顏色等），若與商品說明網頁不符，則以商品說明網頁為準，恕不另行通知，敬請見諒！</p>

<p>{shop_name}<br><a href="{shop_url}" target="_blank" rel="noopener">{shop_url}</a></p>
HTML;
    }

    public function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '出貨通知信設定',
            '出貨通知信',
            'manage_woocommerce',
            self::PAGE,
            array($this, 'settings_page')
        );
    }

    public function register_settings(): void {
        register_setting('wutm_sne_group', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => $this->defaults(),
        ));
    }

    public function sanitize_settings($input): array {
        $input = is_array($input) ? $input : array();
        $defaults = $this->defaults();
        $email = sanitize_email((string) wp_unslash($input['support_email'] ?? ''));
        return array(
            'shop_name' => sanitize_text_field(wp_unslash($input['shop_name'] ?? $defaults['shop_name'])),
            'logo_url' => esc_url_raw(wp_unslash($input['logo_url'] ?? '')),
            'support_email' => is_email($email) ? $email : $defaults['support_email'],
            'account_url' => esc_url_raw(wp_unslash($input['account_url'] ?? $defaults['account_url'])),
            'shop_url' => esc_url_raw(wp_unslash($input['shop_url'] ?? $defaults['shop_url'])),
            'subject' => sanitize_text_field(wp_unslash($input['subject'] ?? $defaults['subject'])),
            'body' => isset($input['body']) && is_string($input['body']) ? wp_kses_post(wp_unslash($input['body'])) : $defaults['body'],
        );
    }

    public function settings_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理出貨通知信的權限。');
        $o = $this->settings;
        $defaults = $this->defaults();
        ?>
        <div class="wrap wutm-sne-admin">
            <h1>出貨通知信設定</h1>
            <p>設定所有訂單共用的預設信件。實際寄送前，仍可在訂單編輯頁預覽並個別修改收件人、主旨與內容。</p>
            <form method="post" action="options.php">
                <?php settings_fields('wutm_sne_group'); ?>
                <section class="wutm-sne-panel">
                    <h2>店家與連結</h2>
                    <div class="wutm-sne-grid">
                        <label>店家／品牌名稱<input name="<?php echo esc_attr(self::OPTION); ?>[shop_name]" value="<?php echo esc_attr($o['shop_name']); ?>"></label>
                        <label>客服信箱<input type="email" name="<?php echo esc_attr(self::OPTION); ?>[support_email]" value="<?php echo esc_attr($o['support_email']); ?>"></label>
                        <label>Logo 圖片網址（可留空）<input type="url" name="<?php echo esc_attr(self::OPTION); ?>[logo_url]" value="<?php echo esc_attr($o['logo_url']); ?>" placeholder="https://example.com/logo.png"></label>
                        <label>官網網址<input type="url" name="<?php echo esc_attr(self::OPTION); ?>[shop_url]" value="<?php echo esc_attr($o['shop_url']); ?>"></label>
                        <label class="wutm-sne-wide">會員中心／訂單查詢網址<input type="url" name="<?php echo esc_attr(self::OPTION); ?>[account_url]" value="<?php echo esc_attr($o['account_url']); ?>"></label>
                    </div>
                </section>
                <section class="wutm-sne-panel">
                    <h2>預設信件範本</h2>
                    <label class="wutm-sne-subject">信件主旨<input name="<?php echo esc_attr(self::OPTION); ?>[subject]" value="<?php echo esc_attr($o['subject']); ?>"></label>
                    <p><strong>信件內容</strong></p>
                    <?php wp_editor($o['body'], 'wutm_sne_body', array(
                        'textarea_name' => self::OPTION . '[body]',
                        'textarea_rows' => 18,
                        'media_buttons' => false,
                        'teeny' => true,
                        'quicktags' => true,
                    )); ?>
                    <p class="description">可用變數：<code>{shop_name}</code> <code>{billing_name}</code> <code>{order_number}</code> <code>{order_date}</code> <code>{shipped_date}</code> <code>{items_list}</code> <code>{shipping_address}</code> <code>{support_email}</code> <code>{account_url}</code> <code>{shop_url}</code> <code>{logo_html}</code></p>
                    <p><button type="button" class="button" id="wutm-sne-restore">還原為預設範本</button></p>
                </section>
                <?php submit_button('儲存設定'); ?>
            </form>
        </div>
        <style>.wutm-sne-admin{max-width:1180px}.wutm-sne-admin>p{font-size:15px}.wutm-sne-panel{margin:20px 0;padding:22px 26px;border:1px solid #dcdcde;border-radius:9px;background:#fff}.wutm-sne-panel h2{margin:0 0 20px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;font-size:18px}.wutm-sne-grid{display:grid;grid-template-columns:repeat(2,minmax(240px,1fr));gap:20px 28px}.wutm-sne-grid label,.wutm-sne-subject{display:block;font-weight:600}.wutm-sne-grid input,.wutm-sne-subject input{display:block;width:100%;max-width:none;min-height:42px;margin-top:8px}.wutm-sne-wide{grid-column:1/-1}.wutm-sne-subject{margin-bottom:20px}@media(max-width:720px){.wutm-sne-grid{grid-template-columns:1fr}.wutm-sne-wide{grid-column:auto}}</style>
        <script>(function(){const button=document.getElementById('wutm-sne-restore');if(!button)return;button.addEventListener('click',function(){const html=<?php echo wp_json_encode($defaults['body']); ?>,editor=window.tinymce&&tinymce.get('wutm_sne_body'),textarea=document.getElementById('wutm_sne_body');if(editor&&!editor.isHidden())editor.setContent(html);else if(textarea)textarea.value=html;});})();</script>
        <?php
    }

    private function order_screen_ids(): array {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) $screens[] = wc_get_page_screen_id('shop-order');
        return array_values(array_unique(array_filter($screens)));
    }

    public function add_order_meta_box(): void {
        add_meta_box(
            'wutm_sne_order_box',
            '出貨通知信',
            array($this, 'render_order_meta_box'),
            $this->order_screen_ids(),
            'side',
            'high'
        );
    }

    private function normalize_order($post_or_order) {
        if ($post_or_order instanceof WC_Order) return $post_or_order;
        if ($post_or_order instanceof WP_Post) return wc_get_order($post_or_order->ID);
        return false;
    }

    public function render_order_meta_box($post_or_order): void {
        $order = $this->normalize_order($post_or_order);
        if (!$order instanceof WC_Order) {
            echo '<p>無法讀取訂單資料。</p>';
            return;
        }
        $to = $order->get_billing_email();
        $last_sent = $order->get_meta(self::LAST_SENT_META);
        if (!$last_sent) $last_sent = $order->get_meta('_wsn_shipped_email_last_sent');
        ?>
        <div class="wutm-sne-order-box">
            <p style="margin-top:0">收件信箱：<br><code><?php echo esc_html($to ?: '（無帳單信箱）'); ?></code></p>
            <?php if ($last_sent): ?><p style="color:#2271b1">上次寄送時間：<br><?php echo esc_html($last_sent); ?></p><?php endif; ?>
            <button type="button" class="button button-primary wutm-sne-open" style="width:100%" <?php disabled(empty($to)); ?>>預覽並寄出出貨通知</button>
            <?php if (!$to): ?><p class="description">請先在帳單資料填入有效 Email 並儲存訂單。</p><?php endif; ?>
            <p class="wutm-sne-side-result" style="margin-top:8px"></p>
        </div>
        <?php
    }

    private function current_order() {
        global $post, $theorder;
        if (isset($theorder) && $theorder instanceof WC_Order) return $theorder;
        if (isset($post) && $post instanceof WP_Post) {
            $order = wc_get_order($post->ID);
            if ($order) return $order;
        }
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        return $order_id ? wc_get_order($order_id) : false;
    }

    public function render_preview_modal(): void {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, $this->order_screen_ids(), true)) return;
        $order = $this->current_order();
        if (!$order instanceof WC_Order || !current_user_can('edit_shop_orders')) return;
        $order_id = $order->get_id();
        ?>
        <div id="wutm-sne-modal" hidden>
            <div class="wutm-sne-backdrop"></div>
            <div class="wutm-sne-dialog" role="dialog" aria-modal="true" aria-labelledby="wutm-sne-modal-title">
                <h2 id="wutm-sne-modal-title">出貨通知信預覽</h2>
                <label><strong>收件人</strong><input type="email" id="wutm-sne-to" value="<?php echo esc_attr($order->get_billing_email()); ?>"></label>
                <label><strong>主旨</strong><input type="text" id="wutm-sne-subject"></label>
                <p><strong>內容（寄送前可直接修改）</strong></p>
                <?php wp_editor('', 'wutm_sne_preview_body', array(
                    'textarea_name' => 'wutm_sne_preview_body',
                    'textarea_rows' => 16,
                    'media_buttons' => false,
                    'teeny' => true,
                    'quicktags' => true,
                )); ?>
                <p class="wutm-sne-result" role="status" aria-live="polite"></p>
                <div class="wutm-sne-actions"><button type="button" class="button wutm-sne-cancel">取消</button><button type="button" class="button button-primary wutm-sne-send">確認寄出</button></div>
            </div>
        </div>
        <style>#wutm-sne-modal{position:fixed;z-index:999999;inset:0;padding:24px;overflow:auto}#wutm-sne-modal[hidden]{display:none!important}.wutm-sne-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.58)}.wutm-sne-dialog{position:relative;z-index:1;max-width:780px;margin:24px auto;padding:24px;border-radius:8px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.25)}.wutm-sne-dialog h2{margin-top:0}.wutm-sne-dialog>label{display:block;margin:0 0 16px}.wutm-sne-dialog>label strong{display:block;margin-bottom:7px}.wutm-sne-dialog>label input{width:100%;max-width:none;min-height:40px}.wutm-sne-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}.wutm-sne-result.is-success{color:#008a20}.wutm-sne-result.is-error{color:#b32d2e}@media(max-width:600px){#wutm-sne-modal{padding:8px}.wutm-sne-dialog{margin:8px auto;padding:18px}}</style>
        <script>
        (function(){
            const modal=document.getElementById('wutm-sne-modal');if(!modal)return;const openButtons=[...document.querySelectorAll('.wutm-sne-open')],cancel=modal.querySelector('.wutm-sne-cancel'),send=modal.querySelector('.wutm-sne-send'),to=document.getElementById('wutm-sne-to'),subject=document.getElementById('wutm-sne-subject'),result=modal.querySelector('.wutm-sne-result'),orderId=<?php echo (int) $order_id; ?>,nonce=<?php echo wp_json_encode(wp_create_nonce('wutm_sne_order_' . $order_id)); ?>;let opener=null;
            function setContent(html){const editor=window.tinymce&&tinymce.get('wutm_sne_preview_body'),textarea=document.getElementById('wutm_sne_preview_body');if(editor&&!editor.isHidden())editor.setContent(html);else if(textarea)textarea.value=html;}
            function getContent(){const editor=window.tinymce&&tinymce.get('wutm_sne_preview_body'),textarea=document.getElementById('wutm_sne_preview_body');return editor&&!editor.isHidden()?editor.getContent():(textarea?textarea.value:'');}
            function close(){modal.hidden=true;document.body.style.overflow='';if(opener)opener.focus();}
            openButtons.forEach(function(button){button.addEventListener('click',async function(){opener=button;const old=button.textContent;button.disabled=true;button.textContent='載入預覽中…';result.textContent='';const data=new FormData();data.append('action','wutm_sne_preview');data.append('order_id',String(orderId));data.append('nonce',nonce);try{const response=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data}),json=await response.json();if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'載入預覽失敗。');subject.value=json.data.subject;setContent(json.data.body);modal.hidden=false;document.body.style.overflow='hidden';to.focus();}catch(error){alert(error.message||'載入預覽失敗。');}finally{button.disabled=false;button.textContent=old;}});});
            cancel.addEventListener('click',close);modal.querySelector('.wutm-sne-backdrop').addEventListener('click',close);document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!modal.hidden)close();});
            send.addEventListener('click',async function(){if(!to.reportValidity())return;if(!window.confirm('確定要寄出這封出貨通知信嗎？'))return;send.disabled=true;send.textContent='寄送中…';result.className='wutm-sne-result';result.textContent='';const data=new FormData();data.append('action','wutm_sne_send');data.append('order_id',String(orderId));data.append('nonce',nonce);data.append('to',to.value);data.append('subject',subject.value);data.append('body',getContent());try{const response=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data}),json=await response.json();if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'寄送失敗。');result.classList.add('is-success');result.textContent=json.data.message;document.querySelectorAll('.wutm-sne-side-result').forEach(function(el){el.style.color='#008a20';el.textContent=json.data.message;});setTimeout(function(){window.location.reload();},900);}catch(error){result.classList.add('is-error');result.textContent=error.message||'寄送失敗，請稍後再試。';send.disabled=false;send.textContent='確認寄出';}});
        })();
        </script>
        <?php
    }

    private function verify_ajax_order() {
        if (!current_user_can('edit_shop_orders')) wp_send_json_error(array('message' => '權限不足，無法執行此動作。'), 403);
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$order_id || !wp_verify_nonce($nonce, 'wutm_sne_order_' . $order_id)) wp_send_json_error(array('message' => '驗證失敗，請重新整理頁面後再試一次。'), 403);
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) wp_send_json_error(array('message' => '找不到此訂單。'), 404);
        return $order;
    }

    public function ajax_preview(): void {
        $order = $this->verify_ajax_order();
        $rendered = $this->build_email($order);
        wp_send_json_success(array('subject' => $rendered['subject'], 'body' => $rendered['body']));
    }

    public function ajax_send(): void {
        $order = $this->verify_ajax_order();
        $to = isset($_POST['to']) && is_string($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';
        $subject = isset($_POST['subject']) && is_string($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $body = isset($_POST['body']) && is_string($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
        if (!is_email($to)) wp_send_json_error(array('message' => '收件信箱無效，無法寄送。'), 400);
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') wp_send_json_error(array('message' => '主旨與信件內容不可留空。'), 400);

        $lock = 'wutm_sne_sending_' . $order->get_id();
        if (get_transient($lock)) wp_send_json_error(array('message' => '這封信正在寄送中，請勿重複操作。'), 409);
        set_transient($lock, 1, 30);
        $result = $this->send_email($order, $to, $subject, $body);
        delete_transient($lock);
        if (is_wp_error($result)) wp_send_json_error(array('message' => $result->get_error_message()), 500);
        wp_send_json_success(array('message' => sprintf('出貨通知信已成功寄出至 %s', $to)));
    }

    private function build_email(WC_Order $order): array {
        $o = $this->settings;
        $billing_name = trim($order->get_billing_last_name() . $order->get_billing_first_name());
        if ($billing_name === '') $billing_name = $order->get_formatted_billing_full_name();
        $created = $order->get_date_created();
        $address = $order->get_formatted_shipping_address();
        if (!$address) $address = $order->get_formatted_billing_address();
        $shipping_method = (string) $order->get_shipping_method();
        if (strpos($shipping_method, '超商') !== false) $address .= ($address ? '<br>' : '') . '（超商取貨）';

        $items = '<ul>';
        foreach ($order->get_items('line_item') as $item) {
            $items .= '<li>' . esc_html($item->get_name()) . ' × ' . esc_html($item->get_quantity()) . '</li>';
        }
        $items .= '</ul>';
        $logo = $o['logo_url']
            ? '<img src="' . esc_url($o['logo_url']) . '" alt="' . esc_attr($o['shop_name']) . '" style="max-width:200px;height:auto;">'
            : '<strong style="font-size:18px;">' . esc_html($o['shop_name']) . '</strong>';

        $replace = array(
            '{shop_name}' => esc_html($o['shop_name']),
            '{billing_name}' => esc_html($billing_name),
            '{order_number}' => esc_html($order->get_order_number()),
            '{order_date}' => esc_html($created ? wc_format_datetime($created, 'Y/m/d H:i:s') : ''),
            '{shipped_date}' => esc_html(current_time('Y/m/d')),
            '{items_list}' => $items,
            '{shipping_address}' => wp_kses_post($address),
            '{support_email}' => esc_attr($o['support_email']),
            '{account_url}' => esc_url($o['account_url']),
            '{shop_url}' => esc_url($o['shop_url']),
            '{logo_html}' => $logo,
        );
        $subject_replace = array();
        foreach ($replace as $key => $value) {
            $subject_replace[$key] = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
        }
        return array(
            'subject' => sanitize_text_field(strtr($o['subject'], $subject_replace)),
            'body' => wp_kses_post(strtr($o['body'], $replace)),
        );
    }

    private function send_email(WC_Order $order, string $to, string $subject, string $body) {
        $sent = wp_mail($to, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
        $time = current_time('Y-m-d H:i:s');
        if (!$sent) {
            error_log(sprintf('[WUTM shipping email] Failed for order %s.', $order->get_order_number()));
            $order->add_order_note(sprintf('出貨通知信寄送失敗（收件人：%s，時間：%s）', $to, $time), false, true);
            return new WP_Error('wutm_sne_mail_failed', '寄信失敗，請檢查網站 SMTP／寄信設定後再試一次。');
        }
        $order->add_order_note(sprintf('出貨通知信已寄出至 %s（日期：%s）', $to, $time), false, true);
        $order->update_meta_data(self::LAST_SENT_META, $time);
        $order->save();
        return true;
    }
}

new WUTM_Shipping_Notification_Email();
