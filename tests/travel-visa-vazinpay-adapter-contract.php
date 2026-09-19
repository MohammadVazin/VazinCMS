<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/TravelVisaCommerceContract.php';
require dirname(__DIR__).'/src/TravelVisaCommerceService.php';
require dirname(__DIR__).'/src/VazinPayGatewayClient.php';
require dirname(__DIR__).'/src/TravelVisaVazinPayAdapter.php';
use VazinCMS\{TravelVisaCommerceService,VazinPayGatewayClient,TravelVisaVazinPayAdapter};

$expect=static fn(bool $ok,string $message)=>$ok?:throw new RuntimeException($message);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/10.30.0-sqlite.sql'));
$state=['status'=>'pending','calls'=>[]];
$transport=static function(string $method,string $path,?array $body,array $headers)use(&$state):array{$state['calls'][]=compact('method','path','body','headers');if($method==='POST')return ['status'=>201,'body'=>['invoice_id'=>'inv_travel_123456','external_order_id'=>$body['external_order_id'],'status'=>'pending']];return ['status'=>200,'body'=>['invoice_id'=>'inv_travel_123456','external_order_id'=>'travelvisa:TVORDER0001','amount'=>'99.00','currency'=>'USD','status'=>$state['status']]];};
$orders=new TravelVisaCommerceService($pdo);$orders->create('russiafa','TVORDER0001','evisa',null,'99.00','USD');
$adapter=new TravelVisaVazinPayAdapter($orders,new VazinPayGatewayClient('https://pay.vazin.online','contract-key','contract-webhook',$transport));
$bound=$adapter->createInvoice('russiafa','TVORDER0001','https://russiafa.ru/services/russia-visa/evisa/?order=TVORDER0001','RussiaFa eVisa service','invoice-bind-0001');
$expect($bound['invoice_id']==='inv_travel_123456'&&$state['calls'][0]['body']['external_order_id']==='travelvisa:TVORDER0001','Invoice was not bound to the correct external order.');
try{$adapter->createInvoice('other-tenant','TVORDER0001','https://example.test/return','x','invoice-bind-0002');throw new RuntimeException('Cross-tenant order access was accepted.');}catch(RuntimeException $e){$expect($e->getMessage()==='order_not_found','Unexpected tenant-boundary failure.');}
$state['status']='paid';$paid=$adapter->reconcileReadback('russiafa','TVORDER0001','pay-readback-0001');$expect($paid['payment_status']==='paid'&&$paid['lifecycle_status']==='paid','Verified paid readback was not applied.');
$raw='{"external_order_id":"travelvisa:TVORDER0001"}';$timestamp='1700000000';$headers=['X-VazinPay-Event'=>'invoice.paid','X-VazinPay-Delivery'=>'webhook-delivery-0001','X-VazinPay-Timestamp'=>$timestamp,'X-VazinPay-Signature'=>'v1='.hash_hmac('sha256',$timestamp.'.'.$raw,'contract-webhook')];
$again=$adapter->reconcileWebhook('russiafa',$headers,$raw,1700000001);$expect($again['payment_status']==='paid','Signed webhook did not reconcile through VazinPay readback.');
echo "travel-visa-vazinpay-adapter-contract: OK\n";
