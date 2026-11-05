<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
require_once ABSPATH.'wp-admin/includes/screen.php';require_once ABSPATH.'wp-admin/includes/template.php';set_current_screen('dashboard');
require_once dirname(__DIR__).'/modules/admin-bar-cleaner/module.php';
function v393_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
update_option('wutm_dashboard_widget_registry',['conditional'=>'條件式小工具']);delete_option('wutm_disabled_dashboard_widgets');update_option('wu_disable_all_dashboard_widgets',1);
foreach(['fixture-a','fixture-b','wu_email_tracker_dashboard'] as $id)add_meta_box($id,'標題 '.$id,static function(){},'dashboard','normal');
WUTM_Dashboard_Widgets::apply();v393_check(isset(WUTM_Dashboard_Widgets::registry()['fixture-a'],WUTM_Dashboard_Widgets::registry()['conditional']),'Detect current widgets and preserve historical registry');
v393_check($GLOBALS['wp_meta_boxes']['dashboard']['normal']['default']['fixture-a']===false&&is_array($GLOBALS['wp_meta_boxes']['dashboard']['normal']['default']['wu_email_tracker_dashboard']),'Legacy disable-all preserves original exception');
$_POST=['wutm_dashboard_widget_list'=>1,'wutm_disabled_dashboard_widgets'=>['fixture-a','unknown',['bad']]];WUTM_Dashboard_Widgets::save();v393_check(get_option('wutm_disabled_dashboard_widgets')===['fixture-a']&&!get_option('wu_disable_all_dashboard_widgets'),'Explicit whitelist selection replaces legacy all flag');
foreach(['fixture-a','fixture-b','wu_email_tracker_dashboard'] as $id)add_meta_box($id,'標題 '.$id,static function(){},'dashboard','normal','high');
WUTM_Dashboard_Widgets::apply();v393_check($GLOBALS['wp_meta_boxes']['dashboard']['normal']['high']['fixture-a']===false&&is_array($GLOBALS['wp_meta_boxes']['dashboard']['normal']['high']['fixture-b']),'Only selected widget removed');
ob_start();WUTM_Dashboard_Widgets::render();$html=ob_get_clean();v393_check(str_contains($html,'wutm_disabled_dashboard_widgets[]')&&str_contains($html,'標題 fixture-b'),'Actual widget list renders');
$_POST=['wutm_dashboard_widget_list'=>1];WUTM_Dashboard_Widgets::save();v393_check(get_option('wutm_disabled_dashboard_widgets')===[],'Unchecking all restores widgets on next request');$_POST=[];
echo "v3.9.3 native dashboard passed: discovery, historic widgets, legacy migration, whitelist save, selected removal and restore.\n";
