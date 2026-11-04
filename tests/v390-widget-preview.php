<?php
namespace WUTM\ContactWidgets {class YSChatWidgets {static function get_settings(){return ['enabled'=>1,'show_mobile'=>1,'show_desktop'=>1,'display'=>'all','layout'=>$GLOBALS['argv'][1]??'toggle','back_to_top'=>($GLOBALS['argv'][2]??'yes')==='yes','mobile_outer'=>40,'mobile_inner'=>32,'size_outer'=>56,'size_inner'=>46,'apps'=>[['key'=>'facebook','value'=>'brand']]];}}}
namespace {
define('ABSPATH',__DIR__);function add_action(...$args){}function wp_is_mobile(){return false;}function __($value,...$args){return $value;}function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}function esc_attr($value){return esc_html($value);}function esc_url($value,...$args){return esc_html($value);}function esc_attr__($value,...$args){return esc_attr($value);}function esc_url_raw($value,...$args){return $value;}function wp_parse_url(...$args){return parse_url(...$args);}function wp_strip_all_tags($value){return strip_tags($value);}
require dirname(__DIR__).'/modules/contact-widgets/src/YSChatApps.php';require dirname(__DIR__).'/modules/contact-widgets/src/Frontend/YSChatFrontend.php';(new \WUTM\ContactWidgets\Frontend\YSChatFrontend())->render_widget();
}
