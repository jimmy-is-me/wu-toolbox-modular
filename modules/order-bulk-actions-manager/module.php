<?php
/**
 * WU Toolbox Modular：訂單批次操作管理。
 *
 * 統一管理傳統訂單列表與 HPOS 訂單列表的批次操作顯示狀態。
 */

defined( 'ABSPATH' ) || exit;

final class WUTM_Order_Bulk_Actions_Manager {
	private const OPTION_KEY = 'wutm_order_bulk_actions_hidden';
	private const PAGE_SLUG  = 'wu-order-bulk-actions-manager';
	private const NONCE      = 'wutm_order_bulk_actions_save';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'hide_actions' ), 9999 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'hide_actions' ), 9999 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'wu-toolbox-modular',
			'訂單批次操作管理',
			'訂單批次操作管理',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	private static function hidden_actions(): array {
		$hidden = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $hidden ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $hidden ) ) ) );
	}

	public static function hide_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		foreach ( self::hidden_actions() as $slug ) {
			unset( $actions[ $slug ] );
		}

		return $actions;
	}

	/**
	 * WooCommerce 兩種訂單列表原生提供的常用操作。
	 * 保留這份基準，讓操作被本模組隱藏後仍可在設定頁重新開啟。
	 */
	private static function core_actions(): array {
		$actions = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $status => $label ) {
				$slug = preg_replace( '/^wc-/', '', (string) $status );
				if ( ! $slug ) {
					continue;
				}
				$actions[ 'mark_' . $slug ] = sprintf( '變更狀態為「%s」', wp_strip_all_tags( $label ) );
			}
		}

		$actions['trash'] = '移至回收桶';
		return $actions;
	}

	/**
	 * 取得各訂單畫面的外掛擴充操作，但暫時略過本模組的隱藏規則。
	 */
	private static function discover_actions(): array {
		$screens = array(
			'edit-shop_order'            => '傳統訂單列表',
			'woocommerce_page_wc-orders' => 'HPOS 訂單列表',
		);
		$core    = self::core_actions();
		$found   = array();

		foreach ( $screens as $screen => $screen_label ) {
			$hook = 'bulk_actions-' . $screen;
			remove_filter( $hook, array( __CLASS__, 'hide_actions' ), 9999 );
			$actions = apply_filters( $hook, $core );
			add_filter( $hook, array( __CLASS__, 'hide_actions' ), 9999 );

			if ( ! is_array( $actions ) ) {
				continue;
			}
			foreach ( $actions as $slug => $label ) {
				$slug = sanitize_key( $slug );
				if ( ! $slug ) {
					continue;
				}
				if ( ! isset( $found[ $slug ] ) ) {
					$found[ $slug ] = array(
						'label'   => wp_strip_all_tags( is_scalar( $label ) ? (string) $label : $slug ),
						'screens' => array(),
					);
				}
				$found[ $slug ]['screens'][] = $screen_label;
			}
		}

		ksort( $found, SORT_NATURAL | SORT_FLAG_CASE );
		return $found;
	}

	public static function save_settings(): void {
		if ( empty( $_POST['wutm_order_bulk_actions_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '抱歉，您沒有管理此設定頁的權限。', 'wu-toolbox-modular' ) );
		}
		check_admin_referer( self::NONCE );

		$all     = isset( $_POST['wutm_all_actions'] ) ? (array) wp_unslash( $_POST['wutm_all_actions'] ) : array();
		$visible = isset( $_POST['wutm_visible_actions'] ) ? (array) wp_unslash( $_POST['wutm_visible_actions'] ) : array();
		$all     = array_values( array_unique( array_filter( array_map( 'sanitize_key', $all ) ) ) );
		$visible = array_values( array_unique( array_filter( array_map( 'sanitize_key', $visible ) ) ) );

		$existing_outside_scan = array_diff( self::hidden_actions(), $all );
		$newly_hidden          = array_diff( $all, $visible );
		update_option( self::OPTION_KEY, array_values( array_unique( array_merge( $existing_outside_scan, $newly_hidden ) ) ) );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$actions = self::discover_actions();
		$hidden  = self::hidden_actions();
		?>
		<div class="wrap wutm-module-wrap wutm-order-bulk-actions">
			<h1>訂單批次操作管理</h1>
			<p class="wutm-module-subtitle">集中控制 WooCommerce 傳統訂單與 HPOS 訂單列表中的批次操作。開啟代表顯示於下拉選單，關閉則隱藏；不會刪除訂單或變更其他外掛設定。</p>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定已儲存，重新載入訂單列表後即可看到結果。</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:1100px;padding:22px;margin-top:20px;">
				<div class="wutm-bulk-toolbar">
					<div><h2>批次操作顯示項目</h2><p>系統目前偵測到 <?php echo esc_html( (string) count( $actions ) ); ?> 個項目。</p></div>
					<div><button type="button" class="button" id="wutm-bulk-show-all">全部顯示</button> <button type="button" class="button" id="wutm-bulk-hide-all">全部隱藏</button></div>
				</div>

				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<?php if ( $actions ) : ?>
						<div class="wutm-bulk-list">
						<?php foreach ( $actions as $slug => $action ) :
							$is_visible = ! in_array( $slug, $hidden, true );
							?>
							<label class="wutm-bulk-row">
								<span class="wutm-bulk-switch"><input class="wutm-bulk-toggle" type="checkbox" name="wutm_visible_actions[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_visible ); ?>><i aria-hidden="true"></i></span>
								<span class="wutm-bulk-main"><strong><?php echo esc_html( $action['label'] ); ?></strong><code><?php echo esc_html( $slug ); ?></code></span>
								<span class="wutm-bulk-screens"><?php echo esc_html( implode( '、', array_unique( $action['screens'] ) ) ); ?></span>
								<input type="hidden" name="wutm_all_actions[]" value="<?php echo esc_attr( $slug ); ?>">
							</label>
						<?php endforeach; ?>
						</div>
						<p class="submit"><button type="submit" name="wutm_order_bulk_actions_submit" value="1" class="button button-primary">儲存設定</button></p>
					<?php else : ?>
						<p>目前沒有偵測到可管理的訂單批次操作，請確認 WooCommerce 已啟用。</p>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<style>
		.wutm-bulk-toolbar,.wutm-bulk-row{display:flex;align-items:center;justify-content:space-between;gap:18px}.wutm-bulk-toolbar h2{margin:0}.wutm-bulk-toolbar p{margin:5px 0 0;color:#646970}.wutm-bulk-list{margin-top:18px;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}.wutm-bulk-row{padding:14px 16px;background:#fff;border-bottom:1px solid #eee;cursor:pointer}.wutm-bulk-row:last-child{border-bottom:0}.wutm-bulk-row:hover{background:#f6f7f7}.wutm-bulk-main{display:flex;align-items:center;gap:10px;flex:1}.wutm-bulk-main code{font-size:12px}.wutm-bulk-screens{color:#646970;min-width:230px;text-align:right}.wutm-bulk-switch{position:relative;width:42px;height:24px;flex:0 0 42px}.wutm-bulk-switch input{opacity:0;width:0;height:0}.wutm-bulk-switch i{position:absolute;inset:0;border-radius:24px;background:#8c8f94;transition:.2s}.wutm-bulk-switch i:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s}.wutm-bulk-switch input:checked+i{background:#2271b1}.wutm-bulk-switch input:checked+i:before{transform:translateX(18px)}@media(max-width:782px){.wutm-bulk-toolbar{align-items:flex-start;flex-direction:column}.wutm-bulk-row{align-items:flex-start;flex-wrap:wrap}.wutm-bulk-main{align-items:flex-start;flex-direction:column;gap:4px}.wutm-bulk-screens{width:100%;min-width:0;text-align:left;padding-left:60px}}
		</style>
		<script>
		(function(){var toggles=function(){return document.querySelectorAll('.wutm-bulk-toggle');};document.getElementById('wutm-bulk-show-all')?.addEventListener('click',function(){toggles().forEach(function(input){input.checked=true;});});document.getElementById('wutm-bulk-hide-all')?.addEventListener('click',function(){toggles().forEach(function(input){input.checked=false;});});}());
		</script>
		<?php
	}
}

WUTM_Order_Bulk_Actions_Manager::init();
