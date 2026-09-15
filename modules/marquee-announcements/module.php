<?php
/**
 * WU Toolbox Modular：跑馬燈／公告輪播。
 */

defined( 'ABSPATH' ) || exit;

final class WUTM_Marquee_Announcements {
	private const OPTION_KEY = 'wutm_marquee_options';
	private const PAGE_SLUG  = 'wu-marquee-announcements';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'maybe_render_automatic' ) );
		add_shortcode( 'wutm_marquee', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'wp_marquee', array( __CLASS__, 'shortcode' ) );
	}

	private static function defaults(): array {
		return array(
			'messages'       => array( '歡迎來到我們的網站！', '請留意網站最新公告與優惠消息。' ),
			'font_size'      => 16,
			'font_color'     => '#ffffff',
			'bg_color'       => '#1d2327',
			'font_weight'    => 'normal',
			'display_loc'    => 'none',
			'specific_pages' => '',
			'transition'     => 'fade',
			'auto_seconds'   => 3,
			'show_buttons'   => 'no',
			'pause_hover'    => 'yes',
		);
	}

	private static function options(): array {
		$options = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'wu-toolbox-modular',
			'跑馬燈／公告輪播',
			'跑馬燈／公告輪播',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'wutm_marquee_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_options' ) )
		);
	}

	public static function sanitize_options( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$messages = isset( $input['messages'] ) ? (array) $input['messages'] : array();
		$messages = array_values( array_filter( array_map( static function ( $message ) {
			return wp_kses_post( trim( wp_unslash( (string) $message ) ) );
		}, $messages ), static function ( $message ) {
			return '' !== trim( wp_strip_all_tags( $message ) );
		} ) );

		$display_locations = array( 'all', 'home', 'specific', 'none' );
		$transitions       = array( 'fade', 'left', 'right', 'none' );
		$font_weights      = array( 'normal', 'bold' );

		return array(
			'messages'       => $messages,
			'font_size'      => max( 10, min( 48, absint( $input['font_size'] ?? $defaults['font_size'] ) ) ),
			'font_color'     => sanitize_hex_color( $input['font_color'] ?? '' ) ?: $defaults['font_color'],
			'bg_color'       => sanitize_hex_color( $input['bg_color'] ?? '' ) ?: $defaults['bg_color'],
			'font_weight'    => in_array( $input['font_weight'] ?? '', $font_weights, true ) ? $input['font_weight'] : $defaults['font_weight'],
			'display_loc'    => in_array( $input['display_loc'] ?? '', $display_locations, true ) ? $input['display_loc'] : $defaults['display_loc'],
			'specific_pages' => implode( ',', array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $input['specific_pages'] ?? '' ) ) ) ) ),
			'transition'     => in_array( $input['transition'] ?? '', $transitions, true ) ? $input['transition'] : $defaults['transition'],
			'auto_seconds'   => max( 0, min( 60, absint( $input['auto_seconds'] ?? $defaults['auto_seconds'] ) ) ),
			'show_buttons'   => ! empty( $input['show_buttons'] ) ? 'yes' : 'no',
			'pause_hover'    => ! empty( $input['pause_hover'] ) ? 'yes' : 'no',
		);
	}

	public static function enqueue_admin_assets(): void {
		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_editor();
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options  = self::options();
		$messages = $options['messages'] ?: array( '' );
		?>
		<div class="wrap wutm-module-wrap wutm-marquee-admin">
			<h1>跑馬燈／公告輪播</h1>
			<p class="wutm-module-subtitle">新增多則公告、拖曳調整順序，並選擇全站自動顯示或使用短代碼 <code>[wutm_marquee]</code> 插入指定位置。</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wutm_marquee_group' ); ?>
				<div class="card" style="max-width:1100px;padding:22px;margin-top:20px;">
					<h2>公告內容</h2>
					<p>拖曳左側把手即可排序；公告支援粗體、斜體及安全連結。</p>
					<ul id="wutm-marquee-list">
					<?php foreach ( $messages as $index => $message ) : ?>
						<li class="wutm-marquee-row"><span class="dashicons dashicons-menu wutm-marquee-handle" title="拖曳排序"></span><div class="wutm-marquee-editor">
						<?php wp_editor( $message, 'wutm_marquee_message_' . absint( $index ), array( 'textarea_name' => self::OPTION_KEY . '[messages][]', 'textarea_rows' => 3, 'media_buttons' => false, 'teeny' => true, 'quicktags' => false, 'tinymce' => array( 'toolbar1' => 'bold,italic,link,unlink,undo,redo', 'toolbar2' => '' ) ) ); ?>
						</div><button type="button" class="button wutm-marquee-remove">刪除</button></li>
					<?php endforeach; ?>
					</ul>
					<button type="button" class="button button-secondary" id="wutm-marquee-add">新增公告</button>
				</div>

				<div class="card" style="max-width:1100px;padding:22px;">
					<h2>外觀與顯示</h2>
					<table class="form-table"><tbody>
					<tr><th>顏色</th><td><label>背景 <input type="color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bg_color]" value="<?php echo esc_attr( $options['bg_color'] ); ?>"></label>　<label>文字 <input type="color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[font_color]" value="<?php echo esc_attr( $options['font_color'] ); ?>"></label></td></tr>
					<tr><th>文字</th><td><input type="number" min="10" max="48" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[font_size]" value="<?php echo esc_attr( $options['font_size'] ); ?>" class="small-text"> px　<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[font_weight]"><option value="normal" <?php selected( $options['font_weight'], 'normal' ); ?>>正常</option><option value="bold" <?php selected( $options['font_weight'], 'bold' ); ?>>粗體</option></select></td></tr>
					<tr><th>自動顯示位置</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[display_loc]"><option value="none" <?php selected( $options['display_loc'], 'none' ); ?>>不自動顯示（只用短代碼）</option><option value="all" <?php selected( $options['display_loc'], 'all' ); ?>>全站頁首</option><option value="home" <?php selected( $options['display_loc'], 'home' ); ?>>僅網站首頁</option><option value="specific" <?php selected( $options['display_loc'], 'specific' ); ?>>指定頁面或文章</option></select><p><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[specific_pages]" value="<?php echo esc_attr( $options['specific_pages'] ); ?>" placeholder="例如：12,34,56" class="regular-text"></p><p class="description">指定頁面請輸入內容 ID，以逗號分隔。自動顯示位置需要佈景主題支援標準 wp_body_open 掛鉤。</p></td></tr>
					<tr><th>輪播效果</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[transition]"><option value="fade" <?php selected( $options['transition'], 'fade' ); ?>>淡入淡出</option><option value="left" <?php selected( $options['transition'], 'left' ); ?>>向左滑動</option><option value="right" <?php selected( $options['transition'], 'right' ); ?>>向右滑動</option><option value="none" <?php selected( $options['transition'], 'none' ); ?>>直接切換</option></select>　每 <input type="number" min="0" max="60" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_seconds]" value="<?php echo esc_attr( $options['auto_seconds'] ); ?>" class="small-text"> 秒切換<p class="description">設為 0 即停止自動輪播。</p></td></tr>
					<tr><th>控制方式</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_buttons]" value="yes" <?php checked( $options['show_buttons'], 'yes' ); ?>> 顯示上一則／下一則按鈕</label><br><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pause_hover]" value="yes" <?php checked( $options['pause_hover'], 'yes' ); ?>> 滑鼠移入時暫停</label></td></tr>
					</tbody></table>
				</div>
				<?php submit_button( '儲存設定' ); ?>
			</form>
		</div>
		<style>.wutm-marquee-row{display:flex;align-items:flex-start;gap:14px;background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;border-radius:5px;padding:14px;margin-bottom:14px}.wutm-marquee-handle{cursor:move;color:#646970;margin-top:7px}.wutm-marquee-editor{flex:1;min-width:0}.wutm-marquee-remove{margin-top:2px}.ui-sortable-placeholder{height:90px;border:1px dashed #8c8f94;background:#f0f0f1;visibility:visible!important}@media(max-width:782px){.wutm-marquee-row{flex-wrap:wrap}.wutm-marquee-editor{flex-basis:calc(100% - 45px)}.wutm-marquee-remove{margin-left:38px}}</style>
		<script>
		jQuery(function($){var count=<?php echo absint( count( $messages ) ); ?>;$('#wutm-marquee-list').sortable({handle:'.wutm-marquee-handle',axis:'y'});$('#wutm-marquee-add').on('click',function(){var id='wutm_marquee_message_new_'+count++;var row=$('<li class="wutm-marquee-row"><span class="dashicons dashicons-menu wutm-marquee-handle" title="拖曳排序"></span><div class="wutm-marquee-editor"><textarea rows="3"></textarea></div><button type="button" class="button wutm-marquee-remove">刪除</button></li>');var textarea=row.find('textarea').attr({id:id,name:'<?php echo esc_js( self::OPTION_KEY ); ?>[messages][]'});$('#wutm-marquee-list').append(row);if(window.wp&&wp.editor){wp.editor.initialize(id,{tinymce:{toolbar1:'bold,italic,link,unlink,undo,redo',toolbar2:''},quicktags:false,mediaButtons:false});}else{textarea.css('width','100%');}});$('#wutm-marquee-list').on('click','.wutm-marquee-remove',function(){if(!window.confirm('確定刪除這則公告嗎？'))return;var row=$(this).closest('li'),id=row.find('textarea').attr('id');if(window.wp&&wp.editor&&id)wp.editor.remove(id);row.remove();});});
		</script>
		<?php
	}

	public static function shortcode(): string {
		return self::render_marquee();
	}

	public static function maybe_render_automatic(): void {
		$options = self::options();
		$show    = 'all' === $options['display_loc'] || ( 'home' === $options['display_loc'] && ( is_front_page() || is_home() ) );
		if ( 'specific' === $options['display_loc'] ) {
			$ids  = array_filter( array_map( 'absint', explode( ',', $options['specific_pages'] ) ) );
			$show = $ids && ( is_page( $ids ) || is_single( $ids ) );
		}
		if ( $show ) {
			echo self::render_marquee(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaped attributes and wp_kses_post content.
		}
	}

	private static function render_marquee(): string {
		$options  = self::options();
		$messages = array_values( array_filter( (array) $options['messages'], static function ( $message ) { return '' !== trim( wp_strip_all_tags( (string) $message ) ); } ) );
		if ( ! $messages ) {
			return '';
		}
		$id         = wp_unique_id( 'wutm-marquee-' );
		$font_size  = absint( $options['font_size'] );
		$line_height = max( 24, (int) ceil( $font_size * 1.6 ) );
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="wutm-marquee" data-transition="<?php echo esc_attr( $options['transition'] ); ?>" data-auto="<?php echo esc_attr( $options['auto_seconds'] ); ?>" data-pause="<?php echo esc_attr( $options['pause_hover'] ); ?>" style="--wutm-mq-bg:<?php echo esc_attr( $options['bg_color'] ); ?>;--wutm-mq-color:<?php echo esc_attr( $options['font_color'] ); ?>;--wutm-mq-size:<?php echo esc_attr( $font_size ); ?>px;--wutm-mq-weight:<?php echo esc_attr( $options['font_weight'] ); ?>;--wutm-mq-height:<?php echo esc_attr( $line_height ); ?>px">
			<?php if ( 'yes' === $options['show_buttons'] && count( $messages ) > 1 ) : ?><button type="button" class="wutm-mq-button is-prev" aria-label="上一則公告">&#10094;</button><?php endif; ?>
			<div class="wutm-mq-track" aria-live="polite"><?php foreach ( $messages as $index => $message ) : ?><div class="wutm-mq-item<?php echo 0 === $index ? ' is-active' : ''; ?>"><?php echo wp_kses_post( $message ); ?></div><?php endforeach; ?></div>
			<?php if ( 'yes' === $options['show_buttons'] && count( $messages ) > 1 ) : ?><button type="button" class="wutm-mq-button is-next" aria-label="下一則公告">&#10095;</button><?php endif; ?>
		</div>
		<style>#<?php echo esc_attr( $id ); ?>{position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden;box-sizing:border-box;width:100%;min-height:calc(var(--wutm-mq-height) + 20px);padding:10px 44px;background:var(--wutm-mq-bg);color:var(--wutm-mq-color);font-size:var(--wutm-mq-size);font-weight:var(--wutm-mq-weight);text-align:center}#<?php echo esc_attr( $id ); ?> .wutm-mq-track{position:relative;width:100%;height:var(--wutm-mq-height);overflow:hidden}#<?php echo esc_attr( $id ); ?> .wutm-mq-item{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transform:translateX(0);transition:opacity .45s ease,transform .45s ease}#<?php echo esc_attr( $id ); ?> .wutm-mq-item.is-active{opacity:1;visibility:visible}#<?php echo esc_attr( $id ); ?> .wutm-mq-item p{margin:0}#<?php echo esc_attr( $id ); ?> .wutm-mq-item a{color:inherit;text-decoration:underline}#<?php echo esc_attr( $id ); ?> .wutm-mq-button{position:absolute;top:50%;z-index:2;transform:translateY(-50%);padding:8px 14px;border:0;background:transparent;color:inherit;font-size:18px;cursor:pointer;opacity:.75}#<?php echo esc_attr( $id ); ?> .wutm-mq-button:hover{opacity:1}#<?php echo esc_attr( $id ); ?> .is-prev{left:0}#<?php echo esc_attr( $id ); ?> .is-next{right:0}@media(prefers-reduced-motion:reduce){#<?php echo esc_attr( $id ); ?> .wutm-mq-item{transition:none}}</style>
		<script>(function(){var wrap=document.getElementById(<?php echo wp_json_encode( $id ); ?>);if(!wrap)return;var items=Array.prototype.slice.call(wrap.querySelectorAll('.wutm-mq-item'));if(items.length<2)return;var index=0,timer=null,delay=parseInt(wrap.dataset.auto,10)*1000,mode=wrap.dataset.transition;function show(next,direction){if(next===index)return;var old=items[index],item=items[next],sign=direction==='prev'?-1:1;if(mode==='left'||mode==='right'){sign=mode==='right'?-sign:sign;item.style.transition='none';item.style.transform='translateX('+(sign*100)+'%)';item.style.visibility='visible';item.style.opacity='1';requestAnimationFrame(function(){requestAnimationFrame(function(){item.style.transition='';item.style.transform='translateX(0)';old.style.transform='translateX('+(-sign*100)+'%)';old.style.opacity='0';});});setTimeout(function(){old.classList.remove('is-active');old.style.transform='';old.style.visibility='';item.classList.add('is-active');},470);}else{if(mode==='none'){old.style.transition='none';item.style.transition='none';}old.classList.remove('is-active');item.classList.add('is-active');}index=next;}function next(){show((index+1)%items.length,'next');}function prev(){show((index-1+items.length)%items.length,'prev');}wrap.querySelector('.is-next')?.addEventListener('click',next);wrap.querySelector('.is-prev')?.addEventListener('click',prev);function start(){if(delay>0&&!timer)timer=setInterval(next,delay);}function stop(){if(timer){clearInterval(timer);timer=null;}}start();if(wrap.dataset.pause==='yes'){wrap.addEventListener('mouseenter',stop);wrap.addEventListener('mouseleave',start);}}());</script>
		<?php
		return (string) ob_get_clean();
	}
}

WUTM_Marquee_Announcements::init();
