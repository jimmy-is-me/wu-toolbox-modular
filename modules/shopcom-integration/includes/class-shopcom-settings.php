<?php
/**
 * 設定模組 - 於 WooCommerce > 設定 新增「美安設定」分頁。
 * 負責集中管理 Offer_ID、Advertiser_ID、佣金比例、IP 白名单、
 * 訂單觸發狀態、XML 篩選規則等所有可調整參數。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_Settings {

	const OPTION_KEY = 'shopcom_settings';

	private $defaults = array(
		'enabled'                 => 'yes',
		'offer_id'                => '',
		'advertiser_id'           => '',
		'commission_rate'         => '10',
		'api_base_url'            => 'https://api.shop.com/affiliate',
		'force_ipv4'              => 'yes',
		'debug_mode'              => 'no',
		'order_created_status'    => 'completed',
		'order_cancel_status'     => 'cancelled',
		'auto_cancel'             => 'yes',
		'show_fraud_notice'       => 'yes',
		'show_account_status'     => 'yes',
		'notify_emails'           => '',
		'xml_filter_mode'         => 'none',
		'xml_filter_categories'   => array(),
		'xml_batch_size'          => '200',
		'xml_description_source'  => 'short_description',
		'xml_exclude_types'       => array( 'grouped' ),
		'rid_cookie_days'         => '30',
	);

	public function __construct() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_shopcom', array( $this, 'render_settings_tab' ) );
		add_action( 'woocommerce_update_options_shopcom', array( $this, 'save_settings_tab' ) );
	}

	public function add_settings_tab( $tabs ) {
		$tabs['shopcom'] = __( '美安設定', 'woo-shopcom' );
		return $tabs;
	}

	public function get( $key ) {
		$options = get_option( self::OPTION_KEY, array() );
		if ( isset( $options[ $key ] ) ) {
			return $options[ $key ];
		}
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '';
	}

	public function is_enabled() {
		return 'yes' === $this->get( 'enabled' );
	}

	public function is_debug() {
		return 'yes' === $this->get( 'debug_mode' );
	}

	private function get_fields() {
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cat_options = array();
		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $cat ) {
				$cat_options[ $cat->term_id ] = $cat->name;
			}
		}

		return array(
			array(
				'title' => __( '美安（Shop.com）串接設定', 'woo-shopcom' ),
				'type'  => 'title',
				'id'    => 'shopcom_general_title',
			),
			array(
				'title'   => __( '啟用串接', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[enabled]',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title' => __( 'Offer ID', 'woo-shopcom' ),
				'id'    => self::OPTION_KEY . '[offer_id]',
				'type'  => 'text',
				'desc'  => __( '美安提供的專屬 Offer_ID。', 'woo-shopcom' ),
			),
			array(
				'title' => __( 'Advertiser ID', 'woo-shopcom' ),
				'id'    => self::OPTION_KEY . '[advertiser_id]',
				'type'  => 'text',
				'desc'  => __( '美安提供的專屬 Advertiser_ID。', 'woo-shopcom' ),
			),
			array(
				'title'             => __( '佣金比例 (%)', 'woo-shopcom' ),
				'id'                => self::OPTION_KEY . '[commission_rate]',
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '0.01', 'min' => '0', 'max' => '100' ),
			),
			array(
				'title' => __( 'API Base URL', 'woo-shopcom' ),
				'id'    => self::OPTION_KEY . '[api_base_url]',
				'type'  => 'text',
				'desc'  => __( '美安 API 網域，如 API 建立失敗請確認此網址正確。', 'woo-shopcom' ),
			),
			array(
				'title'   => __( '強制使用 IPv4', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[force_ipv4]',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '避免部分主機 IPv6 環境造成 API 連線失敗。', 'woo-shopcom' ),
			),
			array(
				'title'   => __( '除錯模式', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[debug_mode]',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '僅限管理員檢視：於購物車/結賬頁顯示 RID 與 Click_ID。', 'woo-shopcom' ),
			),
			array(
				'title' => __( 'RID Cookie 保存天數', 'woo-shopcom' ),
				'id'    => self::OPTION_KEY . '[rid_cookie_days]',
				'type'  => 'number',
				'default' => '30',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'shopcom_general_title',
			),
			array(
				'title' => __( '訂單串接設定', 'woo-shopcom' ),
				'type'  => 'title',
				'id'    => 'shopcom_order_title',
			),
			array(
				'title'   => __( '訂單成立呼叫時機', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[order_created_status]',
				'type'    => 'select',
				'options' => $this->get_order_status_options(),
				'desc'    => __( '訂單進入此状態時，呼叫美安建立訂單 API。', 'woo-shopcom' ),
			),
			array(
				'title'   => __( '自動取消訂單', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[auto_cancel]',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '訂單進入取消状態時，自動呼叫美安取消訂單 API。', 'woo-shopcom' ),
			),
			array(
				'title'   => __( '訂單取消觸發状態', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[order_cancel_status]',
				'type'    => 'select',
				'options' => $this->get_order_status_options(),
			),
			array(
				'title'   => __( '結賬頁顯示防詐騙季導', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[show_fraud_notice]',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( '我的帳戶顯示美安串接状態', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[show_account_status]',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title' => __( 'API 失敗通知信收件人', 'woo-shopcom' ),
				'id'    => self::OPTION_KEY . '[notify_emails]',
				'type'  => 'textarea',
				'desc'  => __( '多筆請用逗號分隔。留空則不寄送。', 'woo-shopcom' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'shopcom_order_title',
			),
			array(
				'title' => __( '商品目錄 XML 設定', 'woo-shopcom' ),
				'type'  => 'title',
				'id'    => 'shopcom_xml_title',
				'desc'  => sprintf(
					/* translators: %s: feed URL */
					__( '商品目錄網址：%s（請提供此網址給美安人員，並將主機 IP 加入白名单）', 'woo-shopcom' ),
					'<code>' . esc_url( home_url( '/?feed=shopcom' ) ) . '</code>'
				),
			),
			array(
				'title'   => __( '商品說明來源', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[xml_description_source]',
				'type'    => 'select',
				'options' => array(
					'short_description' => __( '商品簡短說明', 'woo-shopcom' ),
					'description'       => __( '商品完整說明', 'woo-shopcom' ),
				),
			),
			array(
				'title'   => __( '分類篩選模式', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[xml_filter_mode]',
				'type'    => 'select',
				'options' => array(
					'none'    => __( '不篩選', 'woo-shopcom' ),
					'include' => __( '只包含所選分類', 'woo-shopcom' ),
					'exclude' => __( '排除所選分類', 'woo-shopcom' ),
				),
			),
			array(
				'title'             => __( '篩選的商品分類', 'woo-shopcom' ),
				'id'                => self::OPTION_KEY . '[xml_filter_categories]',
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'options'           => $cat_options,
				'custom_attributes' => array( 'multiple' => 'multiple' ),
			),
			array(
				'title'   => __( '批次建立數量', 'woo-shopcom' ),
				'id'      => self::OPTION_KEY . '[xml_batch_size]',
				'type'    => 'number',
				'default' => '200',
				'desc'    => __( '大型商城建議分批背景建立，避免逗時。', 'woo-shopcom' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'shopcom_xml_title',
			),
		);
	}

	private function get_order_status_options() {
		$statuses = wc_get_order_statuses();
		$options  = array();
		foreach ( $statuses as $key => $label ) {
			$options[ str_replace( 'wc-', '', $key ) ] = $label;
		}
		return $options;
	}

	public function render_settings_tab() {
		$options    = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $this->defaults );
		$statuses   = $this->get_order_status_options();
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$feed_url   = home_url( '/?feed=shopcom' );
		?>
		<h2>美安（SHOP.COM）串接設定</h2>
		<p>設定美安提供的串接資料後，系統會記錄 RID／Click_ID，並依訂單狀態回傳成立或取消資訊。</p>
		<table class="form-table">
			<tr><th>啟用串接</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="yes" <?php checked( $options['enabled'], 'yes' ); ?> /> 啟用美安追蹤與訂單回傳</label></td></tr>
			<tr><th><label for="shopcom_offer_id">Offer ID</label></th><td><input id="shopcom_offer_id" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[offer_id]" value="<?php echo esc_attr( $options['offer_id'] ); ?>" /><p class="description">請填入美安提供的專屬 Offer ID。</p></td></tr>
			<tr><th><label for="shopcom_advertiser_id">Advertiser ID</label></th><td><input id="shopcom_advertiser_id" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[advertiser_id]" value="<?php echo esc_attr( $options['advertiser_id'] ); ?>" /><p class="description">請填入美安提供的專屬 Advertiser ID。</p></td></tr>
			<tr><th><label for="shopcom_commission_rate">佣金比例（%）</label></th><td><input id="shopcom_commission_rate" type="number" min="0" max="100" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[commission_rate]" value="<?php echo esc_attr( $options['commission_rate'] ); ?>" /></td></tr>
			<tr><th><label for="shopcom_api_base_url">API Base URL</label></th><td><input id="shopcom_api_base_url" class="regular-text code" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base_url]" value="<?php echo esc_attr( $options['api_base_url'] ); ?>" /><p class="description">請依美安最新技術文件填入 API 網域，並確認網站主機 IP 已加入白名單。</p></td></tr>
			<tr><th>連線與除錯</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[force_ipv4]" value="yes" <?php checked( $options['force_ipv4'], 'yes' ); ?> /> 強制使用 IPv4</label><br /><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_mode]" value="yes" <?php checked( $options['debug_mode'], 'yes' ); ?> /> 管理員在購物車／結帳頁顯示 RID 與 Click_ID 除錯資訊</label></td></tr>
			<tr><th><label for="shopcom_rid_cookie_days">追蹤資料保存天數</label></th><td><input id="shopcom_rid_cookie_days" type="number" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rid_cookie_days]" value="<?php echo esc_attr( $options['rid_cookie_days'] ); ?>" /><p class="description">目前追蹤值存於 WooCommerce Session；此欄保留供美安規格與後續 Cookie 模式使用。</p></td></tr>
		</table>

		<h2>訂單串接</h2>
		<table class="form-table">
			<tr><th><label for="shopcom_created_status">成立訂單回傳時機</label></th><td><select id="shopcom_created_status" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[order_created_status]"><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $options['order_created_status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th>自動取消回傳</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_cancel]" value="yes" <?php checked( $options['auto_cancel'], 'yes' ); ?> /> 訂單進入指定取消狀態時，自動通知美安</label></td></tr>
			<tr><th><label for="shopcom_cancel_status">取消訂單回傳時機</label></th><td><select id="shopcom_cancel_status" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[order_cancel_status]"><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $options['order_cancel_status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th>前台顯示</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_fraud_notice]" value="yes" <?php checked( $options['show_fraud_notice'], 'yes' ); ?> /> 感謝頁顯示防詐提醒</label><br /><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_account_status]" value="yes" <?php checked( $options['show_account_status'], 'yes' ); ?> /> 我的帳號訂單顯示美安同步狀態</label></td></tr>
			<tr><th><label for="shopcom_notify_emails">失敗通知收件人</label></th><td><textarea id="shopcom_notify_emails" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_emails]"><?php echo esc_textarea( $options['notify_emails'] ); ?></textarea><p class="description">多個 Email 以逗號分隔；留空不寄送。</p></td></tr>
		</table>

		<h2>商品 XML Feed</h2>
		<p>提供給美安的商品目錄網址：<code><?php echo esc_html( $feed_url ); ?></code>　<a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" rel="noopener noreferrer">預覽 XML</a></p>
		<table class="form-table">
			<tr><th><label for="shopcom_description_source">商品說明來源</label></th><td><select id="shopcom_description_source" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[xml_description_source]"><option value="short_description" <?php selected( $options['xml_description_source'], 'short_description' ); ?>>商品簡短說明</option><option value="description" <?php selected( $options['xml_description_source'], 'description' ); ?>>商品完整說明</option></select></td></tr>
			<tr><th><label for="shopcom_filter_mode">分類篩選方式</label></th><td><select id="shopcom_filter_mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[xml_filter_mode]"><option value="none" <?php selected( $options['xml_filter_mode'], 'none' ); ?>>不篩選</option><option value="include" <?php selected( $options['xml_filter_mode'], 'include' ); ?>>只包含所選分類</option><option value="exclude" <?php selected( $options['xml_filter_mode'], 'exclude' ); ?>>排除所選分類</option></select></td></tr>
			<tr><th><label for="shopcom_categories">商品分類</label></th><td><select id="shopcom_categories" class="wc-enhanced-select" multiple="multiple" style="width:420px;max-width:100%;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[xml_filter_categories][]"><?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $category ) : ?><option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, array_map( 'intval', (array) $options['xml_filter_categories'] ), true ) ); ?>><?php echo esc_html( $category->name ); ?></option><?php endforeach; endif; ?></select></td></tr>
			<tr><th><label for="shopcom_batch_size">每批商品數</label></th><td><input id="shopcom_batch_size" type="number" min="1" max="1000" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[xml_batch_size]" value="<?php echo esc_attr( $options['xml_batch_size'] ); ?>" /><p class="description">大型商店建議保留預設 200，避免產生目錄時逾時。</p></td></tr>
		</table>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shopcom_manual_regenerate' ), 'shopcom_manual_regenerate' ) ); ?>">立即重新建立商品 XML</a></p>
		<?php
	}

	public function save_settings_tab() {
		$posted = isset( $_POST[ self::OPTION_KEY ] ) && is_array( $_POST[ self::OPTION_KEY ] )
			? wp_unslash( $_POST[ self::OPTION_KEY ] )
			: array();
		$options = array(
			'enabled' => isset( $posted['enabled'] ) ? 'yes' : 'no',
			'offer_id' => sanitize_text_field( $posted['offer_id'] ?? '' ),
			'advertiser_id' => sanitize_text_field( $posted['advertiser_id'] ?? '' ),
			'commission_rate' => (string) min( 100, max( 0, (float) ( $posted['commission_rate'] ?? 10 ) ) ),
			'api_base_url' => esc_url_raw( $posted['api_base_url'] ?? '' ),
			'force_ipv4' => isset( $posted['force_ipv4'] ) ? 'yes' : 'no',
			'debug_mode' => isset( $posted['debug_mode'] ) ? 'yes' : 'no',
			'order_created_status' => sanitize_key( $posted['order_created_status'] ?? 'completed' ),
			'order_cancel_status' => sanitize_key( $posted['order_cancel_status'] ?? 'cancelled' ),
			'auto_cancel' => isset( $posted['auto_cancel'] ) ? 'yes' : 'no',
			'show_fraud_notice' => isset( $posted['show_fraud_notice'] ) ? 'yes' : 'no',
			'show_account_status' => isset( $posted['show_account_status'] ) ? 'yes' : 'no',
			'notify_emails' => sanitize_textarea_field( $posted['notify_emails'] ?? '' ),
			'xml_filter_mode' => in_array( $posted['xml_filter_mode'] ?? '', array( 'none', 'include', 'exclude' ), true ) ? $posted['xml_filter_mode'] : 'none',
			'xml_filter_categories' => array_values( array_filter( array_map( 'absint', (array) ( $posted['xml_filter_categories'] ?? array() ) ) ) ),
			'xml_batch_size' => (string) min( 1000, max( 1, absint( $posted['xml_batch_size'] ?? 200 ) ) ),
			'xml_description_source' => 'description' === ( $posted['xml_description_source'] ?? '' ) ? 'description' : 'short_description',
			'xml_exclude_types' => array( 'grouped' ),
			'rid_cookie_days' => (string) max( 1, absint( $posted['rid_cookie_days'] ?? 30 ) ),
		);
		update_option( self::OPTION_KEY, $options, false );
		delete_transient( ShopCom_XML_Feed::TRANSIENT_XML );
	}
}
