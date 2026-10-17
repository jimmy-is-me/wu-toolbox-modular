<?php
// Only the disposable CI WordPress instance may execute this integration test.
if (!defined('WP_CLI') || !WP_CLI || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture' || $GLOBALS['wpdb']->prefix !== 'wme_wp_') {
    throw new RuntimeException('Disposable CI WordPress required.');
}
define('WUTM_VERSION','integration-test');
require dirname(__DIR__).'/modules/media-encoder/module.php';
require_once dirname(__DIR__).'/modules/media-encoder/admin.php';
require_once ABSPATH.'wp-admin/includes/image.php';
add_filter('wp_image_editors', static function(){return ['WP_Image_Editor_GD'];});
function wme_assert($ok,$label){if(!$ok)throw new RuntimeException($label);}
$uploads=wp_upload_dir();wme_assert(empty($uploads['error']),'Upload directory available');
function wme_fixture($name,$width=700){
    $uploads=wp_upload_dir();$file=$uploads['path'].'/'.$name;
    $image=imagecreatetruecolor($width,450);imagefill($image,0,0,imagecolorallocate($image,30,100,150));imagepng($image,$file);unset($image);
    $id=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>$name,'post_status'=>'inherit'],$file,0,true);
    wme_assert(!is_wp_error($id),'Attachment inserted');
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file));
    return $id;
}
$id=wme_fixture('batch-original.png');$source=get_attached_file($id);$old=wp_get_attachment_metadata($id);
$result=wutm_media_convert_attachment($id,false);
wme_assert(!is_wp_error($result),'Real WordPress batch conversion succeeds');
$new=get_attached_file($id);$meta=wp_get_attachment_metadata($id);
wme_assert(file_exists($source)&&wp_get_image_mime($new)==='image/webp'&&get_post_mime_type($id)==='image/webp','File and post MIME updated, original preserved');
wme_assert(_wp_relative_upload_path($new)===$meta['file'],'Attachment path and metadata agree');
foreach($old['sizes'] as $size)wme_assert(file_exists(dirname($source).'/'.$size['file']),'Batch retains old thumbnails');
wme_assert(!empty($meta['sizes']),'Real WebP thumbnails generated');
foreach($meta['sizes'] as $size)wme_assert(wp_get_image_mime(dirname($new).'/'.$size['file'])==='image/webp','New thumbnail is real WebP');
wme_assert(is_wp_error(wutm_media_convert_attachment($id)),'Already-WebP is refused');
$failure=wme_fixture('metadata-failure.png');$source=get_attached_file($failure);$old=wp_get_attachment_metadata($failure);
$fail_filter=static function(){return [];};add_filter('wp_generate_attachment_metadata',$fail_filter,999);
$result=wutm_media_convert_attachment($failure,true);remove_filter('wp_generate_attachment_metadata',$fail_filter,999);
wme_assert(is_wp_error($result)&&file_exists($source)&&get_attached_file($failure)===$source&&get_post_mime_type($failure)==='image/png'&&wp_get_attachment_metadata($failure)===$old,'Actual WordPress metadata failure rolls back before deletion');
// Start with a large original without pre-scaling, then let conversion generate -scaled.
add_filter('big_image_size_threshold','__return_false',99);
$replace=wme_fixture('large-original.png',2800);remove_filter('big_image_size_threshold','__return_false',99);
$source=get_attached_file($replace);$old=wp_get_attachment_metadata($replace);
$result=wutm_media_convert_attachment($replace,true);
wme_assert(!is_wp_error($result),'Large confirmed replacement succeeds');
$new=get_attached_file($replace);$meta=wp_get_attachment_metadata($replace);
wme_assert(!file_exists($source)&&file_exists($new)&&strpos(basename($new),'-scaled.webp')!==false,'Scaled output selected after confirmed replacement');
wme_assert($meta['file']===_wp_relative_upload_path($new),'Real scaled path remains consistent');
foreach($old['sizes'] as $size)wme_assert(!file_exists(dirname($source).'/'.$size['file']),'Confirmed replacement removes old thumbnails after success');
wme_assert(wp_get_image_mime($new)==='image/webp'&&!empty($meta['sizes']),'Scaled WebP and thumbnail metadata remain valid');
WP_CLI::success('Real WordPress + GD: new thumbnails, attachment paths, original retention, metadata rollback and scaled replacement passed.');
