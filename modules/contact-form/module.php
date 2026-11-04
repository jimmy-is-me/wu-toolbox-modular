<?php
defined('ABSPATH') || exit;
if(function_exists('khmf_fields')||defined('KHMF_OPT')){add_action('admin_notices',static function(){if(current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>聯絡表單：請先停用原 KH Mini Form 程式，再使用 Toolbox 模組。舊資料不會刪除。</p></div>';});return;}
require_once __DIR__.'/source.php';
