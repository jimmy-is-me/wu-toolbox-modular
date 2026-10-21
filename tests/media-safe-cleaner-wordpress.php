<?php
/** Destructive integration fixture: restricted to the disposable CI database. */
if (!defined('WP_CLI') || !WP_CLI || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture' || $GLOBALS['wpdb']->prefix !== 'wme_wp_') {
    throw new RuntimeException('Disposable CI WordPress required.');
}
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/image.php';
set_current_screen('dashboard');
require dirname(__DIR__).'/modules/media-safe-cleaner/module.php';
function mc_assert($v,$m){if(!$v)throw new RuntimeException($m);}
function mc_private($m,...$args){$r=new ReflectionMethod('WUTM_Media_Safe_Cleaner',$m);if(PHP_VERSION_ID<80100)$r->setAccessible(true);return $r->invoke(null,...$args);}
$uploads=wp_upload_dir();$ids=[];$content_id=0;$old_icon=get_option('site_icon');
try {
 foreach(['clean','icon','content','builder','gallery','shared','parent','unsafe'] as $key){
  $file=$uploads['path'].'/cleaner-fixture-'.wp_generate_password(8,false,false).'.png';
  $im=imagecreatetruecolor(600,400);imagefill($im,0,0,imagecolorallocate($im,40,90,130));imagepng($im,$file);imagedestroy($im);
  $id=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>'Cleaner '.$key,'post_status'=>'inherit'],$file,0,true);
  mc_assert(!is_wp_error($id),'Attachment insert');$ids[$key]=$id;
  wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file));
 }
 update_option('site_icon',$ids['icon']);
 $content_id=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Cleaner fixture','post_content'=>'<img class="wp-image-'.$ids['content'].'" src="'.wp_get_attachment_url($ids['content']).'">']);
 update_post_meta($content_id,'_elementor_data',wp_slash(wp_json_encode(['url'=>wp_get_attachment_url($ids['builder'])])));
 update_post_meta($content_id,'_product_image_gallery',(string)$ids['gallery']);
 update_post_meta($ids['shared'],'_wp_attached_file',get_post_meta($ids['clean'],'_wp_attached_file',true));
 // Shared-file guard must protect both records, then restore their separate fixture paths.
 $reasons=mc_private('batch_reference_reasons',array_values($ids));
 mc_assert($reasons[$ids['clean']]!==''&&$reasons[$ids['shared']]!=='','Shared physical file guarded');
 foreach(['icon','content','builder','gallery'] as $key)mc_assert($reasons[$ids[$key]]!=='','Real reference protected: '.$key);
 // Restore shared record using its own generated metadata path.
 $shared_meta=wp_get_attachment_metadata($ids['shared']);update_post_meta($ids['shared'],'_wp_attached_file',$shared_meta['file']);
 $reasons=mc_private('batch_reference_reasons',[$ids['clean']]);mc_assert($reasons[$ids['clean']]==='','Unreferenced fixture eligible');
 wp_update_post(['ID'=>$ids['parent'],'post_parent'=>$content_id]);
 $f=['from'=>'','before'=>'','keyword'=>'','type'=>'all','protect_parent'=>true];
 mc_assert(!mc_private('matches_filters',get_post($ids['parent']),$f),'Parent filter rechecked');
 $unsafe=wp_get_attachment_metadata($ids['unsafe']);$unsafe['sizes']['bad']=['file'=>'../outside.png'];wp_update_attachment_metadata($ids['unsafe'],$unsafe);
 mc_assert(!mc_private('safe_attachment_paths',$ids['unsafe']),'Unsafe registered thumbnail blocked');
 unset($unsafe['sizes']['bad']);wp_update_attachment_metadata($ids['unsafe'],$unsafe);
 $clean=$ids['clean'];$file=get_attached_file($clean);$meta=wp_get_attachment_metadata($clean);
 mc_assert(mc_private('safe_attachment_paths',$clean),'Native metadata paths eligible');
 mc_assert(!empty($meta['sizes']),'Real thumbnails exist');
 mc_assert((bool)wp_delete_attachment($clean,true),'Native permanent deletion');
 mc_assert(!get_post($clean)&&!file_exists($file),'Record and original removed');
 foreach($meta['sizes'] as $size)mc_assert(!file_exists(dirname($file).'/'.$size['file']),'Registered thumbnail removed');
 echo "Real WordPress cleaner references, metadata paths and original/thumbnail deletion passed\n";
} finally {
 update_option('site_icon',$old_icon);
 if($content_id)wp_delete_post($content_id,true);
 foreach($ids as $id)if(get_post($id))wp_delete_attachment($id,true);
}
