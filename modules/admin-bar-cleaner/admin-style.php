<?php
/** Optional admin styles adapted from the supplied Wu style reference. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WUTM_Admin_Style', false ) ) {

final class WUTM_Admin_Style {

	const VERSION = '6.2.1', OPTION = 'np_admin_opts', GROUP = 'wu_admin_style_group', SLUG = 'wu-admin-bar-cleaner';
	private static $o = null;

	/* ---------- 啟動 ---------- */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'migrate' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ), 9999 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ), 9999 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 9999 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ), 9999 );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 9999 );
		add_action( 'in_admin_header', array( __CLASS__, 'edition' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_title' ) );
	}

	/* ---------- 設定值 ---------- */
	private static function defaults() {
		return array(
			'schema' => 0, 'theme' => 'native', 'enable_admin' => 1, 'enable_login' => 1, 'enable_bar' => 1,
			'show_switcher' => 1, 'hide_wp_logo' => 1, 'show_edition' => 1, 'load_fonts' => 1, 'menu_accent' => 1,
			'accent' => '', 'edition' => 'Vol 1.0', 'powered' => 'Wumetax', 'link_label' => '網站功能與整合：',
			'link_text' => 'Wumetax', 'link_url' => 'https://wumetax.com/website-integration/', 'site_name' => '',
		);
	}

	private static function get( $k ) {
		if ( null === self::$o ) {
			self::$o = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		return self::$o[ $k ] ?? '';
	}

	public static function migrate() {
		if ( ! current_user_can( 'manage_options' ) || get_option(self::OPTION, null) === null ) return;
		$old = (array) get_option( self::OPTION, array() );
		if ( ! empty( $old['schema'] ) ) return;
		$new   = wp_parse_args( $old, self::defaults() );
		$known = ! empty( $old['wu_schema_600'] ) || ! empty( $old['wu_schema_610'] ) || ! empty( $old['wu_schema_620'] );
		$t     = isset( $old['theme'] ) && is_string( $old['theme'] ) ? $old['theme'] : 'native';
		if ( ! $known || ! isset( self::themes()[ $t ] ) ) { $new['theme'] = 'native'; $new['accent'] = ''; }
		$new['load_fonts'] = 1; $new['menu_accent'] = 1; $new['schema'] = 1;
		foreach ( array( 'wu_schema_600','wu_schema_610','wu_schema_620','hide_lang','hide_privacy','hide_bar_ids','visual_motion','perf' ) as $k ) unset( $new[ $k ] );
		update_option( self::OPTION, $new );
		self::$o = null;
	}

	/* ---------- 風格參數 ---------- */
	private static function themes() {
		$sans  = '-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft JhengHei",sans-serif';
		$serif = '"PMingLiU",Georgia,serif';
		$dots  = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'4\' height=\'4\' viewBox=\'0 0 4 4\'%3E%3Cpath fill=\'%23111111\' fill-opacity=\'0.04\' d=\'M1 3h1v1H1V3zm2-2h1v1H3V1z\'/%3E%3C/svg%3E")';
		$noise = 'url("data:image/svg+xml,%3Csvg viewBox=\'0 0 400 400\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'n\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'.9\' numOctaves=\'3\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23n)\' opacity=\'.05\'/%3E%3C/svg%3E")';
		$b = array(
			'bgimg' => 'none', 'stripe' => '#F6F7F7', 'rowline' => '#E5E7EB', 'rowhover' => '#EEF2F7',
			'bw' => '1px', 'nb' => '1px', 'nl' => '4px', 'shadow' => 'none', 'btn_shadow' => 'none',
			'btn_line' => '#111111', 'btn_bw' => '1px', 'btn2' => '#FFFFFF', 'btn2line' => '#111111',
			'btn2h' => '#EEEEEE', 'btn2hink' => '#111111', 'menu_ink' => '#E6EAE7', 'mtf' => 'none', 'mls' => 'normal',
			'cur_filter' => 'brightness(0) invert(1)', 'barb' => 'none', 'hw' => '700', 'htf' => 'none',
			'hls' => '0', 'h1b' => 'none', 'uitf' => 'none', 'uils' => '0', 'inb' => '1px solid #8C8F94',
			'inbb' => '1px solid #8C8F94', 'inbg' => '#FFFFFF', 'infocus' => '#FFFFFF', 'ftb' => 'none',
		);

		return array(
			'native' => array( 'label' => 'WordPress 原生', 'desc' => '預設選項；不載入後台、登入頁或工具列風格美化。' ),

			'newsprint' => array_merge( $b, array(
				'label' => 'Newsprint 報紙風', 'desc' => '報紙點狀背景、超大襯線標題、黑色表頭、等寬刊頭與粗底線。',
				'google' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;500&family=Lora:wght@400;600&family=Playfair+Display:wght@700;900&display=swap',
				'bg' => '#F9F9F7', 'bgimg' => $dots, 'ink' => '#111111', 'surface' => '#FCFCFA', 'soft' => '#E5E5E0',
				'stripe' => '#F2F2EE', 'line' => '#111111', 'rowline' => '#CFCFC8', 'rowhover' => '#E5E5E0', 'nl' => '6px',
				'btn2' => '#F9F9F7', 'btn2h' => '#111111', 'btn2hink' => '#F9F9F7',
				'menu' => '#111111', 'mtf' => 'uppercase', 'mls' => '.04em', 'cur_bg' => '#CC0000', 'cur_ink' => '#FFFFFF',
				'bar' => '#111111', 'bar_ink' => '#F9F9F7', 'barb' => '4px solid #CC0000',
				'primary' => '#111111', 'on' => '#FFFFFF', 'hover' => '#333333', 'link' => '#111111',
				'link_hover' => '#CC0000', 'accent' => '#CC0000', 'muted' => '#626262',
				'thbg' => '#111111', 'think' => '#F9F9F7',
				'heading' => '"Playfair Display",' . $serif, 'font' => '"Lora",Georgia,"Microsoft JhengHei",sans-serif',
				'ui' => '"Inter",' . $sans, 'mono' => '"JetBrains Mono","Consolas","Microsoft JhengHei",monospace',
				'hw' => '900', 'hls' => '-.03em', 'h1b' => '4px solid #111111', 'uitf' => 'uppercase', 'uils' => '.08em',
				'inb' => '0 none', 'inbb' => '2px solid #111111', 'inbg' => 'transparent', 'infocus' => '#F0F0F0', 'focus' => '#111111',
				'ok' => '#111111', 'err' => '#CC0000', 'warn' => '#737373', 'info' => '#111111',
				'ftbg' => '#F9F9F7', 'ftink' => '#626262', 'ftlink' => '#111111', 'ftb' => '4px solid #111111',
			) ),

			'botanical' => array_merge( $b, array(
				'label' => 'Botanical 植物風', 'desc' => '森林綠與暖白、紙張顆粒、襯線標題與表頭、細線與柔和陰影。',
				'google' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Source+Sans+3:wght@400;600;700&family=Noto+Serif+TC:wght@500;700&display=swap',
				'bg' => '#F9F8F4', 'bgimg' => $noise, 'ink' => '#2D3A31', 'surface' => '#FFFFFF', 'soft' => '#F2F0EB',
				'stripe' => '#FBFAF7', 'line' => '#E6E2DA', 'rowline' => '#E6E2DA', 'rowhover' => '#F2F0EB',
				'shadow' => '0 10px 15px -3px rgba(45,58,49,.06)', 'btn_line' => '#2D3A31',
				'btn2' => 'transparent', 'btn2line' => '#8C9A84', 'btn2h' => '#8C9A84', 'btn2hink' => '#17251B',
				'menu' => '#263229', 'cur_bg' => '#8C9A84', 'cur_ink' => '#17251B', 'cur_filter' => 'brightness(0)',
				'bar' => '#2D3A31', 'bar_ink' => '#F2F0EB', 'barb' => '3px solid #8C9A84',
				'primary' => '#2D3A31', 'on' => '#FFFFFF', 'hover' => '#A8604D', 'link' => '#334F3B',
				'link_hover' => '#A8604D', 'accent' => '#C27B66', 'muted' => '#617065',
				'thbg' => '#F2F0EB', 'think' => '#2D3A31',
				'heading' => '"Playfair Display","Noto Serif TC",' . $serif, 'font' => '"Source Sans 3",' . $sans,
				'ui' => '"Source Sans 3",' . $sans, 'mono' => '"Source Sans 3",' . $sans,
				'hls' => '-.01em', 'h1b' => '1px solid #E6E2DA', 'uitf' => 'uppercase', 'uils' => '.1em',
				'inb' => '0 none', 'inbb' => '1px solid #8C9A84', 'inbg' => 'transparent', 'infocus' => '#F2F0EB', 'focus' => '#8C9A84',
				'ok' => '#8C9A84', 'err' => '#C27B66', 'warn' => '#DCCFC2', 'info' => '#8C9A84',
				'ftbg' => 'transparent', 'ftink' => '#617065', 'ftlink' => '#2D3A31', 'ftb' => '1px solid #E6E2DA',
			) ),

			'flat' => array_merge( $b, array(
				'label' => 'Flat 扁平風', 'desc' => '灰底白色塊、無框無陰影、藍色按鈕、灰底輸入框與淡色通知。',
				'google' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap',
				'bg' => '#F3F4F6', 'ink' => '#111827', 'surface' => '#FFFFFF', 'soft' => '#E5E7EB', 'stripe' => '#F9FAFB',
				'line' => '#E5E7EB', 'rowline' => '#F3F4F6', 'rowhover' => '#EFF6FF', 'bw' => '0px', 'nb' => '0px', 'nl' => '0px',
				'btn_line' => 'transparent', 'btn_bw' => '0px', 'btn2' => '#E5E7EB', 'btn2line' => 'transparent',
				'btn2h' => '#D1D5DB', 'btn2hink' => '#111827',
				'menu' => '#111827', 'cur_bg' => '#2563EB', 'cur_ink' => '#FFFFFF', 'bar' => '#111827', 'bar_ink' => '#E5E7EB',
				'primary' => '#2563EB', 'on' => '#FFFFFF', 'hover' => '#1D4ED8', 'link' => '#1D4ED8', 'link_hover' => '#111827',
				'accent' => '#F59E0B', 'muted' => '#596273', 'thbg' => '#111827', 'think' => '#FFFFFF',
				'heading' => '"Outfit",' . $sans, 'font' => '"Outfit",' . $sans, 'ui' => '"Outfit",' . $sans, 'mono' => '"Outfit",' . $sans,
				'hw' => '800', 'hls' => '-.02em', 'uitf' => 'uppercase', 'uils' => '.05em',
				'inb' => '2px solid transparent', 'inbb' => '2px solid transparent', 'inbg' => '#E5E7EB', 'infocus' => '#FFFFFF', 'focus' => '#2563EB',
				'ok' => '#10B981', 'err' => '#DC2626', 'warn' => '#F59E0B', 'info' => '#3B82F6',
				'ftbg' => '#111827', 'ftink' => '#9CA3AF', 'ftlink' => '#FFFFFF',
			) ),

			'bauhaus' => array_merge( $b, array(
				'label' => 'Bauhaus 包浩斯', 'desc' => '粗黑框、硬陰影、紅藍黃色塊、黑色表頭與幾何標題。',
				'google' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;900&display=swap',
				'bg' => '#F0F0F0', 'ink' => '#121212', 'surface' => '#FFFFFF', 'soft' => '#E0E0E0', 'stripe' => '#F7F7F7',
				'line' => '#121212', 'rowline' => '#CFCFCF', 'rowhover' => '#FFF4C2', 'bw' => '2px', 'nb' => '2px', 'nl' => '8px',
				'shadow' => '6px 6px 0 #121212', 'btn_shadow' => '3px 3px 0 #121212', 'btn_line' => '#121212', 'btn_bw' => '2px',
				'btn2' => '#FFFFFF', 'btn2line' => '#121212', 'btn2h' => '#F0C020', 'btn2hink' => '#121212',
				'menu' => '#121212', 'mtf' => 'uppercase', 'mls' => '.04em', 'cur_bg' => '#F0C020', 'cur_ink' => '#121212', 'cur_filter' => 'brightness(0)',
				'bar' => '#121212', 'bar_ink' => '#F0F0F0', 'barb' => '4px solid #F0C020',
				'primary' => '#D02020', 'on' => '#FFFFFF', 'hover' => '#B01919', 'link' => '#1040C0', 'link_hover' => '#D02020',
				'accent' => '#D02020', 'muted' => '#4A4A4A', 'thbg' => '#121212', 'think' => '#FFFFFF',
				'heading' => '"Outfit",' . $sans, 'font' => '"Outfit",' . $sans, 'ui' => '"Outfit",' . $sans, 'mono' => '"Outfit",' . $sans,
				'hw' => '900', 'htf' => 'uppercase', 'hls' => '-.03em', 'h1b' => '4px solid #121212', 'uitf' => 'uppercase', 'uils' => '.08em',
				'inb' => '2px solid #121212', 'inbb' => '2px solid #121212', 'inbg' => '#FFFFFF', 'infocus' => '#FFF9C4', 'focus' => '#1040C0',
				'ok' => '#1040C0', 'err' => '#D02020', 'warn' => '#F0C020', 'info' => '#121212',
				'ftbg' => '#121212', 'ftink' => '#F0F0F0', 'ftlink' => '#F0C020', 'ftb' => '4px solid #121212',
			) ),
		);
	}

	private static function theme_key() {
		if ( ! self::get( 'schema' ) && ! get_option( self::OPTION . '_legacy_ok' ) ) {
			$raw = (array) get_option( self::OPTION, array() );
			if ( empty( $raw['wu_schema_600'] ) && empty( $raw['wu_schema_610'] ) && empty( $raw['wu_schema_620'] ) ) return 'native';
		}
		$k = self::get( 'theme' );
		return is_string( $k ) && isset( self::themes()[ $k ] ) ? $k : 'native';
	}
	private static function styled() { return 'native' !== self::theme_key(); }
	private static function theme()  { return self::themes()[ self::theme_key() ]; }

	/* ---------- 選單與儲存 ---------- */
	public static function menu() {}

	public static function register() {
		register_setting( self::GROUP, self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array( 'schema' => 1 );
		foreach ( array( 'enable_admin','enable_login','enable_bar','show_switcher','hide_wp_logo','show_edition','load_fonts','menu_accent' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$t = sanitize_key( is_string($in['theme'] ?? null) ? $in['theme'] : 'native' );
		$out['theme']  = isset( self::themes()[ $t ] ) ? $t : 'native';
		$out['accent'] = sanitize_hex_color( is_string($in['accent'] ?? null) ? $in['accent'] : '' ) ?: '';
		if ( $out['theme'] !== self::theme_key() && $out['accent'] === self::get( 'accent' ) ) $out['accent'] = '';
		foreach ( array( 'edition','powered','link_label','link_text','site_name' ) as $k ) $out[ $k ] = sanitize_text_field( $in[ $k ] ?? '' );
		$out['link_url'] = esc_url_raw( is_string($in['link_url'] ?? null) ? $in['link_url'] : '' );
		return $out;
	}

	public static function body_class( $c ) {
		if ( self::get( 'enable_admin' ) && self::styled() ) {
			$c .= ' wu-admin-style wu-theme-' . self::theme_key();
			if ( self::get( 'menu_accent' ) ) $c .= ' wu-menu-accent';
		}
		return $c;
	}

	/* ---------- 設定頁 ---------- */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$n = self::OPTION;
		$checks = array(
			'enable_admin'  => '套用到後台',
			'enable_login'  => '套用到登入頁',
			'enable_bar'    => '套用到前台管理工具列',
			'menu_accent'   => '左側選單目前所在項目使用強調色整列色塊',
			'show_edition'  => '顯示後台日期與網站名稱',
			'show_switcher' => '工具列顯示 Wu 後台風格入口（管理員）',
			'load_fonts'    => '載入 Google Fonts（建議開啟，各風格的標題字型依賴它）',
			'hide_wp_logo'  => '移除工具列 WordPress 圖示及子選單',
		);
		$fields = array(
			'edition' => array( 'Edition', 'text' ), 'powered' => array( 'Powered by', 'text' ),
			'link_label' => array( '連結前綴', 'text' ), 'link_text' => array( '連結文字', 'text' ),
			'link_url' => array( '連結網址', 'url' ), 'site_name' => array( '網站名稱（留空使用網站標題）', 'text' ),
			'accent' => array( '強調色（例如 #D02020，留空使用風格預設）', 'text' ),
		);
		?>
		<div class="wrap wu-settings">
			<header class="wu-settings-header">
				<div>
					<p class="wu-kicker">WUMETAX / ADMIN APPEARANCE</p>
					<h1>Wu 後台風格</h1>
					<p class="wu-header-description">版本 <?php echo esc_html( self::VERSION ); ?>｜原生為預設，可切換四種完整設計風格。</p>
				</div>
				<div class="wu-geometry" aria-hidden="true"><span class="wu-shape-circle"></span><span class="wu-shape-square"></span><span class="wu-shape-triangle"></span></div>
			</header>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<section class="wu-settings-section">
					<h2>風格切換</h2>
					<fieldset class="wu-theme-options">
						<legend class="screen-reader-text">選擇後台風格</legend>
						<?php foreach ( self::themes() as $key => $t ) : ?>
							<label class="wu-theme-choice">
								<input type="radio" name="<?php echo esc_attr( $n ); ?>[theme]" value="<?php echo esc_attr( $key ); ?>" <?php checked( self::theme_key(), $key ); ?>>
								<span class="wu-choice-copy">
									<strong><?php echo esc_html( $t['label'] ); ?></strong>
									<span class="wu-theme-description"><?php echo esc_html( $t['desc'] ); ?></span>
									<?php if ( 'native' !== $key ) : ?>
										<span class="wu-swatches" aria-hidden="true">
											<?php foreach ( array( 'bg', 'ink', 'accent', 'primary' ) as $c ) echo '<i style="background:' . esc_attr( $t[ $c ] ) . '"></i>'; ?>
										</span>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</section>
				<section class="wu-settings-section">
					<h2>啟用範圍與選項</h2>
					<?php foreach ( $checks as $k => $l ) : ?>
						<label class="wu-option-row">
							<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[<?php echo esc_attr( $k ); ?>]" value="1" <?php checked( (bool) self::get( $k ) ); ?>>
							<?php echo esc_html( $l ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description">選擇「WordPress 原生」時，不載入後台、登入頁與工具列的風格美化。本外掛不修改 Heartbeat、自動儲存或編輯器內容。</p>
				</section>
				<section class="wu-settings-section">
					<h2>頁尾設定</h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $k => $f ) : ?>
							<tr>
								<th scope="row"><label for="wu-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
								<td><input id="wu-<?php echo esc_attr( $k ); ?>" type="<?php echo esc_attr( $f[1] ); ?>" class="regular-text" name="<?php echo esc_attr( $n ); ?>[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( self::get( $k ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</section>
				<p class="submit"><button type="submit" class="button button-primary wu-save-button">儲存設定</button></p>
			</form>
		</div>
		<?php
	}

	/* ---------- 載入資源 ---------- */
	private static function enqueue_css( $h, $css ) {
		wp_register_style( $h, false, array(), self::VERSION );
		wp_enqueue_style( $h );
		wp_add_inline_style( $h, $css );
	}

	private static function fonts() {
		if ( self::get( 'load_fonts' ) && self::styled() ) {
			wp_enqueue_style( 'wu-admin-fonts', self::theme()['google'], array(), null );
		}
	}

	private static function b( $css ) {
		$inputs = 'input[type=text],input[type=search],input[type=email],input[type=url],input[type=password],input[type=number],input[type=date],input[type=tel],select,textarea';
		return str_replace( array( '%B%', '%I%' ), array( 'body.wu-admin-style', $inputs ), $css );
	}

	private static function tokens( $sel ) {
		$css = $sel . '{';
		foreach ( self::theme() as $k => $v ) {
			if ( in_array( $k, array( 'label', 'desc', 'google' ), true ) ) continue;
			$css .= '--wu-' . str_replace( '_', '-', $k ) . ':' . $v . ';';
		}
		$acc = sanitize_hex_color( self::get( 'accent' ) );
		if ( $acc ) $css .= '--wu-accent:' . $acc . ';--wu-link-hover:' . $acc . ';';
		return $css . '}';
	}

	public static function admin_assets( $hook ) {
		if ( self::get( 'enable_admin' ) && self::styled() ) {
			self::fonts();
			self::enqueue_css(
				'wu-admin-621',
				self::tokens( 'body.wu-admin-style' ) . self::b( self::base_css() ) . self::b( self::menu_css() )
				. self::toolbar_css( 'body.wu-admin-style ' ) . self::b( self::theme_css( self::theme_key() ) )
			);
			self::enqueue_css('wutm-style-toolbox-bridge', 'body.wu-admin-style.wutm-admin-screen{--wutm-ink:var(--wu-ink);--wutm-muted:var(--wu-muted);--wutm-line:var(--wu-line);--wutm-paper:var(--wu-bg);--wutm-blue:var(--wu-primary);--wutm-blue-dark:var(--wu-link)}body.wu-admin-style.wutm-admin-screen .wutm-ui-header{background:var(--wu-primary)!important;color:var(--wu-on)!important}body.wu-admin-style.wutm-admin-screen .wutm-ui-header :is(h1,h2,p){color:var(--wu-on)!important}');
		}
		if ( isset($_GET['page']) && $_GET['page'] === self::SLUG && ($_GET['tab'] ?? '') === 'appearance' ) {
			self::enqueue_css( 'wu-settings-621', self::settings_css() );
		}
	}

	public static function front_assets() {
		if ( ! self::get( 'enable_bar' ) || ! self::styled() || ! is_admin_bar_showing() ) return;
		self::fonts();
		self::enqueue_css( 'wu-toolbar-621', self::tokens( '#wpadminbar' ) . self::toolbar_css() );
	}

	/* ---------- CSS：設定頁 ---------- */
	private static function settings_css() {
		return <<<'CSS'
body:not(.wu-admin-style) .wu-settings{--wu-bg:#f0f0f1;--wu-ink:#1d2327;--wu-surface:#fff;--wu-soft:#f6f7f7;--wu-line:#c3c4c7;--wu-primary:#2271b1;--wu-on:#fff;--wu-hover:#135e96;--wu-muted:#50575e;--wu-accent:#2271b1}
body:not(.wu-admin-style) .wu-settings .wu-save-button{display:inline-flex;align-items:center;min-height:44px;padding:9px 24px;background:var(--wu-primary);border:1px solid var(--wu-primary);border-radius:0;color:var(--wu-on);font-weight:600}
body:not(.wu-admin-style) .wu-settings .wu-save-button:hover{background:var(--wu-hover)}
.wu-settings{max-width:1180px}
.wu-settings-header{display:grid;grid-template-columns:minmax(0,1fr) 160px;align-items:center;gap:24px;padding:24px 0;margin-bottom:24px;border-bottom:1px solid var(--wu-line);position:relative}
.wu-settings-header h1{margin:8px 0 12px;padding:0;color:var(--wu-ink);font-size:clamp(28px,3vw,42px);line-height:1.25;border:0}
.wu-settings-header h1::after{content:none!important}
.wu-kicker{font-size:11px;letter-spacing:.12em;margin:0;color:var(--wu-muted)}
.wu-header-description{color:var(--wu-muted);margin:0}
.wu-geometry{display:flex;gap:12px;align-items:center;justify-content:center}
.wu-geometry span{display:block;width:32px;height:32px}
.wu-shape-circle{background:#D02020;border-radius:50%}
.wu-shape-square{background:#1040C0}
.wu-shape-triangle{background:#F0C020;clip-path:polygon(50% 0,100% 100%,0 100%)}
.wu-settings-section{margin:0 0 28px;padding:0 0 24px;border-bottom:1px solid var(--wu-line)}
.wu-theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;min-width:0;padding:4px;border:0;margin:0}
.wu-settings .wu-theme-choice{display:flex;align-items:flex-start;gap:12px;padding:20px;background:var(--wu-surface);color:var(--wu-ink);border:1px solid var(--wu-line);border-radius:0;cursor:pointer;min-width:0}
.wu-theme-choice:has(input:checked){outline:2px solid var(--wu-accent);outline-offset:2px}
.wu-theme-choice input{flex:none;margin-top:3px}
.wu-choice-copy{min-width:0}
.wu-theme-choice strong{color:var(--wu-ink);font-size:15px}
.wu-theme-description{display:block;color:var(--wu-muted);font-size:13px;line-height:1.6;margin-top:8px}
.wu-swatches{display:flex;gap:6px;margin-top:14px}
.wu-swatches i{width:20px;height:20px;border:1px solid var(--wu-line)}
.wu-option-row{display:block;margin:12px 0;line-height:1.6}
.wu-settings input.regular-text{width:min(100%,480px);box-sizing:border-box}
@media(max-width:1000px){.wu-theme-options{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.wu-theme-options{grid-template-columns:1fr}.wu-settings-header{grid-template-columns:1fr}.wu-geometry{justify-content:flex-start}}
body .wu-settings .wu-settings-header h1.wutm-ui-title{background:none!important;color:var(--wu-ink)!important;border:0!important;border-radius:0!important;margin:8px 0 12px!important;padding:0!important;font-size:clamp(28px,3vw,42px)!important;line-height:1.25!important;box-shadow:none!important}
body .wu-settings .wu-settings-header h1.wutm-ui-title::before{display:none!important}
CSS;
	}

	/* ---------- CSS：共用結構（全部由風格參數驅動） ---------- */
	private static function base_css() {
		return <<<'CSS'
html.wp-toolbar{background:var(--wu-bg)}
%B%{background-color:var(--wu-bg);background-image:var(--wu-bgimg);color:var(--wu-ink);font-family:var(--wu-font);font-size:14px;line-height:1.65}
%B% #wpwrap,%B% #wpcontent,%B% #wpbody{background:transparent}
%B% :is(.button,.components-button,.postbox,.postbox-header,.card,.stuffbox,.plugin-card,.theme,.welcome-panel,.notice,.nav-tab,.wp-list-table,.widefat,.page-title-action,.tablenav-pages a,select,textarea),%B% input:not([type=radio]):not([type=range]){border-radius:0!important}

%B% .wrap h1,%B% .wrap h1.wp-heading-inline{font-family:var(--wu-heading);font-size:clamp(28px,3.2vw,44px);font-weight:var(--wu-hw);text-transform:var(--wu-htf);letter-spacing:var(--wu-hls);line-height:1.12;color:var(--wu-ink);padding:10px 0;margin:0 12px 0 0}
%B% .wrap hr.wp-header-end{visibility:visible;border:0;border-top:var(--wu-h1b);margin:0 0 18px;height:0}
%B% .wrap h2,%B% .wrap h3{font-family:var(--wu-heading);font-weight:var(--wu-hw);text-transform:var(--wu-htf);letter-spacing:var(--wu-hls);color:var(--wu-ink)}
%B% :is(.description,p.description,.form-table td p){color:var(--wu-muted)}
%B% :is(.form-table th,.form-table label){font-family:var(--wu-ui);color:var(--wu-ink);font-weight:600}
%B% .wu-settings-header{border-bottom:var(--wu-h1b,1px solid var(--wu-line))}

%B% :is(.wp-list-table,.subsubsub,.row-actions,.form-table,.postbox .inside,.notice,.wrap > p) a:not(.button){color:var(--wu-link);text-underline-offset:3px}
%B% :is(.wp-list-table,.subsubsub,.row-actions,.form-table,.postbox .inside,.notice,.wrap > p) a:not(.button):is(:hover,:focus){color:var(--wu-link-hover)}
%B% .row-title{font-weight:700}
%B% :is(.submitdelete,.button-link-delete){color:var(--wu-err)!important}

%B% #wpbody-content :is(a.button,button.button,input.button,a.page-title-action){font-family:var(--wu-ui);font-weight:600;font-size:12px;text-transform:var(--wu-uitf);letter-spacing:var(--wu-uils);min-height:36px;line-height:1.4;padding:7px 16px;transition:background-color .15s,color .15s;text-shadow:none}
%B% #wpbody-content :is(a.button-small,button.button-small,input.button-small){min-height:28px;padding:3px 10px;font-size:11px}
%B% #wpbody-content .tablenav-pages :is(a.button,span.button,span.tablenav-pages-navspan){padding:0 4px;min-width:30px;font-size:16px;letter-spacing:0}
%B% #wpbody-content :is(a.button,button.button,input.button,a.page-title-action):not(.button-primary):not(.button-link):not(.wp-hide-pw):not(:disabled):not(.disabled){background:var(--wu-btn2)!important;background-image:none!important;border:var(--wu-btn-bw) solid var(--wu-btn2line)!important;color:var(--wu-ink)!important;-webkit-text-fill-color:var(--wu-ink)!important;box-shadow:var(--wu-btn-shadow)!important}
%B% #wpbody-content :is(a.button,button.button,input.button,a.page-title-action):not(.button-primary):not(.button-link):not(.wp-hide-pw):not(:disabled):not(.disabled):is(:hover,:focus){background:var(--wu-btn2h)!important;color:var(--wu-btn2hink)!important;-webkit-text-fill-color:var(--wu-btn2hink)!important}
%B% #wpbody-content :is(a.button-primary,button.button-primary,input.button-primary):not(:disabled):not(.disabled){background:var(--wu-primary)!important;background-image:none!important;border:var(--wu-btn-bw) solid var(--wu-btn-line)!important;color:var(--wu-on)!important;-webkit-text-fill-color:var(--wu-on)!important;box-shadow:var(--wu-btn-shadow)!important}
%B% #wpbody-content :is(a.button-primary,button.button-primary,input.button-primary):not(:disabled):not(.disabled):is(:hover,:focus){background:var(--wu-hover)!important}
%B% .button-primary > :is(span:not(.spinner),strong,b,i){color:inherit!important;-webkit-text-fill-color:inherit!important}
%B% #wpbody-content :is(.button:disabled,.button.disabled){background:var(--wu-soft)!important;border:1px solid var(--wu-line)!important;color:var(--wu-muted)!important;-webkit-text-fill-color:var(--wu-muted)!important;box-shadow:none!important;cursor:not-allowed}

%B% #wpbody-content :is(%I%){background-color:var(--wu-inbg);border:var(--wu-inb);border-bottom:var(--wu-inbb);color:var(--wu-ink);font-family:var(--wu-mono);box-shadow:none}
%B% #wpbody-content :is(%I%):focus{background-color:var(--wu-infocus);border-color:var(--wu-focus);box-shadow:none;outline:0}
%B% :is(input[type=checkbox],input[type=radio]){border:2px solid var(--wu-ink);background:var(--wu-surface);box-shadow:none}
%B% input[type=checkbox]:checked{background:var(--wu-ink);border-color:var(--wu-ink)}
%B% input[type=checkbox]:checked::before{filter:brightness(0) invert(1)}
%B% input[type=radio]:checked::before{background:var(--wu-ink)}
%B% #wpbody-content :is(a,button,input,select,textarea):focus-visible{outline:2px solid var(--wu-focus);outline-offset:2px}

%B% #wpbody-content :is(.postbox,.card,.stuffbox,.welcome-panel,.plugin-card,.theme){background:var(--wu-surface);border:var(--wu-bw) solid var(--wu-line);box-shadow:var(--wu-shadow);color:var(--wu-ink)}
%B% .welcome-panel{background-image:none}
%B% .postbox-header{border-bottom:var(--wu-bw) solid var(--wu-line)}
%B% :is(.postbox .hndle,.postbox-header h2){font-family:var(--wu-heading);font-weight:var(--wu-hw);text-transform:var(--wu-htf);letter-spacing:0}

%B% :is(.wp-list-table,.widefat){background:var(--wu-surface);border:var(--wu-bw) solid var(--wu-line);box-shadow:var(--wu-shadow)}
%B% .widefat :is(td,th){border-bottom:1px solid var(--wu-rowline);color:var(--wu-ink)}
%B% .widefat :is(thead,tfoot) :is(th,td){background:var(--wu-thbg);color:var(--wu-think);border-color:var(--wu-line);font-family:var(--wu-ui);font-size:12px;font-weight:700;text-transform:var(--wu-uitf);letter-spacing:var(--wu-uils)}
%B% .widefat :is(thead,tfoot) :is(th,td) a,%B% .widefat :is(thead,tfoot) .sorting-indicator::before{color:var(--wu-think)!important}
%B% .striped>tbody>:nth-child(odd){background:var(--wu-stripe)}
%B% .striped>tbody>:nth-child(even){background:var(--wu-surface)}
%B% .wp-list-table tbody tr:hover{background:var(--wu-rowhover)}
%B% .row-actions{font-family:var(--wu-ui);font-size:12px}

%B% :is(.notice,div.updated,div.error,.update-nag){background:var(--wu-surface);color:var(--wu-ink);border:var(--wu-nb) solid var(--wu-line);border-left:var(--wu-nl) solid var(--wu-info);box-shadow:var(--wu-btn-shadow)}
%B% :is(.notice-success,div.updated){border-left-color:var(--wu-ok)}
%B% :is(.notice-error,div.error){border-left-color:var(--wu-err)}
%B% .notice-warning{border-left-color:var(--wu-warn)}

%B% .nav-tab-wrapper{border-bottom:var(--wu-bw) solid var(--wu-line)}
%B% .nav-tab{background:var(--wu-soft);border:var(--wu-nb) solid var(--wu-line);color:var(--wu-ink);font-family:var(--wu-ui);font-weight:600;font-size:12px;text-transform:var(--wu-uitf);letter-spacing:var(--wu-uils)}
%B% .nav-tab:is(:hover,:focus){background:var(--wu-btn2h);color:var(--wu-btn2hink)}
%B% :is(.nav-tab-active,.nav-tab-active:hover,.nav-tab-active:focus){background:var(--wu-primary);border-color:var(--wu-primary);color:var(--wu-on)}
%B% .subsubsub{font-family:var(--wu-ui)}
%B% .subsubsub a.current{color:var(--wu-ink)!important;font-weight:700;border-bottom:3px solid var(--wu-accent)}
%B% .subsubsub .count{color:var(--wu-muted)}

%B% #wpfooter{background:var(--wu-ftbg);color:var(--wu-ftink);border-top:var(--wu-ftb);font-family:var(--wu-ui);font-size:12px;padding:12px 20px}
%B% #wpfooter a{color:var(--wu-ftlink);font-weight:600;text-underline-offset:3px}
%B% #footer-upgrade{display:none}
%B% .wu-edition{margin:12px 20px 8px;padding:8px 0;border-bottom:1px solid var(--wu-line);color:var(--wu-muted);font-family:var(--wu-ui);font-size:12px;line-height:1.6}
@media(max-width:782px){%B% .wrap h1{font-size:30px}}
CSS;
	}

	/* ---------- CSS：左側選單 ---------- */
	private static function menu_css() {
		return <<<'CSS'
%B% :is(#adminmenuback,#adminmenuwrap,#adminmenu){background:var(--wu-menu)!important;background-image:none!important}
%B% #adminmenu>li.menu-top{margin:0!important;border:0!important;background:transparent!important;border-radius:0!important;box-shadow:none!important}
%B% #adminmenu>li.menu-top>a.menu-top,%B% #adminmenu .wp-submenu a{background:transparent!important;background-image:none!important;color:var(--wu-menu-ink)!important;-webkit-text-fill-color:var(--wu-menu-ink)!important;border:0!important;border-radius:0!important;box-shadow:none!important;text-shadow:none!important;text-decoration:none!important;transform:none!important;font-family:var(--wu-ui);font-size:13px;font-weight:400}
%B% #adminmenu>li.menu-top>a.menu-top{text-transform:var(--wu-mtf);letter-spacing:var(--wu-mls)}
%B% #adminmenu .wp-submenu a{text-transform:none;letter-spacing:normal;padding-top:8px;padding-bottom:8px}
%B% :is(#adminmenu .wp-menu-name,#adminmenu .wp-menu-image){background:transparent!important;border-radius:0!important;box-shadow:none!important}

/* 懸浮：整列淡色，與子選單一致 */
%B% #adminmenu>li.menu-top:not(.current):not(.wp-has-current-submenu)>a.menu-top:is(:hover,:focus),
%B% #adminmenu>li.menu-top.opensub:not(.current)>a.menu-top,
%B% #adminmenu .wp-submenu a:is(:hover,:focus),
%B% #adminmenu .wp-submenu li.current>a{background:rgba(255,255,255,.085)!important;color:#fff!important;-webkit-text-fill-color:#fff!important}

/* 目前所在項目 */
%B% #adminmenu>li.current>a.menu-top,%B% #adminmenu>li.wp-has-current-submenu>a.menu-top{background:rgba(255,255,255,.12)!important;color:#fff!important;-webkit-text-fill-color:#fff!important;font-weight:700}
%B%.wu-menu-accent #adminmenu>li.current>a.menu-top,%B%.wu-menu-accent #adminmenu>li.wp-has-current-submenu>a.menu-top{background:var(--wu-cur-bg)!important;color:var(--wu-cur-ink)!important;-webkit-text-fill-color:var(--wu-cur-ink)!important}
%B%.wu-menu-accent #adminmenu>li.current .wp-menu-image::before,%B%.wu-menu-accent #adminmenu>li.wp-has-current-submenu .wp-menu-image::before{color:var(--wu-cur-ink)!important;-webkit-text-fill-color:var(--wu-cur-ink)!important}
%B%.wu-menu-accent #adminmenu>li.current .wp-menu-image.svg,%B%.wu-menu-accent #adminmenu>li.current .wp-menu-image img,%B%.wu-menu-accent #adminmenu>li.wp-has-current-submenu .wp-menu-image.svg,%B%.wu-menu-accent #adminmenu>li.wp-has-current-submenu .wp-menu-image img{filter:var(--wu-cur-filter)!important}

/* 不顯示左側直線與右側三角，保留圖示 */
%B% :is(#adminmenu>li.menu-top,#adminmenu>li.menu-top>a,#adminmenu .wp-submenu a)::before,
%B% :is(#adminmenu>li.menu-top,#adminmenu>li.menu-top>a,#adminmenu .wp-submenu a)::after{content:none!important;display:none!important}
%B% #adminmenu .wp-menu-image::before{color:#D5DED6!important;-webkit-text-fill-color:#D5DED6!important}
%B% :is(#adminmenu .wp-menu-image img,#adminmenu .wp-menu-image.svg){filter:brightness(0) invert(1)!important}

%B% :is(#adminmenu .wp-submenu,#adminmenu .wp-submenu-wrap){background:var(--wu-menu)!important;border:0!important;border-radius:0!important;padding:8px 0!important;box-shadow:none!important}
%B%:not(.folded) #adminmenu .wp-has-current-submenu>.wp-submenu{background:rgba(255,255,255,.03)!important}
%B% #adminmenu .wp-submenu .wp-submenu-head{color:#fff!important;background:transparent!important}
%B% :is(#adminmenu .update-plugins,#adminmenu .awaiting-mod){background:#D8E3D8!important;color:#203126!important;-webkit-text-fill-color:#203126!important;border-radius:0!important}
%B% #adminmenu a:focus{outline:none!important}
%B% #adminmenu a:focus-visible{outline:2px solid #E6EAE7!important;outline-offset:-3px!important}
CSS;
	}

	/* ---------- CSS：工具列 ---------- */
	private static function toolbar_css( $p = '' ) {
		$css = <<<'CSS'
__P__#wpadminbar{background:var(--wu-bar);border-bottom:var(--wu-barb);box-shadow:none;font-family:var(--wu-ui)}
__P__#wpadminbar :is(.ab-item,.ab-label,.ab-icon::before,.ab-item::before){color:var(--wu-bar-ink)!important;-webkit-text-fill-color:var(--wu-bar-ink)!important}
__P__#wpadminbar .ab-top-menu>li:is(:hover,.hover)>.ab-item{background:rgba(255,255,255,.1)!important}
__P__#wpadminbar .ab-sub-wrapper{background:var(--wu-bar);border-radius:0!important}
__P__#wpadminbar .wp-environment-type{background:#E4EEE4!important;color:#17251B!important;-webkit-text-fill-color:#17251B!important;border-radius:0!important}
__P__#wpadminbar .wp-environment-type *{color:#17251B!important;-webkit-text-fill-color:#17251B!important}
__P__#wpadminbar .quicklinks li#wp-admin-bar-woocommerce-site-visibility-badge>a.ab-item,
__P__#wpadminbar .quicklinks li#wp-admin-bar-woocommerce-site-visibility-badge:hover>a.ab-item,
__P__#wpadminbar .quicklinks li#wp-admin-bar-woocommerce-site-visibility-badge.hover>a.ab-item{background:#E4EEE4!important;background-image:none!important;color:#17251B!important;-webkit-text-fill-color:#17251B!important;border-radius:0!important;text-shadow:none!important;opacity:1!important}
__P__#wpadminbar .quicklinks li#wp-admin-bar-woocommerce-site-visibility-badge>a.ab-item *{background:transparent!important;color:#17251B!important;-webkit-text-fill-color:#17251B!important;border-radius:0!important;text-shadow:none!important;opacity:1!important;font-weight:600!important}
CSS;
		return str_replace( '__P__', $p, $css );
	}

	/* ---------- CSS：各風格的標誌性設計 ---------- */
	private static function theme_css( $key ) {

		$css = array();

		$css['newsprint'] = <<<'CSS'
%B% .wrap h1,%B% .wrap h1.wp-heading-inline{font-size:clamp(34px,4.4vw,60px);line-height:.98}
%B% .wu-edition{font-family:var(--wu-mono);letter-spacing:.15em;text-transform:uppercase;border-bottom-color:#111}
%B% .wu-edition::before{content:"VOL. 1  |  "}
%B% #wpfooter{font-family:var(--wu-mono);text-transform:uppercase;letter-spacing:.08em}
CSS;

		$css['botanical'] = <<<'CSS'
%B% .widefat :is(thead,tfoot) :is(th,td){font-family:var(--wu-heading);text-transform:none;letter-spacing:0;font-size:13px}
%B% .wu-edition{font-family:var(--wu-heading);font-style:italic;text-align:center}
%B% .wu-edition::before{content:"\2766  ";color:var(--wu-accent)}
%B% .wu-edition::after{content:"  \2766";color:var(--wu-accent)}
%B% #wpbody-content :is(a.button,button.button,input.button){transition:background-color .3s ease-out,color .3s ease-out}
CSS;

		$css['flat'] = <<<'CSS'
%B% .wrap h1::before{content:"";display:inline-block;width:14px;height:14px;background:var(--wu-accent);margin-right:12px;vertical-align:middle}
%B% #wpbody-content a.page-title-action{background:var(--wu-primary)!important;color:var(--wu-on)!important;-webkit-text-fill-color:var(--wu-on)!important}
%B% #wpbody-content a.page-title-action:hover{background:var(--wu-hover)!important}
%B% :is(.notice,div.updated,div.error,.update-nag){background:#EFF6FF;border:0;box-shadow:none}
%B% :is(.notice-success,div.updated){background:#ECFDF5}
%B% :is(.notice-error,div.error){background:#FEF2F2}
%B% .notice-warning{background:#FFFBEB}
%B% .widefat :is(td,th){border-bottom-width:2px}
%B% .postbox-header{border-bottom:2px solid var(--wu-bg)}
%B% .subsubsub a.current{background:var(--wu-ink);color:#fff!important;padding:2px 8px;border-bottom:0}
%B% .wu-edition{border-bottom:0;font-weight:600;text-transform:uppercase;letter-spacing:.12em;font-size:11px}
%B% .wu-edition::before{content:"";display:inline-block;width:10px;height:10px;background:var(--wu-accent);margin-right:10px}
CSS;

		$css['bauhaus'] = <<<'CSS'
@media(min-width:783px){body.wu-theme-bauhaus{--wu-bw:4px}}
%B% .wrap h1::after{content:"";display:inline-block;width:84px;height:24px;margin-left:16px;vertical-align:middle;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 84 24'%3E%3Ccircle cx='12' cy='12' r='11' fill='%23D02020' stroke='%23121212' stroke-width='2'/%3E%3Crect x='31' y='1' width='22' height='22' fill='%231040C0' stroke='%23121212' stroke-width='2'/%3E%3Cpath d='M72 1 83 23H61Z' fill='%23F0C020' stroke='%23121212' stroke-width='2'/%3E%3C/svg%3E") no-repeat center/contain}
%B% .postbox:nth-of-type(3n+1) .postbox-header{background:#F0C020}
%B% .postbox:nth-of-type(3n+2) .postbox-header{background:#1040C0}
%B% .postbox:nth-of-type(3n) .postbox-header{background:#D02020}
%B% :is(.postbox:nth-of-type(3n+2),.postbox:nth-of-type(3n)) :is(.hndle,.postbox-header h2,.handlediv,.toggle-indicator,.toggle-indicator::before){color:#fff}
%B% #wpbody-content a.page-title-action{background:#1040C0!important;border-color:#121212!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
%B% #wpbody-content a.page-title-action:hover{background:#0D3399!important}
%B% #wpbody-content :is(a.button,button.button,input.button):active{transform:translate(2px,2px);box-shadow:none!important}
%B% .subsubsub{text-transform:uppercase;font-weight:700;letter-spacing:.06em;font-size:12px}
%B% .wu-edition{background:#F0C020;border:2px solid #121212;padding:6px 12px;color:#121212;font-weight:700;text-transform:uppercase;letter-spacing:.12em}
%B% .wu-settings-header{background:#F0C020;border:4px solid #121212;padding:28px;box-shadow:8px 8px 0 #121212;grid-template-columns:minmax(0,1.7fr) minmax(140px,1fr);margin-bottom:36px}
%B% .wu-settings-header h1{font-weight:900;letter-spacing:-.035em;font-size:clamp(32px,4.5vw,60px)}
%B% .wu-geometry{background:#1040C0;min-height:140px}
%B% .wu-geometry span{width:42px;height:42px;border:2px solid #121212}
%B% .wu-geometry .wu-shape-square{background:#fff;transform:rotate(45deg)}
%B% .wu-settings .wu-theme-choice{border:2px solid #121212;box-shadow:4px 4px 0 #121212}
%B% .wu-settings .wu-theme-choice:nth-child(3n+1){border-top:8px solid #D02020}
%B% .wu-settings .wu-theme-choice:nth-child(3n+2){border-top:8px solid #1040C0}
%B% .wu-settings .wu-theme-choice:nth-child(3n){border-top:8px solid #F0C020}
%B% .wu-settings-section>h2{border-bottom:4px solid #121212;padding-bottom:12px}
@media(max-width:600px){%B% .wu-settings-header{grid-template-columns:1fr;border-width:2px;box-shadow:4px 4px 0 #121212;padding:20px}}
CSS;

		return $css[ $key ] ?? '';
	}

	/* ---------- 登入頁 ---------- */
	public static function login_assets() {
		if ( ! self::get( 'enable_login' ) || ! self::styled() ) return;
		self::fonts();
		$css = self::tokens( 'body.login' ) . <<<'CSS'
body.login{background-color:var(--wu-bg);background-image:var(--wu-bgimg);color:var(--wu-ink);font-family:var(--wu-font)}
body.login #login{width:min(360px,calc(100% - 40px));padding-top:clamp(36px,8vh,90px)}
body.login h1 a{background-image:none;width:auto;height:auto;text-indent:0;font-family:var(--wu-heading);font-size:36px;font-weight:var(--wu-hw);text-transform:var(--wu-htf);letter-spacing:var(--wu-hls);line-height:1.2;color:var(--wu-ink);margin-bottom:24px}
body.login form{background:var(--wu-surface);border:var(--wu-bw) solid var(--wu-line);border-radius:0;box-shadow:var(--wu-shadow);padding:28px}
body.login label{color:var(--wu-ink);font-family:var(--wu-ui)}
body.login form .input{background-color:var(--wu-inbg);color:var(--wu-ink);border:var(--wu-inb);border-bottom:var(--wu-inbb);border-radius:0;box-shadow:none;font-family:var(--wu-mono);font-size:18px;min-height:44px}
body.login form .input:focus{background-color:var(--wu-infocus);border-color:var(--wu-focus);box-shadow:none}
body.login .button-primary{background:var(--wu-primary)!important;border:var(--wu-btn-bw) solid var(--wu-btn-line)!important;color:var(--wu-on)!important;-webkit-text-fill-color:var(--wu-on)!important;border-radius:0;box-shadow:var(--wu-btn-shadow);text-shadow:none;min-height:44px;padding:0 22px;font-family:var(--wu-ui);font-weight:600;text-transform:var(--wu-uitf);letter-spacing:var(--wu-uils)}
body.login .button-primary:hover{background:var(--wu-hover)!important}
body.login .button.wp-hide-pw{color:var(--wu-ink);background:transparent}
body.login :is(#nav a,#backtoblog a,.privacy-policy-page-link a){color:var(--wu-link)}
body.login .language-switcher label{color:var(--wu-ink)}
body.login .language-switcher select{background:var(--wu-surface);color:var(--wu-ink);border-radius:0}
body.login :is(.message,.notice,#login_error){background:var(--wu-surface);color:var(--wu-ink);border-radius:0}
body.login :is(a,button,input,select):focus-visible{outline:2px solid var(--wu-focus);outline-offset:3px}
CSS;
		self::enqueue_css( 'wu-login-621', $css );
	}

	public static function login_url( $u )   { return self::get( 'enable_login' ) && self::styled() ? home_url( '/' ) : $u; }
	public static function login_title( $t ) { return self::get( 'enable_login' ) && self::styled() ? get_bloginfo( 'name' ) : $t; }

	/* ---------- 工具列節點 ---------- */
	public static function toolbar( $bar ) {
		if (get_option(self::OPTION, null) === null) return;
		if ( self::get( 'hide_wp_logo' ) ) $bar->remove_node( 'wp-logo' );
		if ( self::get( 'show_switcher' ) && current_user_can( 'manage_options' ) ) {
			$bar->add_node( array( 'id' => 'wu-style-settings', 'title' => 'Wu 後台風格', 'href' => admin_url( 'admin.php?page=' . self::SLUG . '&tab=appearance' ) ) );
		}
	}

	/* ---------- 頁尾與刊頭 ---------- */
	public static function footer( $orig ) {
		if (get_option(self::OPTION, null) === null || get_option('wu_remove_admin_footer_text') || get_option('wu_custom_admin_footer_text')) return $orig;
		if ( ! self::get( 'enable_admin' ) ) return $orig;
		$p = array();
		if ( self::get( 'edition' ) ) $p[] = 'Edition: ' . esc_html( self::get( 'edition' ) );
		if ( self::get( 'powered' ) ) $p[] = 'Powered by ' . esc_html( self::get( 'powered' ) );
		if ( self::get( 'link_text' ) ) {
			$t = esc_html( self::get( 'link_text' ) );
			$u = self::get( 'link_url' );
			if ( $u ) $t = '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer">' . $t . '</a>';
			$p[] = esc_html( self::get( 'link_label' ) ) . $t;
		}
		$p[] = esc_html( self::get( 'site_name' ) ?: get_bloginfo( 'name' ) );
		return implode( ' &nbsp;|&nbsp; ', $p );
	}

	public static function footer_version( $o ) { return get_option(self::OPTION, null) !== null && self::get( 'enable_admin' ) ? '' : $o; }

	public static function edition() {
		if (get_option(self::OPTION, null) === null || ! self::get( 'enable_admin' ) || ! self::get( 'show_edition' ) ) return;
		echo '<div class="wu-edition">' . esc_html( wp_date( 'Y年n月j日 l' ) . ' | ' . ( self::get( 'site_name' ) ?: get_bloginfo( 'name' ) ) ) . '</div>';
	}
}

if (!class_exists('WU_Admin_Style_621', false)) WUTM_Admin_Style::boot();

}
