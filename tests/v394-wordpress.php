<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
function v394_check($yes,$label){if(!$yes)throw new RuntimeException($label);}
require_once ABSPATH.'wp-admin/includes/screen.php';require_once ABSPATH.'wp-admin/includes/template.php';require_once ABSPATH.'wp-admin/includes/dashboard.php';set_current_screen('dashboard');
require_once dirname(__DIR__).'/modules/dashboard-status/module.php';require_once dirname(__DIR__).'/modules/admin-bar-cleaner/dashboard-widgets.php';
add_filter('pre_http_request',static fn()=>new WP_Error('fixture','No external transports'));
update_option('wu_dashboard_enabled',1);update_option('wutm_disabled_dashboard_widgets',[]);
add_action('wp_dashboard_setup',static function(){global $wp_meta_boxes;foreach((array)($wp_meta_boxes['dashboard']??[]) as $context=>$priorities)foreach((array)$priorities as $boxes)foreach(array_keys((array)$boxes) as $id)remove_meta_box($id,'dashboard',$context);},999999);
do_action('wp_dashboard_setup');v394_check(is_callable($GLOBALS['wp_meta_boxes']['dashboard']['normal']['high']['wu_unified_dashboard']['callback']??null),'Wu status widget survives earlier dashboard cleanup');
update_option('wutm_disabled_dashboard_widgets',['wu_unified_dashboard']);WUTM_Dashboard_Widgets::apply();v394_check($GLOBALS['wp_meta_boxes']['dashboard']['normal']['high']['wu_unified_dashboard']===false,'Explicit widget disable remains respected');
update_option('wu_dashboard_enabled',0);do_action('wp_dashboard_setup');v394_check($GLOBALS['wp_meta_boxes']['dashboard']['normal']['high']['wu_unified_dashboard']===false,'Internal dashboard off switch remains respected');
v394_check(post_type_exists('wu_entry')&&!post_type_exists('khmf_entry'),'Canonical Wu contact entry type only');
v394_check(shortcode_exists('wu_contact_form')&&!shortcode_exists('kh_mini_form_x7'),'Canonical Wu shortcode only');
$entry=wp_insert_post(['post_type'=>'wu_entry','post_status'=>'publish','post_title'=>'fixture']);update_post_meta($entry,'_khmf_data',[['label'=>'姓名','value'=>'fixture']]);v394_check(get_post_type($entry)==='wu_entry','Native entry storage');wp_delete_post($entry,true);
foreach(wutm_modules() as $key=>$module){$svg=wutm_module_icon($key,$module);v394_check(str_contains($svg,'<svg ')&&!str_contains($svg,'<text')&&str_contains($svg,'aria-hidden="true"'),'Local SVG for '.$key);}
v394_check(str_starts_with(wutm_toolbox_menu_icon(),'data:image/svg+xml;base64,'),'Dedicated Toolbox menu artwork');
echo "v3.9.4 native modules passed: late dashboard registration, explicit hide/off controls, Wu form identifiers and all module SVG artwork.\n";
