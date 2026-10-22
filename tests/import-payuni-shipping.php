<?php
/** Build-only audit manifest from the supplied ZIP extraction. Never run in CI. */
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(1);
$source=rtrim($argv[1],'/\\');$vendor=dirname(__DIR__).'/modules/payuni-shipping/vendor';$manifest=[];
$reasons=[
    'src/PayuniShipping.php'=>'Preserve checkout/order/crypto APIs; scoped UI/assets, foreign-provider guards, frozen map goods type, instance package spec, missing-session/ctype guards, sanitized debug log.',
    'src/Api/ShippingRequest.php'=>'Atomic creation wrapper; preserve API payload builders, reject failed/mismatched queries before meta changes, fix WP_Error/PDF headers and Taipei dates without changing global timezone.',
    'src/Api/ShippingResponse.php'=>'Return from TCat handler to release wrapper lock; signature/order/replay guards are in adapter.',
    'src/Admin/OrderMetaBox.php'=>'Scope label button and prevent accidental order form submission.',
    'assets/js/scripts-admin.js'=>'Scope own admin action selectors and omit raw console payloads.',
    'assets/css/styles-public.css'=>'Scoped own checkout state, preserve other provider visibility.',
    'templates/cart/choose-cvs.php'=>'Unique WU map button ID; prevent clashes with other shipping providers.',
];
function ps_digest($file){$text=file_get_contents($file);if(pathinfo($file,PATHINFO_EXTENSION)!=='mo')$text=str_replace("\r\n","\n",$text);return hash('sha256',$text);}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS)) as $file){
    if(!$file->isFile())continue;$name=str_replace('\\','/',substr($file->getPathname(),strlen($source)+1));$original=ps_digest($file->getPathname());$bundled=ps_digest($vendor.'/'.$name);
    if($original!==$bundled&&!isset($reasons[$name]))throw new RuntimeException('Unreviewed source adaptation: '.$name);
    $manifest[$name]=['source_sha256'=>$original,'bundled_sha256'=>$bundled,'adaptation'=>$original===$bundled?'unchanged':$reasons[$name]];
}
ksort($manifest);file_put_contents(__DIR__.'/payuni-shipping-source-files.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
echo 'Audited supplied source files: '.count($manifest)."\n";
