<?php
/**
 * Module: woocommerce-optimizer
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * WooCommerce 優化器模組
 * 功能:清理和優化 WooCommerce 設定
 * 版本:4.3 - 新增電子發票功能
 */

if (!defined('ABSPATH')) exit;

class WU_WooCommerce_Optimizer {
    
    private $commerce;
    public function __construct($commerce = false) {
        $this->commerce = $commerce;
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        if (!$this->commerce) {
        add_action('admin_footer', array($this, 'marketing_link'));
        add_action('admin_head', array($this, 'admin_visibility_styles'));
        add_filter('admin_footer_text', array($this, 'footer_text'), PHP_INT_MAX);
        add_filter('woocommerce_settings_tabs_array', array($this, 'hide_settings_tabs'), PHP_INT_MAX);
        }
        $this->load_optimizations();
    }
    

    private function admin_visibility_options() {
        return array(
            'wu_woo_hide_home' => '隱藏 WC 首頁',
            'wu_woo_hide_extensions' => '隱藏擴充功能',
            'wu_woo_hide_status' => '隱藏狀態',
            'wu_woo_hide_pos' => '隱藏銷售時點情報系統',
            'wu_woo_hide_advanced' => '隱藏進階',
            'wu_woo_hide_marketing' => '隱藏行銷概觀',
            'wu_woo_hide_integration' => '隱藏整合',
            'wu_woo_hide_embedded_primary' => '隱藏 WooCommerce 嵌入式推廣區塊',
            'wu_woo_hide_more_payments' => '隱藏更多付款選項',
            'wu_woo_hide_official_payments' => '隱藏官方付款推薦（不會停用已啟用金流）',
            'wu_woo_hide_payments_menu' => '隱藏主選單付款',
            'wu_woo_hide_reports' => '隱藏報表',
            'wu_woo_custom_footer' => '頁尾顯示 Woocommerce X Wumetax（預設開啟）'
        );
    }

    public function visibility_field($args) {
        $option = $args['option'];
        echo '<input type="hidden" name="' . esc_attr($option) . '" value="0">';
        echo '<label><input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked(1, get_option($option, $option === 'wu_woo_custom_footer'), false) . '> ' . esc_html($args['label']) . '</label>';
    }

    // CSS-only: leave menu registrations intact for WordPress page access checks.
    public function visibility_css() {
        $rules = array(
            'wu_woo_hide_home' => '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=wc-admin"]),#adminmenu .wp-submenu li:has(>a[href="admin.php?page=wc-admin&path=%2F"])',
            'wu_woo_hide_extensions' => '#adminmenu .wp-submenu li:has(>a[href*="path=%2Fextensions"]),#adminmenu .wp-submenu li:has(>a[href*="path=/extensions"]),#adminmenu .wp-submenu li:has(>a[href="admin.php?page=wc-addons"])',
            'wu_woo_hide_status' => '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=wc-status"])',
            'wu_woo_hide_reports' => '#adminmenu .wp-submenu li:has(>a[href="admin.php?page=wc-reports"])',
            'wu_woo_hide_marketing' => '#adminmenu .wp-submenu li:has(>a[href*="path=%2Fmarketing"]),#adminmenu .wp-submenu li:has(>a[href*="path=/marketing"])',
            'wu_woo_hide_payments_menu' => '#adminmenu>li:has(>a[href*="page=wc-admin&task=payments"]),#adminmenu>li:has(>a[href*="page=wc-admin&task=woocommerce-payments"]),#adminmenu>li:has(>a[href*="page=wc-settings&tab=checkout"]),#adminmenu>li:has(>a[href="admin.php?page=wc-admin&path=%2Fpayments"])'
        );
        $css = '';
        foreach ($rules as $option => $selector) {
            if (get_option($option, false)) $css .= $selector . '{display:none!important;}';
        }
        if ($this->is_wc_screen()) {
            if (get_option('wu_woo_hide_embedded_primary', false)) $css .= '.woocommerce-embedded-layout__primary{display:none!important;}';
            if (get_option('wu_woo_hide_more_payments', false)) $css .= '.more-payment-options{display:none!important;}';
            if (get_option('wu_woo_hide_official_payments', false)) $css .= '.settings-payment-gateways__list .sortable-item:has([id^="_wc_pes_"]){display:none!important;}';
        }
        return $css;
    }

    public function admin_visibility_styles() {
        $css = $this->visibility_css();
        if ($css !== '') echo '<style id="wutm-wc-visibility">' . $css . '</style>';
    }

    private function is_wc_screen() {
        $screen = get_current_screen();
        if (!$screen) return false;
        return strpos($screen->id, 'woocommerce') !== false ||
            strpos($screen->id, 'wc-') !== false ||
            in_array($screen->post_type, array('product', 'shop_order', 'shop_coupon'), true);
    }

    public function footer_text($text) {
        return $this->is_wc_screen() && get_option('wu_woo_custom_footer', true)
            ? 'Woocommerce X Wumetax' : $text;
    }

    public function hide_settings_tabs($tabs) {
        if (get_option('wu_woo_hide_pos', false)) {
            unset($tabs['point-of-sale']);
        }
        if (get_option('wu_woo_hide_advanced', false)) {
            unset($tabs['advanced']);
        }
        if (get_option('wu_woo_hide_integration', false)) unset($tabs['integration']);
        return $tabs;
    }


    public function marketing_link() {
        if (!get_option('wu_woo_hide_marketing', false)) return;
        ?>
        <script>
        (function(){
            var coupon = <?php echo wp_json_encode(admin_url('edit.php?post_type=shop_coupon')); ?>;
            document.querySelectorAll('#adminmenu > li > a').forEach(function(link){
                var url = new URL(link.href, location.href);
                if (url.searchParams.get('page') === 'wc-admin' && url.searchParams.get('path') === '/marketing') {
                    link.href = coupon;
                }
            });
        })();
        </script>
        <?php
    }

    private function page_slug() { return $this->commerce ? 'wu-wc-optimization-tools' : 'wu-woocommerce-optimizer'; }
    private function page_title() { return $this->commerce ? 'WC優化工具' : '隱藏WC工具'; }
    private function settings_group() { return $this->commerce ? 'wu_wc_optimization_settings' : 'wu_woocommerce_settings'; }
    private function fields() {
        return $this->commerce ? array(
            'wu_woo_taiwan_address' => array('台灣地址選單優化', 'taiwan_address_callback'),
            'wu_woo_enable_island_shipping' => array('啟用台灣離島運送', 'enable_island_shipping_callback'),
            'wu_woo_enable_711_shipping' => array('啟用 7-11 超商取貨', 'enable_711_shipping_callback'),
            'wu_woo_enable_order_comments' => array('啟用訂單備註欄位', 'enable_order_comments_callback'),
            'wu_woo_enable_einvoice' => array('啟用電子發票功能', 'enable_einvoice_callback')
        ) : array(
            'wu_woo_disable_notifications' => array('停用 WooCommerce 通知欄', 'disable_notifications_callback'),
            'wu_woo_show_sales_with_offset' => array('顯示商品購買量（含管理員調整）', 'sales_with_offset_callback')
        );
    }
    public function add_admin_menu() {
        add_submenu_page('wu-toolbox-modular', $this->page_title(), $this->page_title(), 'manage_options', $this->page_slug(), array($this, 'admin_page'));
    }
    public function admin_init() {
        $group = $this->settings_group();
        add_settings_section($group, $this->page_title(), array($this, 'settings_section_callback'), $group);
        foreach ($this->fields() as $option => $field) {
            add_settings_field($option, $field[0], array($this, $field[1]), $group, $group);
        }
        if (!$this->commerce) {
            foreach ($this->admin_visibility_options() as $option => $label) {
                add_settings_field($option, $label, array($this, 'visibility_field'), $group, $group, array('option'=>$option,'label'=>$label));
            }
        }
    }

    public function settings_section_callback() {
        if ($this->commerce) { echo '<p>選擇需要啟用的結帳及運送功能。7-11 為門市自填方式；電子發票為訂單欄位紀錄，並非串接發票開立服務。</p>'; return; }
        echo '<p>配置 WooCommerce 優化選項。所有功能均需手動啟用。隱藏選項僅整理後台選單或設定分頁，不停用相關功能、不改變權限，也不影響前台購物與結帳；取消勾選並儲存即可恢復。</p>';
    }
    
    public function disable_notifications_callback() {
        $value = get_option('wu_woo_disable_notifications', false);
        echo '<input type="checkbox" id="wu_woo_disable_notifications" name="wu_woo_disable_notifications" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_disable_notifications">停用 WooCommerce 通知欄</label>';
        echo '<p class="description">隱藏 WooCommerce 管理介面通知標題欄。</p>';
    }
    
    public function sales_with_offset_callback() {
        $value = get_option('wu_woo_show_sales_with_offset', false);
        echo '<input type="checkbox" id="wu_woo_show_sales_with_offset" name="wu_woo_show_sales_with_offset" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_show_sales_with_offset">在商品頁顯示購買量</label>';
        echo '<p class="description">可透過商品自訂欄位「_wu_sales_offset」設定調整值。</p>';
    }
    
    public function taiwan_address_callback() {
        $value = get_option('wu_woo_taiwan_address', false);
        echo '<input type="checkbox" id="wu_woo_taiwan_address" name="wu_woo_taiwan_address" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_taiwan_address">啟用台灣地址下拉選單</label>';
        echo '<p class="description">縣市/鄉鎮市區/郵遞區號自動關聯。</p>';
    }
    
    public function enable_island_shipping_callback() {
        $value = get_option('wu_woo_enable_island_shipping', false);
        echo '<input type="checkbox" id="wu_woo_enable_island_shipping" name="wu_woo_enable_island_shipping" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_enable_island_shipping">啟用台灣離島運送選項</label>';
        echo '<p class="description"><strong>重要:</strong>啟用後,結帳頁面會顯示「台灣本島」或「台灣離島」選項。請確保已在 WooCommerce 運送區域設定離島運費。</p>';
    }
    
    public function enable_711_shipping_callback() {
        $value = get_option('wu_woo_enable_711_shipping', false);
        $cost = get_option('wu_woo_711_shipping_cost', 60);
        $threshold = get_option('wu_woo_711_free_shipping_threshold', 0);
        
        echo '<input type="checkbox" id="wu_woo_enable_711_shipping" name="wu_woo_enable_711_shipping" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_enable_711_shipping">啟用 7-11 超商取貨運送方式</label>';
        echo '<p class="description"><strong>重要:</strong>啟用後會自動新增「7-11 超商取貨」運送方式,適用於台灣本島及離島。</p>';
        
        echo '<div style="margin-top:15px;padding:15px;background:#f8f8f8;border-left:4px solid #2271b1;">';
        echo '<p style="margin:0 0 10px 0;"><strong>運費設定</strong></p>';
        echo '<label for="wu_woo_711_shipping_cost">7-11 運費 (NT$): </label>';
        echo '<input type="number" id="wu_woo_711_shipping_cost" name="wu_woo_711_shipping_cost" value="' . esc_attr($cost) . '" min="0" step="1" style="width:100px;" />';
        echo '<p class="description" style="margin:5px 0 15px 0;">預設 NT$60</p>';
        
        echo '<label for="wu_woo_711_free_shipping_threshold">免運門檻 (NT$): </label>';
        echo '<input type="number" id="wu_woo_711_free_shipping_threshold" name="wu_woo_711_free_shipping_threshold" value="' . esc_attr($threshold) . '" min="0" step="1" style="width:100px;" />';
        echo '<p class="description" style="margin:5px 0 0 0;">訂單金額達到此金額則免運費,設為 0 表示不提供免運</p>';
        echo '</div>';
    }
    
    public function enable_order_comments_callback() {
        $value = get_option('wu_woo_enable_order_comments', false);
        echo '<input type="checkbox" id="wu_woo_enable_order_comments" name="wu_woo_enable_order_comments" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_enable_order_comments">顯示訂單備註欄位</label>';
        echo '<p class="description">啟用後,結帳頁面會顯示「訂單備註」欄位供顧客填寫。</p>';
    }
    
    public function enable_einvoice_callback() {
        $value = get_option('wu_woo_enable_einvoice', false);
        echo '<input type="checkbox" id="wu_woo_enable_einvoice" name="wu_woo_enable_einvoice" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wu_woo_enable_einvoice">啟用電子發票功能</label>';
        echo '<p class="description">啟用後,結帳頁面會顯示電子發票類型選擇(個人/公司/捐贈)。</p>';
    }
    
    private function load_optimizations() {
        if (!$this->commerce) {
        if (get_option('wu_woo_disable_notifications')) {
            add_action('admin_init', array($this, 'disable_notifications'));
        }
        
        if (get_option('wu_woo_show_sales_with_offset')) {
            add_action('init', array($this, 'register_sales_features'));
        }
        
        return;
        }
        if (get_option('wu_woo_taiwan_address')) {
            add_action('init', array($this, 'register_taiwan_address'));
        }
        
        if (get_option('wu_woo_enable_711_shipping')) {
            add_action('init', array($this, 'register_711_shipping'));
        }
        
        if (get_option('wu_woo_enable_einvoice')) {
            add_action('init', array($this, 'register_einvoice'));
        }
        
        // 訂單備註欄位控制
        if (!get_option('wu_woo_enable_order_comments', false)) {
            add_filter('woocommerce_enable_order_notes_field', '__return_false');
        }
        
        // 載入錯誤訊息樣式
        add_action('wp_enqueue_scripts', array($this, 'enqueue_error_message_styles'), 999);
        
        // 修改「商品明細」標題
        add_filter('gettext', array($this, 'change_order_review_heading'), 20, 3);
    }
    
    public function change_order_review_heading($translated_text, $text, $domain) {
        if ($domain === 'woocommerce' && $text === 'Your order') {
            return '商品明細';
        }
        return $translated_text;
    }
    
    public function disable_notifications() {
        add_action('admin_head', function() {
            echo '<style>.woocommerce-layout__header,.woocommerce-admin-notices,.wc-admin-notice,#woocommerce-admin-notices{display:none!important}</style>';
        });
    }
    
    public function register_sales_features() {
        add_action('woocommerce_single_product_summary', function(){
            global $product;
            if (!$product) return;
            $count = $this->get_product_sales_with_offset($product->get_id());
            echo '<div class="wu-sales-count" style="opacity:.85;font-size:.9em;">已售出:' . intval($count) . '</div>';
        }, 11);
        
        add_shortcode('wu_sales', function($atts){
            $atts = shortcode_atts(array('id'=>0), $atts, 'wu_sales');
            $id = intval($atts['id']);
            if (!$id) return '0';
            return intval($this->get_product_sales_with_offset($id));
        });
        
        add_action('woocommerce_product_options_general_product_data', function(){
            woocommerce_wp_text_input(array(
                'id' => '_wu_sales_offset',
                'label' => '購買量調整',
                'type' => 'number',
                'description' => '真實購買量 + 調整值'
            ));
        });
        
        add_action('woocommerce_admin_process_product_object', function($product){
            if (isset($_POST['_wu_sales_offset'])) {
                $product->update_meta_data('_wu_sales_offset', intval($_POST['_wu_sales_offset']));
            }
        });
    }
    
    private function get_product_sales_with_offset($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return 0;
        $real = intval($product->get_total_sales());
        $offset = intval(get_post_meta($product_id, '_wu_sales_offset', true));
        return max(0, $real + $offset);
    }
    
    /**
     * 註冊電子發票功能
     */
    public function register_einvoice() {
        add_action('woocommerce_after_order_notes', array($this, 'add_einvoice_fields'));
        add_action('wp_footer', array($this, 'add_einvoice_script'));
        add_action('woocommerce_checkout_process', array($this, 'validate_einvoice_fields'));
        add_action('woocommerce_checkout_create_order', array($this, 'save_einvoice_fields'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_einvoice_in_admin'));
        
        // AJAX 查詢公司名稱
        add_action('wp_ajax_get_company_name', array($this, 'ajax_get_company_name'));
        add_action('wp_ajax_nopriv_get_company_name', array($this, 'ajax_get_company_name'));
    }
    
    /**
     * 添加電子發票欄位到結帳頁面
     */
    public function add_einvoice_fields($checkout) {
        echo '<div id="einvoice_fields" style="margin-top:20px;padding:20px;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;">';
        echo '<h3 style="margin-top:0;">電子發票資訊</h3>';

        woocommerce_form_field('einvoice_type', array(
            'type' => 'select',
            'label' => '發票類型',
            'required' => true,
            'class' => array('form-row-wide'),
            'options' => array(
                '' => '請選擇發票類型',
                'personal' => '個人',
                'company' => '公司',
                'donate' => '捐贈'
            )
        ), $checkout->get_value('einvoice_type'));

        // 個人發票欄位
        echo '<div id="einvoice_personal" style="display:none;">';
        woocommerce_form_field('einvoice_carrier_type', array(
            'type' => 'select',
            'label' => '載具類型',
            'required' => false,
            'class' => array('form-row-wide'),
            'options' => array(
                'mobile' => '手機載具',
                'paper' => '紙本發票'
            )
        ), $checkout->get_value('einvoice_carrier_type'));

        woocommerce_form_field('einvoice_mobile_carrier', array(
            'type' => 'text',
            'label' => '手機載具',
            'required' => false,
            'placeholder' => '請輸入手機載具條碼 (例如:/ABCD123)',
            'class' => array('form-row-wide'),
            'custom_attributes' => array('pattern' => '^/[A-Z0-9.+-]{7}$')
        ), $checkout->get_value('einvoice_mobile_carrier'));
        echo '</div>';

        // 公司發票欄位
        echo '<div id="einvoice_company" style="display:none;">';
        woocommerce_form_field('einvoice_tax_id', array(
            'type' => 'text',
            'label' => '統一編號',
            'required' => false,
            'placeholder' => '請輸入8位數統一編號',
            'class' => array('form-row-wide'),
            'custom_attributes' => array('pattern' => '[0-9]{8}', 'maxlength' => '8')
        ), $checkout->get_value('einvoice_tax_id'));

        woocommerce_form_field('einvoice_company_name', array(
            'type' => 'text',
            'label' => '公司名稱',
            'required' => false,
            'placeholder' => '請輸入公司名稱',
            'class' => array('form-row-wide')
        ), $checkout->get_value('einvoice_company_name'));
        
        echo '<p class="description" style="color:#c62828;background:#ffebee;padding:8px;border-radius:4px;font-size:12px;margin-top:-10px;">輸入統一編號後將自動查詢公司名稱</p>';
        echo '</div>';

        // 捐贈發票欄位
        echo '<div id="einvoice_donate" style="display:none;">';
        woocommerce_form_field('einvoice_donate_code', array(
            'type' => 'text',
            'label' => '捐贈碼',
            'required' => false,
            'placeholder' => '請輸入3-7位數捐贈碼',
            'class' => array('form-row-wide'),
            'custom_attributes' => array('pattern' => '[0-9]{3,7}')
        ), $checkout->get_value('einvoice_donate_code'));
        echo '</div>';

        echo '</div>';
    }
    
    /**
     * JavaScript 控制電子發票欄位顯示/隱藏
     */
    public function add_einvoice_script() {
        if (!is_checkout()) return;
        ?>
        <script>
        jQuery(function($){
            function toggleEinvoiceFields(){
                let type = $('#einvoice_type').val();
                $('#einvoice_personal, #einvoice_company, #einvoice_donate').hide();
                
                if(type === 'personal'){
                    $('#einvoice_personal').slideDown();
                    toggleCarrierFields();
                } else if(type === 'company'){
                    $('#einvoice_company').slideDown();
                } else if(type === 'donate'){
                    $('#einvoice_donate').slideDown();
                }
            }
            
            function toggleCarrierFields(){
                let carrierType = $('#einvoice_carrier_type').val();
                if(carrierType === 'mobile'){
                    $('#einvoice_mobile_carrier_field').slideDown();
                } else {
                    $('#einvoice_mobile_carrier_field').slideUp();
                }
            }
            
            // 監聽發票類型變更
            $(document).on('change', '#einvoice_type', toggleEinvoiceFields);
            
            // 監聽載具類型變更
            $(document).on('change', '#einvoice_carrier_type', toggleCarrierFields);
            
            // 監聽統一編號變更 - 自動查詢公司名稱
            var taxIdTimer;
            $(document).on('input', '#einvoice_tax_id', function(){
                clearTimeout(taxIdTimer);
                let taxId = $(this).val().trim();
                if(taxId.length === 8){
                    taxIdTimer = setTimeout(function(){
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'get_company_name',
                            tax_id: taxId
                        }, function(response){
                            if(response.success && response.data.company_name){
                                $('#einvoice_company_name').val(response.data.company_name);
                            }
                        });
                    }, 500);
                }
            });
            
            $(document.body).on('updated_checkout', toggleEinvoiceFields);
            toggleEinvoiceFields();
        });
        </script>
        <?php
    }
    
    /**
     * 驗證電子發票欄位
     */
    public function validate_einvoice_fields() {
        if (empty($_POST['einvoice_type'])) {
            wc_add_notice('請選擇發票類型', 'error');
            return;
        }
        
        $type = sanitize_text_field($_POST['einvoice_type']);
        
        if ($type === 'personal') {
            $carrier_type = isset($_POST['einvoice_carrier_type']) ? sanitize_text_field($_POST['einvoice_carrier_type']) : '';
            if ($carrier_type === 'mobile') {
                if (empty($_POST['einvoice_mobile_carrier'])) {
                    wc_add_notice('請輸入手機載具', 'error');
                } else {
                    $carrier = sanitize_text_field($_POST['einvoice_mobile_carrier']);
                    if (!preg_match('/^\/[A-Z0-9.+-]{7}$/', $carrier)) {
                        wc_add_notice('手機載具格式不正確 (例如:/ABCD123)', 'error');
                    }
                }
            }
        } elseif ($type === 'company') {
            if (empty($_POST['einvoice_tax_id'])) {
                wc_add_notice('請輸入統一編號', 'error');
            } else {
                $tax_id = sanitize_text_field($_POST['einvoice_tax_id']);
                if (!preg_match('/^[0-9]{8}$/', $tax_id)) {
                    wc_add_notice('統一編號格式不正確 (需8位數字)', 'error');
                }
            }
            if (empty($_POST['einvoice_company_name'])) {
                wc_add_notice('請輸入公司名稱', 'error');
            }
        } elseif ($type === 'donate') {
            if (empty($_POST['einvoice_donate_code'])) {
                wc_add_notice('請輸入捐贈碼', 'error');
            } else {
                $donate_code = sanitize_text_field($_POST['einvoice_donate_code']);
                if (!preg_match('/^[0-9]{3,7}$/', $donate_code)) {
                    wc_add_notice('捐贈碼格式不正確 (需3-7位數字)', 'error');
                }
            }
        }
    }
    
    /**
     * 儲存電子發票資訊到訂單
     */
    public function save_einvoice_fields($order) {
        if (isset($_POST['einvoice_type'])) {
            $type = sanitize_text_field($_POST['einvoice_type']);
            $order->update_meta_data('_einvoice_type', $type);
            
            $type_labels = array(
                'personal' => '個人',
                'company' => '公司',
                'donate' => '捐贈'
            );
            $order->update_meta_data('發票類型', $type_labels[$type]);
            
            if ($type === 'personal') {
                $carrier_type = isset($_POST['einvoice_carrier_type']) ? sanitize_text_field($_POST['einvoice_carrier_type']) : 'paper';
                $order->update_meta_data('_einvoice_carrier_type', $carrier_type);
                $order->update_meta_data('載具類型', $carrier_type === 'mobile' ? '手機載具' : '紙本發票');
                
                if ($carrier_type === 'mobile' && !empty($_POST['einvoice_mobile_carrier'])) {
                    $carrier = sanitize_text_field($_POST['einvoice_mobile_carrier']);
                    $order->update_meta_data('_einvoice_mobile_carrier', $carrier);
                    $order->update_meta_data('手機載具', $carrier);
                }
            } elseif ($type === 'company') {
                if (!empty($_POST['einvoice_tax_id'])) {
                    $tax_id = sanitize_text_field($_POST['einvoice_tax_id']);
                    $order->update_meta_data('_einvoice_tax_id', $tax_id);
                    $order->update_meta_data('統一編號', $tax_id);
                }
                if (!empty($_POST['einvoice_company_name'])) {
                    $company_name = sanitize_text_field($_POST['einvoice_company_name']);
                    $order->update_meta_data('_einvoice_company_name', $company_name);
                    $order->update_meta_data('公司名稱', $company_name);
                }
            } elseif ($type === 'donate') {
                if (!empty($_POST['einvoice_donate_code'])) {
                    $donate_code = sanitize_text_field($_POST['einvoice_donate_code']);
                    $order->update_meta_data('_einvoice_donate_code', $donate_code);
                    $order->update_meta_data('捐贈碼', $donate_code);
                }
            }
        }
    }
    
    /**
     * 在後台訂單詳情顯示電子發票資訊
     */
    public function display_einvoice_in_admin($order) {
        $type = $order->get_meta('發票類型');
        
        if ($type) {
            echo '<div style="margin-top:15px;padding:10px;background:#f8f8f8;border:1px solid #ddd;">';
            echo '<h4 style="margin-top:0;">電子發票資訊</h4>';
            echo '<p><strong>發票類型:</strong> ' . esc_html($type) . '</p>';
            
            if ($type === '個人') {
                $carrier_type = $order->get_meta('載具類型');
                echo '<p><strong>載具類型:</strong> ' . esc_html($carrier_type) . '</p>';
                if ($carrier_type === '手機載具') {
                    $carrier = $order->get_meta('手機載具');
                    if ($carrier) {
                        echo '<p><strong>手機載具:</strong> ' . esc_html($carrier) . '</p>';
                    }
                }
            } elseif ($type === '公司') {
                $tax_id = $order->get_meta('統一編號');
                $company_name = $order->get_meta('公司名稱');
                if ($tax_id) {
                    echo '<p><strong>統一編號:</strong> ' . esc_html($tax_id) . '</p>';
                }
                if ($company_name) {
                    echo '<p><strong>公司名稱:</strong> ' . esc_html($company_name) . '</p>';
                }
            } elseif ($type === '捐贈') {
                $donate_code = $order->get_meta('捐贈碼');
                if ($donate_code) {
                    echo '<p><strong>捐贈碼:</strong> ' . esc_html($donate_code) . '</p>';
                }
            }
            
            echo '</div>';
        }
    }
    
    /**
     * AJAX 查詢公司名稱 (使用政府開放資料API)
     */
    public function ajax_get_company_name() {
        $tax_id = isset($_POST['tax_id']) ? sanitize_text_field($_POST['tax_id']) : '';
        
        if (empty($tax_id) || !preg_match('/^[0-9]{8}$/', $tax_id)) {
            wp_send_json_error(array('message' => '統一編號格式不正確'));
        }
        
        // 使用財政部開放資料API查詢公司名稱
        $api_url = 'https://data.gcis.nat.gov.tw/od/data/api/5F64D864-61CB-4D0D-8AD9-492047CC1EA6?$format=json&$filter=Business_Accounting_NO eq ' . $tax_id;
        
        $response = wp_remote_get($api_url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => '無法連接API'));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!empty($data) && is_array($data) && count($data) > 0) {
            $company_name = isset($data[0]['Company_Name']) ? $data[0]['Company_Name'] : '';
            if ($company_name) {
                wp_send_json_success(array('company_name' => $company_name));
            }
        }
        
        wp_send_json_error(array('message' => '查無此統一編號'));
    }
    
    /**
     * 註冊 7-11 超商取貨運送方式
     */
    public function register_711_shipping() {
        add_filter('woocommerce_shipping_methods', array($this, 'add_711_shipping_method'));
        add_action('woocommerce_shipping_init', array($this, 'init_711_shipping_method'));
        add_action('woocommerce_after_order_notes', array($this, 'add_711_store_fields'));
        add_action('wp_footer', array($this, 'add_711_toggle_script'));
        add_action('woocommerce_checkout_process', array($this, 'validate_711_fields'));
        add_action('woocommerce_checkout_create_order', array($this, 'save_711_fields'));
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_711_in_admin'));
        add_filter('woocommerce_checkout_fields', array($this, 'modify_711_checkout_fields'), 1001);
        add_action('wp_ajax_save_711_store_info', array($this, 'ajax_save_711_store_info'));
        add_action('wp_ajax_nopriv_save_711_store_info', array($this, 'ajax_save_711_store_info'));
        add_action('woocommerce_shipping_zone_method_added', array($this, 'clear_shipping_cache'));
        add_action('woocommerce_shipping_zone_method_deleted', array($this, 'clear_shipping_cache'));
        add_action('woocommerce_shipping_zone_method_status_toggled', array($this, 'clear_shipping_cache'));
    }
    
    public function clear_shipping_cache() {
        WC_Cache_Helper::get_transient_version('shipping', true);
    }
    
    public function add_711_shipping_method($methods) {
        $methods['seven_eleven_pickup'] = 'WC_Seven_Eleven_Pickup';
        return $methods;
    }
    
    public function init_711_shipping_method() {
        // 類別已在檔案末尾定義
    }
    
    public function add_711_store_fields($checkout) {
        $user_id = get_current_user_id();
        $saved_store_name = '';
        $saved_store_address = '';
        
        if ($user_id > 0) {
            $saved_store_name = get_user_meta($user_id, '_wu_711_store_name', true);
            $saved_store_address = get_user_meta($user_id, '_wu_711_store_address', true);
        }
        
        echo '<div id="seven_store_field" style="display:none;margin-top:20px;padding:20px;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;">';
        echo '<h3 style="margin-top:0;">7-11 門市資訊</h3>';

        woocommerce_form_field('seven_store_name', array(
            'type' => 'text',
            'label' => '7-11 門市名稱',
            'required' => true,
            'placeholder' => '例如:台北忠孝門市',
            'class' => array('form-row-wide'),
            'default' => $saved_store_name
        ), $checkout->get_value('seven_store_name') ?: $saved_store_name);

        woocommerce_form_field('seven_store_address', array(
            'type' => 'text',
            'label' => '門市地址',
            'required' => true,
            'placeholder' => '例如:台北市中正區忠孝東路一段 100 號',
            'class' => array('form-row-wide'),
            'default' => $saved_store_address
        ), $checkout->get_value('seven_store_address') ?: $saved_store_address);

        echo '</div>';
    }
    
    public function ajax_save_711_store_info() {
        $user_id = get_current_user_id();
        
        if ($user_id > 0 && isset($_POST['store_name']) && isset($_POST['store_address'])) {
            update_user_meta($user_id, '_wu_711_store_name', sanitize_text_field($_POST['store_name']));
            update_user_meta($user_id, '_wu_711_store_address', sanitize_text_field($_POST['store_address']));
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }
    
    public function add_711_toggle_script() {
        if (!is_checkout()) return;
        ?>
        <script>
        jQuery(function($){
            function toggle711(){
                let shipping = $('input[name^="shipping_method"]:checked').val();
                if(shipping && shipping.indexOf('seven_eleven_pickup') !== -1){
                    $('#seven_store_field').slideDown();
                    
                    if(!$('#ship-to-different-address-checkbox').is(':checked')){
                        $('#ship-to-different-address-checkbox').prop('checked', true).trigger('change');
                    }
                    
                    $('#shipping_city_field, #shipping_postcode_field, #shipping_address_1_field').slideUp();
                    $('#shipping_state_field, #shipping_region_type_field').slideUp();
                    
                    $('#shipping_phone_field').slideDown();
                    $('#shipping_phone').attr('required', 'required');
                    
                    $('#seven_store_name, #seven_store_address').on('blur', function(){
                        let storeName = $('#seven_store_name').val();
                        let storeAddress = $('#seven_store_address').val();
                        if(storeName && storeAddress){
                            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                action: 'save_711_store_info',
                                store_name: storeName,
                                store_address: storeAddress
                            });
                        }
                    });
                } else {
                    $('#seven_store_field').slideUp();
                    $('#shipping_city_field, #shipping_postcode_field, #shipping_address_1_field').slideDown();
                    $('#shipping_state_field, #shipping_region_type_field').slideDown();
                    $('#shipping_phone').removeAttr('required');
                }
            }
            
            $(document).on('change','input[name^="shipping_method"]', function(){
                toggle711();
                $(document.body).trigger('update_checkout');
            });
            
            $(document.body).on('updated_checkout', toggle711);
            toggle711();
        });
        </script>
        <?php
    }
    
    public function validate_711_fields() {
        if (isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
            $shipping_method = $_POST['shipping_method'][0];
            if (strpos($shipping_method, 'seven_eleven_pickup') !== false) {
                if (empty($_POST['seven_store_name']) || empty($_POST['seven_store_address'])) {
                    wc_add_notice('請填寫 7-11 門市名稱與地址', 'error');
                }
                if (empty($_POST['shipping_phone'])) {
                    wc_add_notice('請填寫收件者電話', 'error');
                }
            }
        }
    }
    
    public function save_711_fields($order) {
        if (isset($_POST['seven_store_name'])) {
            $order->update_meta_data('_seven_store_name', sanitize_text_field($_POST['seven_store_name']));
            $order->update_meta_data('7-11 門市', sanitize_text_field($_POST['seven_store_name']));
        }
        if (isset($_POST['seven_store_address'])) {
            $order->update_meta_data('_seven_store_address', sanitize_text_field($_POST['seven_store_address']));
            $order->update_meta_data('門市地址', sanitize_text_field($_POST['seven_store_address']));
        }
    }
    
    public function display_711_in_admin($order) {
        $store = $order->get_meta('7-11 門市');
        $address = $order->get_meta('門市地址');

        if ($store || $address) {
            echo '<div style="margin-top:15px;padding:10px;background:#f8f8f8;border:1px solid #ddd;">';
            echo '<h4 style="margin-top:0;">7-11 超商取貨資訊</h4>';
            if ($store) {
                echo '<p><strong>門市名稱:</strong><br>' . esc_html($store) . '</p>';
            }
            if ($address) {
                echo '<p><strong>門市地址:</strong><br>' . esc_html($address) . '</p>';
            }
            echo '</div>';
        }
    }
    
    public function modify_711_checkout_fields($fields) {
        // 新增收件者電話欄位到 shipping (必填)
        if (!isset($fields['shipping']['shipping_phone'])) {
            $fields['shipping']['shipping_phone'] = array(
                'label' => '收件者電話',
                'required' => true,
                'type' => 'tel',
                'class' => array('form-row-wide'),
                'priority' => 25,
                'validate' => array('phone')
            );
        }
        
        if (isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
            $shipping_method = isset($_POST['shipping_method'][0]) ? $_POST['shipping_method'][0] : '';
            if (strpos($shipping_method, 'seven_eleven_pickup') !== false) {
                if (isset($fields['shipping']['shipping_city'])) {
                    $fields['shipping']['shipping_city']['required'] = false;
                }
                if (isset($fields['shipping']['shipping_postcode'])) {
                    $fields['shipping']['shipping_postcode']['required'] = false;
                }
                if (isset($fields['shipping']['shipping_address_1'])) {
                    $fields['shipping']['shipping_address_1']['required'] = false;
                }
                if (isset($fields['shipping']['shipping_state'])) {
                    $fields['shipping']['shipping_state']['required'] = false;
                }
                if (isset($fields['shipping']['shipping_phone'])) {
                    $fields['shipping']['shipping_phone']['required'] = true;
                }
            }
        }
        
        return $fields;
    }
    
    public function enqueue_error_message_styles() {
        if (!is_checkout()) return;
        
        wp_add_inline_style('woocommerce-general', "
.woocommerce form .form-row .woocommerce-error,
.woocommerce form .form-row .woocommerce-message,
.woocommerce form .form-row ul.woocommerce-error,
.woocommerce-error,
.woocommerce-message,
.woocommerce-info {
    background-color: #ffebee !important;
    color: #c62828 !important;
    font-size: 12px !important;
    padding: 8px 12px !important;
    border-left: 3px solid #ef5350 !important;
}

.woocommerce form .form-row.woocommerce-invalid label,
.woocommerce form .form-row.woocommerce-invalid .select2-container,
.woocommerce form .form-row.woocommerce-invalid input.input-text,
.woocommerce form .form-row.woocommerce-invalid select {
    border-color: #ef5350 !important;
}

.woocommerce form .form-row.woocommerce-invalid label {
    color: #c62828 !important;
}

.woocommerce-NoticeGroup .woocommerce-error,
.woocommerce-notices-wrapper .woocommerce-error {
    background-color: #ffebee !important;
    color: #c62828 !important;
    font-size: 12px !important;
    border-left: 3px solid #ef5350 !important;
}

.woocommerce-NoticeGroup .woocommerce-error li,
.woocommerce-notices-wrapper .woocommerce-error li {
    font-size: 12px !important;
    color: #c62828 !important;
}
        ");
    }
    
    public function register_taiwan_address() {
        add_filter('woocommerce_checkout_fields', array($this, 'customize_taiwan_address_fields'), 999);
        add_filter('woocommerce_ship_to_different_address_checked', '__return_false');
        add_filter('gettext', array($this, 'change_ship_to_different_text'), 20, 3);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_taiwan_address_assets'), 999);
    }
    
    public function change_ship_to_different_text($translated_text, $text, $domain) {
        if ($domain === 'woocommerce' && $text === 'Ship to a different address?') {
            return '<span class="wu-ship-text">寄送到其他收件地址</span>';
        }
        return $translated_text;
    }
    
    public function customize_taiwan_address_fields($fields) {
        $enable_island = get_option('wu_woo_enable_island_shipping', false);
        
        // === Billing 欄位 ===
        unset($fields['billing']['billing_last_name']);
        unset($fields['billing']['billing_address_2']);
        unset($fields['billing']['billing_country']);
        
        if (isset($fields['billing']['billing_first_name'])) {
            $fields['billing']['billing_first_name']['label'] = '姓名';
            $fields['billing']['billing_first_name']['class'] = array('form-row-wide');
            $fields['billing']['billing_first_name']['priority'] = 10;
        }
        
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['priority'] = 20;
        }
        
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['priority'] = 30;
        }
        
        if ($enable_island) {
            $fields['billing']['billing_region_type'] = array(
                'type' => 'select',
                'label' => '地區',
                'required' => true,
                'class' => array('form-row-wide', 'wu-region-type'),
                'priority' => 35,
                'default' => 'mainland',
                'options' => array(
                    'mainland' => '台灣本島',
                    'island' => '台灣離島'
                )
            );
        }
        
        if (isset($fields['billing']['billing_state'])) {
            $fields['billing']['billing_state']['type'] = 'select';
            $fields['billing']['billing_state']['options'] = $this->get_taiwan_mainland_cities();
            $fields['billing']['billing_state']['class'] = array('form-row-wide', 'wu-tw-city');
            $fields['billing']['billing_state']['priority'] = 40;
            $fields['billing']['billing_state']['label'] = '縣 / 市';
        }
        
        if (isset($fields['billing']['billing_city'])) {
            $fields['billing']['billing_city']['type'] = 'select';
            $fields['billing']['billing_city']['options'] = array('' => '請先選擇縣市');
            $fields['billing']['billing_city']['class'] = array('form-row-wide', 'wu-tw-district');
            $fields['billing']['billing_city']['priority'] = 50;
            $fields['billing']['billing_city']['label'] = '鄉鎮市區';
        }
        
        if (isset($fields['billing']['billing_postcode'])) {
            $fields['billing']['billing_postcode']['type'] = 'text';
            $fields['billing']['billing_postcode']['class'] = array('form-row-wide', 'wu-tw-postcode');
            $fields['billing']['billing_postcode']['priority'] = 60;
            $fields['billing']['billing_postcode']['label'] = '郵遞區號';
        }
        
        if (isset($fields['billing']['billing_address_1'])) {
            $fields['billing']['billing_address_1']['label'] = '詳細地址';
            $fields['billing']['billing_address_1']['placeholder'] = '';
            $fields['billing']['billing_address_1']['priority'] = 70;
        }
        
        // === Shipping 欄位 ===
        unset($fields['shipping']['shipping_last_name']);
        unset($fields['shipping']['shipping_address_2']);
        unset($fields['shipping']['shipping_country']);
        unset($fields['shipping']['shipping_company']);
        
        if (isset($fields['shipping']['shipping_first_name'])) {
            $fields['shipping']['shipping_first_name']['label'] = '收件人姓名';
            $fields['shipping']['shipping_first_name']['class'] = array('form-row-wide');
            $fields['shipping']['shipping_first_name']['priority'] = 10;
        }
        
        if ($enable_island) {
            $fields['shipping']['shipping_region_type'] = array(
                'type' => 'select',
                'label' => '地區',
                'required' => true,
                'class' => array('form-row-wide', 'wu-region-type'),
                'priority' => 15,
                'default' => 'mainland',
                'options' => array(
                    'mainland' => '台灣本島',
                    'island' => '台灣離島'
                )
            );
        }
        
        if (isset($fields['shipping']['shipping_state'])) {
            $fields['shipping']['shipping_state']['type'] = 'select';
            $fields['shipping']['shipping_state']['options'] = $this->get_taiwan_mainland_cities();
            $fields['shipping']['shipping_state']['class'] = array('form-row-wide', 'wu-tw-city');
            $fields['shipping']['shipping_state']['priority'] = 20;
            $fields['shipping']['shipping_state']['label'] = '縣 / 市';
        }
        
        if (isset($fields['shipping']['shipping_city'])) {
            $fields['shipping']['shipping_city']['type'] = 'select';
            $fields['shipping']['shipping_city']['options'] = array('' => '請先選擇縣市');
            $fields['shipping']['shipping_city']['class'] = array('form-row-wide', 'wu-tw-district');
            $fields['shipping']['shipping_city']['priority'] = 30;
            $fields['shipping']['shipping_city']['label'] = '鄉鎮市區';
        }
        
        if (isset($fields['shipping']['shipping_postcode'])) {
            $fields['shipping']['shipping_postcode']['type'] = 'text';
            $fields['shipping']['shipping_postcode']['class'] = array('form-row-wide', 'wu-tw-postcode');
            $fields['shipping']['shipping_postcode']['priority'] = 40;
            $fields['shipping']['shipping_postcode']['label'] = '郵遞區號';
        }
        
        if (isset($fields['shipping']['shipping_address_1'])) {
            $fields['shipping']['shipping_address_1']['label'] = '詳細地址';
            $fields['shipping']['shipping_address_1']['placeholder'] = '';
            $fields['shipping']['shipping_address_1']['priority'] = 50;
        }
        
        return $fields;
    }
    
    private function get_taiwan_mainland_cities() {
        return array(
            '' => '請選擇縣市',
            '台北市' => '台北市',
            '新北市' => '新北市',
            '基隆市' => '基隆市',
            '桃園市' => '桃園市',
            '新竹市' => '新竹市',
            '新竹縣' => '新竹縣',
            '苗栗縣' => '苗栗縣',
            '台中市' => '台中市',
            '彰化縣' => '彰化縣',
            '南投縣' => '南投縣',
            '雲林縣' => '雲林縣',
            '嘉義市' => '嘉義市',
            '嘉義縣' => '嘉義縣',
            '台南市' => '台南市',
            '高雄市' => '高雄市',
            '屏東縣' => '屏東縣',
            '宜蘭縣' => '宜蘭縣',
            '花蓮縣' => '花蓮縣',
            '台東縣' => '台東縣'
        );
    }
    
    private function get_taiwan_island_cities() {
        return array(
            '' => '請選擇縣市',
            '澎湖縣' => '澎湖縣',
            '金門縣' => '金門縣',
            '連江縣' => '連江縣'
        );
    }
    
    private function get_taiwan_mainland_data() {
        return array(
            '台北市' => array('中正區' => '100','大同區' => '103','中山區' => '104','松山區' => '105','大安區' => '106','萬華區' => '108','信義區' => '110','士林區' => '111','北投區' => '112','內湖區' => '114','南港區' => '115','文山區' => '116'),
            '新北市' => array('板橋區' => '220','三重區' => '241','中和區' => '235','永和區' => '234','新莊區' => '242','新店區' => '231','樹林區' => '238','鶯歌區' => '239','三峽區' => '237','淡水區' => '251','汐止區' => '221','瑞芳區' => '224','土城區' => '236','蘆洲區' => '247','五股區' => '248','泰山區' => '243','林口區' => '244','深坑區' => '222','石碇區' => '223','坪林區' => '232','三芝區' => '252','石門區' => '253','八里區' => '249','平溪區' => '226','雙溪區' => '227','貢寮區' => '228','金山區' => '208','萬里區' => '207','烏來區' => '233'),
            '基隆市' => array('仁愛區' => '200','信義區' => '201','中正區' => '202','中山區' => '203','安樂區' => '204','暖暖區' => '205','七堵區' => '206'),
            '桃園市' => array('桃園區' => '330','中壢區' => '320','平鎮區' => '324','八德區' => '334','楊梅區' => '326','蘆竹區' => '338','大溪區' => '335','龍潭區' => '325','龜山區' => '333','大園區' => '337','觀音區' => '328','新屋區' => '327','復興區' => '336'),
            '新竹市' => array('東區' => '300','北區' => '300','香山區' => '300'),
            '新竹縣' => array('竹北市' => '302','竹東鎮' => '310','新埔鎮' => '305','關西鎮' => '306','湖口鄉' => '303','新豐鄉' => '304','芎林鄉' => '307','橫山鄉' => '312','北埔鄉' => '314','寶山鄉' => '308','峨眉鄉' => '315','尖石鄉' => '313','五峰鄉' => '311'),
            '苗栗縣' => array('苗栗市' => '360','頭份市' => '351','竹南鎮' => '350','後龍鎮' => '356','通霄鎮' => '357','苑裡鎮' => '358','卓蘭鎮' => '369','造橋鄉' => '361','西湖鄉' => '368','頭屋鄉' => '362','公館鄉' => '363','銅鑼鄉' => '366','三義鄉' => '367','大湖鄉' => '364','獅潭鄉' => '354','三灣鄉' => '352','南庄鄉' => '353','泰安鄉' => '365'),
            '台中市' => array('中區' => '400','東區' => '401','南區' => '402','西區' => '403','北區' => '404','西屯區' => '407','南屯區' => '408','北屯區' => '406','豐原區' => '420','大里區' => '412','太平區' => '411','清水區' => '436','沙鹿區' => '433','大甲區' => '437','東勢區' => '423','梧棲區' => '435','烏日區' => '414','神岡區' => '429','大肚區' => '432','大雅區' => '428','后里區' => '421','霧峰區' => '413','潭子區' => '427','龍井區' => '434','外埔區' => '438','和平區' => '424','石岡區' => '422','大安區' => '439','新社區' => '426'),
            '彰化縣' => array('彰化市' => '500','員林市' => '510','和美鎮' => '508','鹿港鎮' => '505','溪湖鎮' => '514','二林鎮' => '526','田中鎮' => '520','北斗鎮' => '521','花壇鄉' => '503','芬園鄉' => '502','大村鄉' => '515','永靖鄉' => '512','伸港鄉' => '509','線西鄉' => '507','福興鄉' => '506','秀水鄉' => '504','埔心鄉' => '513','埔鹽鄉' => '516','大城鄉' => '527','芳苑鄉' => '528','竹塘鄉' => '525','社頭鄉' => '511','二水鄉' => '530','田尾鄉' => '522','埤頭鄉' => '523','溪州鄉' => '524'),
            '南投縣' => array('南投市' => '540','埔里鎮' => '545','草屯鎮' => '542','竹山鎮' => '557','集集鎮' => '552','名間鄉' => '551','鹿谷鄉' => '558','中寮鄉' => '541','魚池鄉' => '555','國姓鄉' => '544','水里鄉' => '553','信義鄉' => '556','仁愛鄉' => '546'),
            '雲林縣' => array('斗六市' => '640','斗南鎮' => '630','虎尾鎮' => '632','西螺鎮' => '648','土庫鎮' => '633','北港鎮' => '651','林內鄉' => '643','古坑鄉' => '646','大埤鄉' => '631','莿桐鄉' => '647','褒忠鄉' => '634','二崙鄉' => '649','崙背鄉' => '637','麥寮鄉' => '638','臺西鄉' => '636','東勢鄉' => '635','元長鄉' => '655','四湖鄉' => '654','口湖鄉' => '653','水林鄉' => '652'),
            '嘉義市' => array('東區' => '600','西區' => '600'),
            '嘉義縣' => array('太保市' => '612','朴子市' => '613','布袋鎮' => '625','大林鎮' => '622','民雄鄉' => '621','溪口鄉' => '623','新港鄉' => '616','六腳鄉' => '615','東石鄉' => '614','義竹鄉' => '624','鹿草鄉' => '611','水上鄉' => '608','中埔鄉' => '606','竹崎鄉' => '604','梅山鄉' => '603','番路鄉' => '602','大埔鄉' => '607','阿里山鄉' => '605'),
            '台南市' => array('中西區' => '700','東區' => '701','南區' => '702','北區' => '704','安平區' => '708','安南區' => '709','永康區' => '710','歸仁區' => '711','新化區' => '712','左鎮區' => '713','玉井區' => '714','楠西區' => '715','南化區' => '716','仁德區' => '717','關廟區' => '718','龍崎區' => '719','官田區' => '720','麻豆區' => '721','佳里區' => '722','西港區' => '723','七股區' => '724','將軍區' => '725','學甲區' => '726','北門區' => '727','新營區' => '730','後壁區' => '731','白河區' => '732','東山區' => '733','六甲區' => '734','下營區' => '735','柳營區' => '736','鹽水區' => '737','善化區' => '741','大內區' => '742','山上區' => '743','新市區' => '744','安定區' => '745'),
            '高雄市' => array('楠梓區' => '811','左營區' => '813','鼓山區' => '804','三民區' => '807','鹽埕區' => '803','前金區' => '801','新興區' => '800','苓雅區' => '802','前鎮區' => '806','旗津區' => '805','小港區' => '812','鳳山區' => '830','大寮區' => '831','鳥松區' => '833','林園區' => '832','仁武區' => '814','大樹區' => '840','大社區' => '815','岡山區' => '820','路竹區' => '821','橋頭區' => '825','梓官區' => '826','彌陀區' => '827','永安區' => '828','燕巢區' => '824','田寮區' => '823','阿蓮區' => '822','茄萣區' => '852','湖內區' => '829','旗山區' => '842','美濃區' => '843','內門區' => '845','杉林區' => '846','甲仙區' => '847','六龜區' => '844','茂林區' => '851','桃源區' => '848','那瑪夏區' => '849'),
            '屏東縣' => array('屏東市' => '900','潮州鎮' => '920','東港鎮' => '928','恆春鎮' => '946','萬丹鄉' => '913','長治鄉' => '908','麟洛鄉' => '909','九如鄉' => '904','里港鄉' => '905','鹽埔鄉' => '907','高樹鄉' => '906','萬巒鄉' => '923','內埔鄉' => '912','竹田鄉' => '911','新埤鄉' => '925','枋寮鄉' => '940','新園鄉' => '932','崁頂鄉' => '924','林邊鄉' => '927','南州鄉' => '926','佳冬鄉' => '931','琉球鄉' => '929','車城鄉' => '944','滿州鄉' => '947','枋山鄉' => '941','霧台鄉' => '902','瑪家鄉' => '903','泰武鄉' => '921','來義鄉' => '922','春日鄉' => '942','獅子鄉' => '943','牡丹鄉' => '945','三地門鄉' => '901'),
            '宜蘭縣' => array('宜蘭市' => '260','羅東鎮' => '265','蘇澳鎮' => '270','頭城鎮' => '261','礁溪鄉' => '262','壯圍鄉' => '263','員山鄉' => '264','冬山鄉' => '269','五結鄉' => '268','三星鄉' => '266','大同鄉' => '267','南澳鄉' => '272'),
            '花蓮縣' => array('花蓮市' => '970','鳳林鎮' => '975','玉里鎮' => '981','新城鄉' => '971','吉安鄉' => '973','壽豐鄉' => '974','光復鄉' => '976','豐濱鄉' => '977','瑞穗鄉' => '978','富里鄉' => '983','秀林鄉' => '972','萬榮鄉' => '979','卓溪鄉' => '982'),
            '台東縣' => array('台東市' => '950','成功鎮' => '961','關山鎮' => '956','卑南鄉' => '954','鹿野鄉' => '955','池上鄉' => '958','東河鄉' => '959','長濱鄉' => '962','太麻里鄉' => '963','大武鄉' => '965','綠島鄉' => '951','海端鄉' => '957','延平鄉' => '953','金峰鄉' => '964','達仁鄉' => '966','蘭嶼鄉' => '952')
        );
    }
    
    private function get_taiwan_island_data() {
        return array(
            '澎湖縣' => array('馬公市' => '880','湖西鄉' => '885','白沙鄉' => '884','西嶼鄉' => '881','望安鄉' => '882','七美鄉' => '883'),
            '金門縣' => array('金城鎮' => '893','金湖鎮' => '891','金沙鎮' => '890','金寧鄉' => '892','烈嶼鄉' => '894','烏坵鄉' => '896'),
            '連江縣' => array('南竿鄉' => '209','北竿鄉' => '210','莒光鄉' => '211','東引鄉' => '212')
        );
    }
    
    public function enqueue_taiwan_address_assets() {
        if (!is_checkout() || is_order_received_page()) {
            return;
        }
        
        $enable_island = get_option('wu_woo_enable_island_shipping', false);
        
        wp_localize_script('jquery', 'wuTaiwanAddress', array(
            'enableIsland' => $enable_island,
            'mainland' => array(
                'cities' => $this->get_taiwan_mainland_cities(),
                'data' => $this->get_taiwan_mainland_data()
            ),
            'island' => array(
                'cities' => $this->get_taiwan_island_cities(),
                'data' => $this->get_taiwan_island_data()
            )
        ));
        
        wp_add_inline_script('jquery', $this->get_taiwan_address_js());
    }
    
    private function get_taiwan_address_js() {
        return "
jQuery(document).ready(function($) {
    var addressData = wuTaiwanAddress;
    var isUpdating = false;
    var currentRegion = 'mainland';
    var enableIsland = addressData.enableIsland;
    var updateTimer = null;
    
    var postcodeIndex = {
        mainland: {},
        island: {}
    };
    
    $.each(['mainland', 'island'], function(idx, region) {
        $.each(addressData[region].data, function(city, districts) {
            $.each(districts, function(district, postcode) {
                if (!postcodeIndex[region][postcode]) {
                    postcodeIndex[region][postcode] = [];
                }
                postcodeIndex[region][postcode].push({city: city, district: district});
            });
        });
    });
    
    function updateCitiesByRegion(prefix) {
        var regionType = enableIsland ? $('#' + prefix + '_region_type').val() || 'mainland' : 'mainland';
        var \$city = $('#' + prefix + '_state');
        
        \$city.empty();
        $('#' + prefix + '_city').empty().append('<option value=\"\">請先選擇縣市</option>');
        
        if (regionType && addressData[regionType]) {
            currentRegion = regionType;
            $.each(addressData[regionType].cities, function(key, city) {
                \$city.append('<option value=\"' + city + '\">' + city + '</option>');
            });
        }
        
        triggerShippingUpdate(prefix);
    }
    
    function updateDistricts(prefix, selectedDistrict) {
        var regionType = enableIsland ? $('#' + prefix + '_region_type').val() || 'mainland' : 'mainland';
        var city = $('#' + prefix + '_state').val();
        var \$district = $('#' + prefix + '_city');
        
        \$district.empty().append('<option value=\"\">請選擇鄉鎮市區</option>');
        
        if (city && addressData[regionType] && addressData[regionType].data[city]) {
            $.each(addressData[regionType].data[city], function(district, postcode) {
                var selected = (district === selectedDistrict) ? ' selected' : '';
                \$district.append('<option value=\"' + district + '\"' + selected + '>' + district + '</option>');
            });
        }
    }
    
    function updatePostcode(prefix) {
        var regionType = enableIsland ? $('#' + prefix + '_region_type').val() || 'mainland' : 'mainland';
        var city = $('#' + prefix + '_state').val();
        var district = $('#' + prefix + '_city').val();
        
        if (city && district && addressData[regionType] && addressData[regionType].data[city] && addressData[regionType].data[city][district]) {
            var postcode = addressData[regionType].data[city][district];
            var \$postcodeField = $('#' + prefix + '_postcode');
            \$postcodeField.val(postcode);
            triggerShippingUpdate(prefix);
        }
    }
    
    function triggerShippingUpdate(prefix) {
        clearTimeout(updateTimer);
        updateTimer = setTimeout(function() {
            $(document.body).trigger('update_checkout');
        }, 300);
    }
    
    function updateAddressByPostcode(prefix, postcode) {
        if (!postcode || postcode.length < 3) return;
        
        var found = null;
        var foundRegion = null;
        
        $.each(['mainland', 'island'], function(idx, region) {
            if (postcodeIndex[region][postcode] && postcodeIndex[region][postcode].length > 0) {
                found = postcodeIndex[region][postcode][0];
                foundRegion = region;
                return false;
            }
        });
        
        if (found) {
            isUpdating = true;
            
            if (enableIsland && foundRegion) {
                $('#' + prefix + '_region_type').val(foundRegion);
                updateCitiesByRegion(prefix);
            }
            
            $('#' + prefix + '_state').val(found.city);
            updateDistricts(prefix, found.district);
            $('#' + prefix + '_city').val(found.district);
            
            triggerShippingUpdate(prefix);
            
            isUpdating = false;
        }
    }
    
    if (enableIsland) {
        $(document.body).on('change', '#billing_region_type, #shipping_region_type', function() {
            if (isUpdating) return;
            isUpdating = true;
            var prefix = $(this).attr('id').replace('_region_type', '');
            updateCitiesByRegion(prefix);
            isUpdating = false;
        });
    }
    
    $(document.body).on('change', '#billing_state, #shipping_state', function() {
        if (isUpdating) return;
        isUpdating = true;
        var prefix = $(this).attr('id').replace('_state', '');
        updateDistricts(prefix, '');
        isUpdating = false;
    });
    
    $(document.body).on('change', '#billing_city, #shipping_city', function() {
        if (isUpdating) return;
        isUpdating = true;
        var prefix = $(this).attr('id').replace('_city', '');
        updatePostcode(prefix);
        isUpdating = false;
    });
    
    $(document.body).on('blur', '#billing_postcode, #shipping_postcode', function() {
        if (isUpdating) return;
        var prefix = $(this).attr('id').replace('_postcode', '');
        var postcode = $(this).val().trim();
        if (postcode.length >= 3) {
            isUpdating = true;
            updateAddressByPostcode(prefix, postcode);
            isUpdating = false;
        }
    });
    
    $(document.body).on('updated_checkout', function() {
        var billingCity = $('#billing_state').val();
        var billingDistrict = $('#billing_city').val();
        if (billingCity && billingDistrict) {
            updateDistricts('billing', billingDistrict);
        }
        
        if ($('#ship-to-different-address-checkbox').is(':checked')) {
            var shippingCity = $('#shipping_state').val();
            var shippingDistrict = $('#shipping_city').val();
            if (shippingCity && shippingDistrict) {
                updateDistricts('shipping', shippingDistrict);
            }
        }
    });
    
    updateCitiesByRegion('billing');
    var initialBillingCity = $('#billing_state').val();
    if (initialBillingCity) {
        $('#billing_state').val(initialBillingCity);
        updateDistricts('billing', $('#billing_city').val());
    }
    
    if ($('#ship-to-different-address-checkbox').is(':checked')) {
        updateCitiesByRegion('shipping');
        var initialShippingCity = $('#shipping_state').val();
        if (initialShippingCity) {
            $('#shipping_state').val(initialShippingCity);
            updateDistricts('shipping', $('#shipping_city').val());
        }
    }
});
        ";
    }
    

    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die('您沒有管理此設定的權限。');
        $group = $this->settings_group();
        if (isset($_POST['submit'])) {
            check_admin_referer($group . '-options');
            $options = array_keys($this->fields());
            if (!$this->commerce) $options = array_merge($options, array_keys($this->admin_visibility_options()));
            foreach ($options as $option) {
                update_option($option, isset($_POST[$option]) && $_POST[$option] === '1' ? 1 : 0);
            }
            if ($this->commerce) {
                foreach (array('wu_woo_711_shipping_cost','wu_woo_711_free_shipping_threshold') as $option) {
                    if (isset($_POST[$option]) && is_scalar($_POST[$option])) update_option($option, max(0, intval($_POST[$option])));
                }
                WC_Cache_Helper::get_transient_version('shipping', true);
            }
            echo '<div class="notice notice-success"><p>設定已儲存。新設定於下次載入頁面生效。</p></div>';
        }
        echo '<div class="wrap"><h1>' . esc_html($this->page_title()) . '</h1>';
        if ($this->commerce) echo '<p>沿用原有地址、運送、訂單備註與電子發票設定，不需重新填寫。停用本卡片即可停止載入這些功能。</p>';
        echo '<form method="post">';
        wp_nonce_field($group . '-options');
        do_settings_sections($group);
        submit_button();
        echo '</form></div>';
    }

    private function display_wc_status() {
        echo '<table class="widefat">';
        echo '<tr><th>WooCommerce 版本</th><td>' . esc_html(WC()->version) . '</td></tr>';
        echo '<tr><th>WordPress 版本</th><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '<tr><th>PHP 版本</th><td>' . esc_html(phpversion()) . '</td></tr>';
        
        $theme = wp_get_theme();
        echo '<tr><th>主題</th><td>' . esc_html($theme->get('Name')) . '</td></tr>';
        echo '</table>';
    }
}

/**
 * 7-11 超商取貨運送方式類別
 */
if (!class_exists('WC_Seven_Eleven_Pickup')) {
    class WC_Seven_Eleven_Pickup extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {
            $this->id = 'seven_eleven_pickup';
            $this->instance_id = absint($instance_id);
            $this->method_title = '7-11 超商取貨';
            $this->method_description = '7-11 超商取貨(門市自填)';
            $this->enabled = "yes";
            $this->title = '7-11 超商取貨';
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
            );
            $this->init();
        }

        public function init() {
            $this->init_settings();
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        public function calculate_shipping($package = array()) {
            if (isset($package['destination']['country']) && $package['destination']['country'] !== 'TW') {
                return;
            }

            $cost = floatval(get_option('wu_woo_711_shipping_cost', 60));
            $free_threshold = floatval(get_option('wu_woo_711_free_shipping_threshold', 0));
            
            $cart_total = 0;
            if (isset($package['contents'])) {
                foreach ($package['contents'] as $item) {
                    $cart_total += floatval($item['line_total']);
                }
            }
            
            if ($free_threshold > 0 && $cart_total >= $free_threshold) {
                $cost = 0;
            }

            $this->add_rate(array(
                'id'    => $this->get_rate_id(),
                'label' => $this->title . ($cost == 0 ? ' (免運)' : ''),
                'cost'  => $cost,
                'package' => $package,
            ));
        }
    }
}

