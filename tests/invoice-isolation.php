<?php
/** CLI only, mocked transports only. Never issues or voids a real invoice. */
if(PHP_SAPI!=='cli')exit;
$order=$argv[1]??'before';$testProvider=$argv[2]??'ezpay';
$foreign=getenv('WUTM_FOREIGN_AUTOLOAD');
if(!$foreign||!is_file($foreign))throw new RuntimeException('Foreign Composer autoloader required');
$base=dirname(__DIR__).'/';
$sentinel=static function($error){throw new RuntimeException('Unexpected uncaught error in fixture',0,$error);};
set_exception_handler($sentinel);
if($order==='before'){require $foreign;require __DIR__.'/fixtures/invoice-foreign-symbols.php';}
$argv=[__FILE__,'enabled',$testProvider];
require __DIR__.'/invoice-modules.php';
if($order==='after'){require $foreign;require __DIR__.'/fixtures/invoice-foreign-symbols.php';}
$previous=set_exception_handler($sentinel);restore_exception_handler();
check($previous===$sentinel,'Invoice constructor must not hijack global exception handler');
check(GuzzleHttp\ClientInterface::MAJOR_VERSION===7,'Foreign Guzzle 7 retained');
check(ODS\Metabox::FOREIGN_MARKER==='foreign'&&InvoicePorter\EzpayInvoice::FOREIGN_MARKER==='foreign','Foreign metabox/invoice classes untouched');
check(EcpayInvoice::FOREIGN_MARKER==='foreign'&&EcpayInvoiceMethod::INVOICE==='foreign','Foreign ECPay SDK untouched');
if($testProvider==='ezpay'){
 check(!class_exists('WUTMInvoiceDeps\GuzzleHttp\Client'),'EZPAY no longer loads a private Guzzle');
 function wp_safe_remote_post($url,$args){
  check($url==='https://cinv.ezpay.com.tw/Api/invoice_search','Only the fixed sandbox host and operation are used');
  check($args['redirection']===0&&$args['timeout']===25&&$args['limit_response_size']===1048576,'Transport bounds applied');
  check($args['body']['MerchantID_']==='fixture'&&!empty($args['body']['PostData_']),'Invoice remains a form request with encrypted data');
  return ['code'=>200,'body'=>'{"Status":"SUCCESS","Message":"fixture","Result":"{}"}'];
 }
 function wp_remote_retrieve_response_code($response){return $response['code'];}
 function wp_remote_retrieve_body($response){return $response['body'];}
 function is_wp_error($value){return $value instanceof WP_Error;}
 $account=['merchantID'=>'fixture','hashKey'=>str_repeat('k',32),'hashIV'=>str_repeat('i',16)];
 $invoice=new WUTMInvoiceDeps\InvoicePorter\EzpayInvoice($account,false);$api=new WUTMInvoiceDeps\InvoicePorter\EzpayApi($invoice);
 $data=['MerchantOrderNo'=>'fixture-123','TotalAmt'=>'100','BuyerName'=>'測試'];
 check($api->decryptPostData($api->encryptPostData($data))===$data,'Invoice encryption roundtrip preserves data');
 check($api->send('invoice_search',$data,false)->status==='SUCCESS','WordPress transport parses invoice results');
}else{
 check(WUTMInvoiceSDK_EcpayInvoiceMethod::INVOICE==='INVOICE','Private SDK wire method unchanged');
 check(is_subclass_of('WUECPAYINVOICE\\APIs\\ECPay_Woo_EcpayInvoice','WUTMInvoiceSDK_EcpayInvoice'),'Shell uses private SDK');
 $r=new ReflectionMethod('WUTMInvoiceSDK_ECPay_Invoice_Send','process_send');if(PHP_VERSION_ID<80100)$r->setAccessible(true);
 // Factory selection and signature algorithm execute without any HTTP transport.
 $request=$r->invoke(null,['MerchantID'=>'fixture','TimeStamp'=>1700000000,'RelateNumber'=>'fixture-order'],'key','iv','INVOICE_SEARCH','https://invoice.example.test/');
 check($request['MerchantID']==='fixture'&&$request['RelateNumber']==='fixture-order'&&strlen($request['CheckMacValue'])===32,'Private dynamic SDK factory preserves signed query payload');
 $method=new ReflectionMethod('WUTMInvoiceSDK_ECPay_Invoice_CheckMacValue','generate');
 $sign=$method->invoke(null,['MerchantID'=>'fixture','InvoiceNumber'=>'AB12345678'],'key','iv');
 check(is_string($sign)&&strlen($sign)===32,'Private SDK checksum works');
 check(class_exists('WUTMInvoiceSDK_ECPay_INVOICE_VOID'),'Private dynamic factory target exists');
}
$foreignMock=new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200,[],'foreign')]);
$foreignClient=new GuzzleHttp\Client(['handler'=>GuzzleHttp\HandlerStack::create($foreignMock)]);
check((string)$foreignClient->request('GET','https://foreign.example.test/')->getBody()==='foreign','Foreign Guzzle continues working after invoice boot');
restore_exception_handler();
echo "Invoice dependency isolation passed: $order / $testProvider\n";
