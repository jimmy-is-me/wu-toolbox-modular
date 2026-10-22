<?php
/** CLI only, mocked transports only. Never issues or voids a real invoice. */
if(PHP_SAPI!=='cli')exit;
$order=$argv[1]??'before';$testProvider=$argv[2]??'ezpay';
$foreign=getenv('WUTM_FOREIGN_AUTOLOAD');
if(!$foreign||!is_file($foreign))throw new RuntimeException('Foreign Composer autoloader required');
$base=dirname(__DIR__).'/';
if($order==='reproduce'){
 require $foreign;
 // Reconstruct the pre-fix Guzzle function namespace in a disposable process.
 // This MUST fail with the reported compile-time redeclaration, not be caught.
 eval('?>'.str_replace('WUTMInvoiceDeps\\','',file_get_contents($base.'modules/ezpay-invoice/vendor/guzzlehttp/guzzle/src/functions.php')));
 throw new RuntimeException('Expected original collision did not occur');
}
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
 check(strpos(WUTMInvoiceDeps\GuzzleHttp\ClientInterface::VERSION,'6.')===0,'Private Guzzle version remains separate');
 $history=[];$mock=new WUTMInvoiceDeps\GuzzleHttp\Handler\MockHandler([new WUTMInvoiceDeps\GuzzleHttp\Psr7\Response(200,[],'private')]);
 $stack=WUTMInvoiceDeps\GuzzleHttp\HandlerStack::create($mock);$stack->push(WUTMInvoiceDeps\GuzzleHttp\Middleware::history($history));
 $client=new WUTMInvoiceDeps\GuzzleHttp\Client(['handler'=>$stack]);
 $response=$client->request('POST','https://invoice.example.test/Api/invoice_issue',['form_params'=>['MerchantID_'=>'fixture','PostData_'=>'encrypted-fixture']]);
 check((string)$response->getBody()==='private','Private HTTP handler works');
 check($history[0]['request'] instanceof WUTMInvoiceDeps\Psr\Http\Message\RequestInterface,'Private PSR interface consistent');
 check(!($history[0]['request'] instanceof Psr\Http\Message\RequestInterface),'Private PSR never mixes with foreign PSR 2');
 parse_str((string)$history[0]['request']->getBody(),$body);check($body===['MerchantID_'=>'fixture','PostData_'=>'encrypted-fixture'],'Form payload fields preserved');
 check(WUTMInvoiceDeps\GuzzleHttp\Promise\promise_for('ok')->wait()==='ok','Private promises work');
 $uri=WUTMInvoiceDeps\GuzzleHttp\Utils::idnUriConvert(new WUTMInvoiceDeps\GuzzleHttp\Psr7\Uri('https://例子.測試/'),IDNA_DEFAULT);
 check(strpos($uri->getHost(),'xn--')===0,'Private IDN/normalization works without foreign Symfony');
 $account=['merchantID'=>'fixture','hashKey'=>str_repeat('k',32),'hashIV'=>str_repeat('i',16)];
 $invoice=new WUTMInvoiceDeps\InvoicePorter\EzpayInvoice($account,false);$api=new WUTMInvoiceDeps\InvoicePorter\EzpayApi($invoice);
 $data=['MerchantOrderNo'=>'fixture-123','TotalAmt'=>'100','BuyerName'=>'測試'];
 check($api->decryptPostData($api->encryptPostData($data))===$data,'Invoice encryption roundtrip preserves data');
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
