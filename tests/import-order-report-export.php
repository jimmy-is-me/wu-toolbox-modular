<?php
/** Build-only mechanical import of the user-supplied V3 snippet. */
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(1);
$source=str_replace("\r\n","\n",file_get_contents($argv[1]));
$target=dirname(__DIR__).'/modules/order-report-export/source.php';
$imported="<?php\n".str_replace('WCTRE_Order_Export_V3','WUTM_Order_Report_Export_V3',$source);
if(!is_dir(dirname($target)))mkdir(dirname($target),0777,true);
file_put_contents($target,$imported);
file_put_contents(__DIR__.'/order-report-export-source.json',json_encode(['source_sha256'=>hash('sha256',$source),'imported_sha256'=>hash('sha256',$imported)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "Imported complete V3 snippet; action names and per-user settings keys retained.\n";
