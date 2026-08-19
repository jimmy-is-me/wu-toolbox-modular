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
      #wpcontent,#wpfooter{background:#E2F8EA}
      #wpbody-content{min-height:calc(100vh - 32px);padding-bottom:32px}
      .wutm-wrap,.wrap{max-width:1240px}
      .wutm-wrap .wutm-header,.wrap>h1:first-child{background:linear-gradient(135deg,#0a2b24,#0d5445 58%,#16a56b);color:#fff!important;border:1px solid rgba(255,255,255,.18);border-radius:16px;padding:22px 26px;box-shadow:0 12px 28px rgba(18,91,63,.18)}
      .wutm-wrap .wutm-header h1,.wrap>h1:first-child{color:#fff!important;text-shadow:0 1px 0 rgba(0,0,0,.2)}
      .wutm-wrap .wutm-header{margin-top:20px}
      .wrap>h1:first-child{margin:20px 0 24px;font-size:21px}
      .wutm-grid{gap:16px}
      .wutm-card,.form-table,.card{border:1px solid rgba(13,84,69,.16)!important;background:rgba(255,255,255,.82)!important;backdrop-filter:blur(8px);border-radius:14px!important;box-shadow:0 8px 20px rgba(18,91,63,.08)!important}
      .wutm-card.on{border-color:#14a96d!important;box-shadow:0 0 0 1px #14a96d,0 10px 24px rgba(20,169,109,.16)!important}
      .wutm-card:hover{transform:translateY(-2px);transition:.18s ease}
      .form-table{border-spacing:0;margin:20px 0;padding:8px 22px}
      .form-table th{color:#073d31;font-weight:700}
      .form-table td{color:#34544b}
      .button,.button-secondary,.button-primary{border-radius:8px!important}
      .button-primary{background:#0f9f65!important;border-color:#0f9f65!important;box-shadow:0 4px 10px rgba(15,159,101,.2)!important}
      .button-primary:hover{background:#087b4d!important;border-color:#087b4d!important}
      input[type=text],input[type=number],input[type=url],textarea,select{border-color:#a7d9bf!important;border-radius:7px!important;background:#fbfffc!important}
      input:focus,textarea:focus,select:focus{border-color:#14a96d!important;box-shadow:0 0 0 1px #14a96d!important}
      .notice{border-radius:10px!important;border-left-width:4px!important;box-shadow:0 3px 10px rgba(18,91,63,.07)}
      .wutm-wrap section>h2{color:#0d5445!important;font-size:12px}
    </style>
    <?php
});
