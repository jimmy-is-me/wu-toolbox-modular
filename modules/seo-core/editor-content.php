<?php
defined('ABSPATH') || exit;
function wutm_seo_keywords($value): string {
    if(!is_string($value))return '';$out=[];$seen=[];
    foreach(preg_split('/[,，、;；\r\n]+/u',$value)?:[] as $word){$word=sanitize_text_field(trim($word));$key=function_exists('mb_strtolower')?mb_strtolower($word,'UTF-8'):strtolower($word);if($word===''||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$word;if(count($out)>=30)break;}
    return implode(', ',$out);
}
/** Read saved builder text only; never render shortcodes or request a frontend URL. */
function wutm_seo_elementor_text($id): string {
    $data=get_post_meta($id,'_elementor_data',true);if(is_string($data))$data=json_decode($data,true);if(!is_array($data))return '';
    $out='';$count=0;
    $walk=static function($nodes,$depth=0)use(&$walk,&$out,&$count){if($depth>30||!is_array($nodes))return;foreach($nodes as $node){if(++$count>1500||strlen($out)>200000)return;if(!is_array($node))continue;$settings=$node['settings']??[];if(!is_array($settings))$settings=[];$type=$node['widgetType']??'';
        if($type==='heading'&&is_string($settings['title']??null)){$tag=in_array($settings['header_size']??'', ['h1','h2','h3','h4','h5','h6'],true)?$settings['header_size']:'h2';$out.='<'.$tag.'>'.esc_html($settings['title']).'</'.$tag.'>';}
        elseif($type==='text-editor'&&is_string($settings['editor']??null))$out.=wp_kses_post($settings['editor']);
        elseif(in_array($type,['button','icon-box','image-box'],true)){foreach(['text','title_text','description_text'] as $key)if(is_string($settings[$key]??null))$out.='<p>'.esc_html($settings[$key]).'</p>';}
        if($type==='image'&&!empty($settings['image']['id']))$out.='<img alt="'.esc_attr(get_post_meta((int)$settings['image']['id'],'_wp_attachment_image_alt',true)).'">';
        $walk($node['elements']??[],$depth+1);
    }};$walk($data);return $out;
}
