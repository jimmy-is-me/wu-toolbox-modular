<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
$options=($argv[1]??'')==='active'?['wutm_toolbox_primary_admin_id'=>42,'wutm_hide_toolbox_other_admins'=>1]:['wutm_hide_toolbox_other_admins'=>1,'wu_hide_wumetax_toolkit_admin_id'=>1,'wutm_admin_menu_editor_owner_ids'=>[1]];
function add_action(...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function current_user_can(...$args){return true;}
function get_current_user_id(){return 42;}
function get_userdata($id){return (object)['ID'=>$id,'display_name'=>'網站管理員'];}
function user_can(...$args){return true;}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function esc_url($value){return esc_html($value);}
function admin_url($path){return 'https://fixture.test/wp-admin/'.$path;}
function checked($value){if($value)echo 'checked';}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
require dirname(__DIR__).'/core/module-access.php';
wutm_render_toolbox_visibility();
