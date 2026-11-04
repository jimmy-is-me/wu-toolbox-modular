<?php
define('ABSPATH',__DIR__);$shortcode_tags=[];
function add_action(...$args){}function add_filter(...$args){}function add_shortcode($name,$fn){$GLOBALS['shortcode_tags'][$name]=$fn;}
function get_option($key,$default=false){return $key==='admin_email'?'fixture@example.test':$default;}
function current_user_can(...$args){return true;}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}function esc_attr($value){return esc_html($value);}function esc_textarea($value){return esc_html($value);}function esc_url($value){return esc_html($value);}
function selected($left,$right,$echo=true){$html=$left===$right?' selected':'';if($echo)echo $html;return $html;}function checked($left,$right=1){if($left==$right)echo ' checked';}
function admin_url($path){return '/wp-admin/'.$path;}function settings_fields($group){echo '<input type="hidden" name="option_page" value="'.$group.'">';}function submit_button(...$args){echo '<button>儲存設定</button>';}
function wutm_admin_page_open($title,$description){echo '<div class="wutm-ui-root"><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p>';}function wutm_admin_page_close(){echo '</div>';}
require dirname(__DIR__).'/modules/contact-form/source.php';wutm_cf_settings_page();
