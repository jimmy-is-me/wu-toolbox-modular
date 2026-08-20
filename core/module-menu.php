<?php
defined('ABSPATH') || exit;

/**
 * Shared visual system for the WU Toolbox dashboard and every module route.
 * Modules own their functionality; this file owns only the common surface.
 */
add_action('admin_head', function (): void {
    $screen = get_current_screen();
    if (!$screen) return;
    $id = (string) $screen->id;
    if ($id !== 'toplevel_page_wu-toolbox-modular' && strpos($id, 'wu-toolbox-modular_page_wu-') !== 0) return;
    ?>
    <style>
      :root{--wutm-ink:#07162c;--wutm-panel:#0b2442;--wutm-panel-2:#10365d;--wutm-cyan:#63ddff;--wutm-green:#35df92;--wutm-muted:#91b4d0}
      #wpcontent,#wpfooter{background:radial-gradient(circle at 88% 0%,#123d67 0,#07162c 44%,#061022 100%)!important}
      #wpbody-content{min-height:calc(100vh - 32px);padding-bottom:32px}
      #adminmenu .wp-has-current-submenu>a.wp-has-current-submenu,#adminmenu .current a.menu-top{background:linear-gradient(90deg,#0c8b78,#1263aa)!important;box-shadow:inset 3px 0 var(--wutm-cyan),0 0 18px #1eb7bd44}
      #adminmenu .wp-submenu .current a{color:var(--wutm-cyan)!important}
      .wutm-wrap,.wrap{max-width:1240px}
      .wutm-wrap .wutm-header,.wrap>h1:first-child{background:linear-gradient(135deg,#061326 0%,#0b2a4c 54%,#145b92 100%)!important;color:#f3fbff!important;border:1px solid #3a8fc577!important;border-radius:16px;padding:22px 26px;box-shadow:0 0 0 1px #63ddff24,0 14px 38px #020b18bb,0 0 34px #16b9c433;position:relative;overflow:hidden}
      .wutm-wrap .wutm-header:after,.wrap>h1:first-child:after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 0%,#63ddff12 45%,transparent 70%);transform:translateX(-100%);animation:wutm-scan 7s ease-in-out infinite;pointer-events:none}
      .wutm-wrap .wutm-header h1,.wrap>h1:first-child{color:#f3fbff!important;text-shadow:0 0 16px #63ddff55}
      .wutm-wrap .wutm-header{margin-top:20px}
      .wrap>h1:first-child{margin:20px 0 24px;font-size:21px}
      .wutm-grid{gap:16px}
      .wutm-card,.form-table,.card{border:1px solid #3a8fc566!important;background:linear-gradient(145deg,#0d2a4bfa,#081a32f5)!important;backdrop-filter:blur(10px);border-radius:14px!important;box-shadow:0 10px 25px #020b1855,0 0 0 1px #63ddff10!important;color:#eaf8ff!important}
      .wutm-card.on{border-color:#35df92!important;box-shadow:0 0 0 1px #35df92,0 0 24px #35df9244,0 14px 32px #020b1866!important;animation:wutm-breathe 3.2s ease-in-out infinite}
      .wutm-card:hover{transform:translateY(-3px);transition:.2s ease;box-shadow:0 0 0 1px #63ddff,0 0 26px #63ddff33!important}
      .wutm-card h3,.wutm-card .wutm-card-name{color:#f3fbff!important;text-shadow:0 0 8px #63ddff22}
      .wutm-card p,.wutm-card .wutm-card-desc{color:#91b4d0!important}
      .wutm-settings{color:var(--wutm-cyan)!important;text-shadow:0 0 8px #63ddff55}
      .wutm-switch span{background:#244566!important;box-shadow:inset 0 0 0 1px #63ddff55}
      .wutm-switch input:checked+span{background:linear-gradient(90deg,#12a97b,#35df92)!important;box-shadow:0 0 14px #35df9277}
      .form-table{border-spacing:0;margin:20px 0;padding:8px 22px}
      .form-table th{color:#eaf8ff!important;font-weight:700}
      .form-table td,.form-table .description{color:#91b4d0!important}
      .button,.button-secondary,.button-primary{border-radius:8px!important}
      .button-primary{background:linear-gradient(90deg,#0e9d78,#1d78c8)!important;border-color:#35df92!important;box-shadow:0 0 16px #35df9244!important;color:#fff!important}
      .button-primary:hover{filter:brightness(1.15);box-shadow:0 0 24px #63ddff66!important}
      input[type=text],input[type=number],input[type=url],textarea,select{border-color:#3a8fc5!important;border-radius:7px!important;background:#06172c!important;color:#eaf8ff!important}
      input:focus,textarea:focus,select:focus{border-color:var(--wutm-cyan)!important;box-shadow:0 0 0 1px var(--wutm-cyan),0 0 14px #63ddff44!important}
      .notice{border-radius:10px!important;border-left-width:4px!important;box-shadow:0 3px 16px #020b1855}
      .wutm-wrap section>h2{color:var(--wutm-cyan)!important;font-size:12px;text-shadow:0 0 10px #63ddff44}
      @keyframes wutm-breathe{0%,100%{filter:brightness(1);transform:translateY(0)}50%{filter:brightness(1.12);transform:translateY(-1px)}}
      @keyframes wutm-scan{0%,35%{transform:translateX(-100%)}65%,100%{transform:translateX(100%)}}
      @media (prefers-reduced-motion:reduce){.wutm-card.on,.wutm-wrap .wutm-header:after,.wrap>h1:first-child:after{animation:none}}
    </style>
    <?php
});
