<?php
/**
 * Module: wc-confetti
 * WooCommerce cart, checkout and thank-you confetti animation.
 */
defined( 'ABSPATH' ) || exit;

final class WUTM_WC_Confetti {
    private const OPTION = 'wutm_wc_confetti_settings';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'wp_footer', [ $this, 'render_frontend' ], 99 );
    }

    private function defaults(): array {
        return [
            'enabled' => 1, 'show_cart' => 1, 'show_checkout' => 1, 'show_thankyou' => 1, 'play_once' => 1,
            'particles_per_side' => 200, 'second_wave_enabled' => 1, 'second_wave_count' => 80, 'delay_ms' => 120,
            'speed_min' => 620, 'speed_max' => 1560, 'gravity_min' => 1000, 'gravity_max' => 1210,
            'duration_min' => 1550, 'duration_max' => 2050, 'drag' => 0.95,
            'colors' => '#FF7A7A, #FFB45C, #FFD86B, #74D99F, #6EB6FF, #9B86FF, #FF8BC2, #6FD6E8', 'z_index' => 999,
        ];
    }

    private function settings(): array { return wp_parse_args( (array) get_option( self::OPTION, [] ), $this->defaults() ); }

    public function register_settings(): void {
        register_setting( 'wutm_wc_confetti_group', self::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ], 'default' => $this->defaults() ] );
    }

    public function sanitize( $input ): array {
        $input = is_array( $input ) ? $input : [];
        $out = [];
        foreach ( [ 'enabled', 'show_cart', 'show_checkout', 'show_thankyou', 'play_once', 'second_wave_enabled' ] as $key ) $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
        $out['particles_per_side'] = max( 1, min( 300, absint( $input['particles_per_side'] ?? 200 ) ) );
        $out['second_wave_count'] = max( 0, min( 200, absint( $input['second_wave_count'] ?? 80 ) ) );
        $out['delay_ms'] = max( 0, min( 5000, absint( $input['delay_ms'] ?? 120 ) ) );
        foreach ( [ 'speed_min' => [ 100, 3000 ], 'speed_max' => [ 100, 3500 ], 'gravity_min' => [ 50, 3000 ], 'gravity_max' => [ 50, 3500 ] ] as $key => $range ) $out[ $key ] = max( $range[0], min( $range[1], (float) ( $input[ $key ] ?? 0 ) ) );
        foreach ( [ 'duration_min' => [ 300, 8000 ], 'duration_max' => [ 300, 10000 ] ] as $key => $range ) $out[ $key ] = max( $range[0], min( $range[1], absint( $input[ $key ] ?? 0 ) ) );
        $out['drag'] = max( 0.1, min( 3, (float) ( $input['drag'] ?? 0.95 ) ) );
        $out['z_index'] = max( 1, min( 9999999, absint( $input['z_index'] ?? 999 ) ) );
        foreach ( [ [ 'speed_min', 'speed_max' ], [ 'gravity_min', 'gravity_max' ], [ 'duration_min', 'duration_max' ] ] as $pair ) if ( $out[ $pair[1] ] < $out[ $pair[0] ] ) [ $out[ $pair[0] ], $out[ $pair[1] ] ] = [ $out[ $pair[1] ], $out[ $pair[0] ] ];
        $colors = array_filter( array_map( 'sanitize_hex_color', array_map( 'trim', explode( ',', (string) ( $input['colors'] ?? '' ) ) ) ) );
        $out['colors'] = implode( ', ', $colors ?: explode( ', ', $this->defaults()['colors'] ) );
        return $out;
    }

    public function add_menu(): void { add_submenu_page( 'wu-toolbox-modular', 'WC 紙片動畫', 'WC 紙片動畫', 'manage_woocommerce', 'wu-wc-confetti', [ $this, 'render_admin' ] ); }

    public function render_admin(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $s = $this->settings(); ?>
        <div class="wrap wutm-module-wrap wutm-confetti-admin"><h1>WC 紙片動畫</h1><p class="wutm-module-subtitle">在購物車、結帳與訂單完成頁以左下、右下交叉噴射方式播放紙片動畫；支援降低動態效果偏好設定。</p>
        <p><button type="button" class="button button-secondary" id="wutm-confetti-preview">▶ 即時預覽動畫</button></p>
        <form method="post" action="options.php"><?php settings_fields( 'wutm_wc_confetti_group' ); ?>
        <section class="wutm-confetti-card"><h2>基本設定</h2><div class="wutm-confetti-grid"><label class="full"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> 啟用紙片動畫</label><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_cart]" value="1" <?php checked( $s['show_cart'] ); ?>> 購物車頁</label><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_checkout]" value="1" <?php checked( $s['show_checkout'] ); ?>> 結帳頁</label><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_thankyou]" value="1" <?php checked( $s['show_thankyou'] ); ?>> 訂單完成／感謝頁</label><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[play_once]" value="1" <?php checked( $s['play_once'] ); ?>> 同一分頁只播放一次</label></div></section>
        <section class="wutm-confetti-card"><h2>紙片與節奏</h2><div class="wutm-confetti-grid"><label>主波每側紙片<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[particles_per_side]" min="1" max="300" value="<?php echo esc_attr( $s['particles_per_side'] ); ?>"></label><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[second_wave_enabled]" value="1" <?php checked( $s['second_wave_enabled'] ); ?>> 啟用第二波補噴</label><label>第二波每側數量<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[second_wave_count]" min="0" max="200" value="<?php echo esc_attr( $s['second_wave_count'] ); ?>"></label><label>開始延遲（ms）<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[delay_ms]" min="0" max="5000" value="<?php echo esc_attr( $s['delay_ms'] ); ?>"></label></div></section>
        <section class="wutm-confetti-card"><h2>動畫物理</h2><div class="wutm-confetti-grid"><label>速度最小值<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[speed_min]" value="<?php echo esc_attr( $s['speed_min'] ); ?>"></label><label>速度最大值<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[speed_max]" value="<?php echo esc_attr( $s['speed_max'] ); ?>"></label><label>重力最小值<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[gravity_min]" value="<?php echo esc_attr( $s['gravity_min'] ); ?>"></label><label>重力最大值<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[gravity_max]" value="<?php echo esc_attr( $s['gravity_max'] ); ?>"></label><label>動畫最短時間（ms）<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[duration_min]" value="<?php echo esc_attr( $s['duration_min'] ); ?>"></label><label>動畫最長時間（ms）<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[duration_max]" value="<?php echo esc_attr( $s['duration_max'] ); ?>"></label><label>水平阻力<input type="number" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[drag]" value="<?php echo esc_attr( $s['drag'] ); ?>"></label><label>顯示層級 z-index<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>[z_index]" value="<?php echo esc_attr( $s['z_index'] ); ?>"></label><label class="full">紙片顏色（HEX 色碼以逗號分隔）<input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[colors]" value="<?php echo esc_attr( $s['colors'] ); ?>"></label></div></section><?php submit_button( '儲存設定' ); ?></form></div>
        <style>.wutm-confetti-admin{max-width:1060px}.wutm-confetti-card{margin:18px 0;padding:22px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.wutm-confetti-card h2{margin:0 0 18px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;font-size:17px}.wutm-confetti-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px 24px}.wutm-confetti-grid label{font-weight:600}.wutm-confetti-grid input[type=number],.wutm-confetti-grid input[type=text]{display:block;width:100%;max-width:440px;min-height:38px;margin-top:7px}.wutm-confetti-grid .full{grid-column:1/-1}@media(max-width:720px){.wutm-confetti-grid{grid-template-columns:1fr}}</style>
        <script>document.getElementById('wutm-confetti-preview').addEventListener('click',function(){if(window.wutmWcConfetti)window.wutmWcConfetti(<?php echo wp_json_encode( $this->config( $s ) ); ?>);});</script>
        <?php $this->render_script( $this->config( $s ), true );
    }

    private function context(): string {
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return 'thankyou';
        if ( function_exists( 'is_cart' ) && is_cart() ) return 'cart';
        if ( function_exists( 'is_checkout' ) && is_checkout() ) return 'checkout';
        return '';
    }

    private function config( array $s ): array {
        $colors = array_values( array_filter( array_map( 'sanitize_hex_color', array_map( 'trim', explode( ',', (string) $s['colors'] ) ) ) ) );
        return [ 'particles' => (int) $s['particles_per_side'], 'secondEnabled' => ! empty( $s['second_wave_enabled'] ), 'secondCount' => (int) $s['second_wave_count'], 'delay' => (int) $s['delay_ms'], 'speedMin' => (float) $s['speed_min'], 'speedMax' => (float) $s['speed_max'], 'gravityMin' => (float) $s['gravity_min'], 'gravityMax' => (float) $s['gravity_max'], 'durationMin' => (int) $s['duration_min'], 'durationMax' => (int) $s['duration_max'], 'drag' => (float) $s['drag'], 'colors' => $colors, 'zIndex' => (int) $s['z_index'] ];
    }

    public function render_frontend(): void {
        if ( is_admin() || empty( $this->settings()['enabled'] ) ) return;
        $context = $this->context(); if ( $context === '' ) return;
        $s = $this->settings(); if ( empty( $s[ 'show_' . ( $context === 'thankyou' ? 'thankyou' : $context ) ] ) ) return;
        $config = $this->config( $s );
        $config['playOnce'] = ! empty( $s['play_once'] );
        $config['key'] = 'wutm_confetti_' . $context . '_' . md5( (string) ( $_SERVER['REQUEST_URI'] ?? $context ) );
        $this->render_script( $config, false );
    }

    private function render_script( array $config, bool $admin ): void { ?>
        <style>.wutm-confetti-overlay{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:999}.wutm-confetti-piece{position:absolute;left:0;top:0;will-change:transform,opacity;box-shadow:inset 0 1px 0 rgba(255,255,255,.2)}</style>
        <script>(function(){window.wutmWcConfetti=function(cfg){if(!cfg||!cfg.colors||!cfg.colors.length)return;if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;try{if(cfg.playOnce&&sessionStorage.getItem(cfg.key))return;if(cfg.playOnce)sessionStorage.setItem(cfg.key,'1')}catch(e){}const old=document.querySelector('.wutm-confetti-overlay');if(old)old.remove();const layer=document.createElement('div');layer.className='wutm-confetti-overlay';layer.style.zIndex=cfg.zIndex||999;document.body.appendChild(layer);const rnd=(a,b)=>Math.random()*(b-a)+a,parts=[];function add(count,second){for(let i=0;i<count;i++)['left','right'].forEach(side=>{const el=document.createElement('i'),circle=Math.random()<.1,w=circle?rnd(4,6):rnd(3,6),h=circle?w:rnd(7,12),angle=(side==='left'?rnd(-66,-43):rnd(-137,-114))*Math.PI/180,speed=rnd(cfg.speedMin,cfg.speedMax)*(second?rnd(.82,.92):1);el.className='wutm-confetti-piece';el.style.cssText+=';width:'+w+'px;height:'+h+'px;background:'+cfg.colors[Math.floor(Math.random()*cfg.colors.length)]+';border-radius:'+(circle?'50%':'1px');layer.appendChild(el);parts.push({el:el,x:side==='left'?rnd(12,28):innerWidth-rnd(12,28),y:innerHeight-rnd(14,26),vx:Math.cos(angle)*speed,vy:Math.sin(angle)*speed,g:rnd(cfg.gravityMin,cfg.gravityMax),life:rnd(cfg.durationMin,cfg.durationMax)/1000,wait:rnd(0,.08),r:rnd(0,360),rs:rnd(-560,560),dead:false})})}add(cfg.particles,false);let start,second=false;function frame(now){start=start||now;const time=(now-start)/1000;if(cfg.secondEnabled&&!second&&time>=.28){second=true;add(cfg.secondCount,true)}let alive=0;parts.forEach(p=>{if(p.dead)return;const t=time-p.wait;if(t<0){alive++;return}if(t>p.life){p.dead=true;p.el.remove();return}alive++;const x=p.x+(p.vx/cfg.drag)*(1-Math.exp(-cfg.drag*t)),y=p.y+p.vy*t+.5*p.g*t*t,progress=t/p.life,opacity=progress<.03?progress/.03:(progress>.84?(1-progress)/.16:1);p.el.style.opacity=Math.max(0,opacity);p.el.style.transform='translate3d('+x+'px,'+y+'px,0) rotate('+(p.r+p.rs*t)+'deg)'}) ;if(alive)requestAnimationFrame(frame);else layer.remove()}requestAnimationFrame(frame)};<?php if ( ! $admin ) : ?>const cfg=<?php echo wp_json_encode( $config ); ?>;const run=()=>setTimeout(()=>window.wutmWcConfetti(cfg),cfg.delay||0);document.readyState==='complete'?run():addEventListener('load',run,{once:true});<?php endif; ?>})();</script>
    <?php }
}
new WUTM_WC_Confetti();
