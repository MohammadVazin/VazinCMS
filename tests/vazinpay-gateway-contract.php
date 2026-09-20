<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/VazinPayGatewayClient.php';
use VazinCMS\VazinPayGatewayClient;
$expect=static fn(bool $ok,string $message)=>$ok?:throw new RuntimeException($message);
$calls=[];$transport=static function(string $method,string $path,?array $body,array $headers)use(&$calls):array{$calls[]=compact('method','path','body','headers');return ['status'=>$path==='/v1/invoices' ? 201 : 202,'body'=>['invoice_id'=>'inv_123456','status'=>$path==='/v1/invoices'?'pending':'pending_provider']];};
$pay=new VazinPayGatewayClient('https://api.pay.vazin.online','live-key','webhook-secret',$transport);
$invoice=$pay->createInvoice('russiafa:RFE12345678901234567890','99','USD','RussiaFa eVisa','https://russiafa.ru/payment-return');
$expect($invoice['invoice_id']==='inv_123456'&&$calls[0]['body']['amount']==='99.00'&&$calls[0]['body']['currency']==='USD','Invoice contract changed.');
$refund=$pay->refund('inv_123456','Customer cancelled before processing','refund-key-123');
$expect($refund['status']==='pending_provider'&&$calls[1]['headers']['Idempotency-Key']==='refund-key-123','Refund idempotency contract changed.');
$raw='{"invoice_id":"inv_123456"}';$ts='1700000000';$headers=['X-VazinPay-Event'=>'invoice.paid','X-VazinPay-Delivery'=>'delivery-1','X-VazinPay-Timestamp'=>$ts,'X-VazinPay-Signature'=>'v1='.hash_hmac('sha256',$ts.'.'.$raw,'webhook-secret')];
$event=$pay->verifyWebhook($headers,$raw,1700000010);$expect($event['event']==='invoice.paid','Webhook signature contract changed.');
try{$pay->verifyWebhook($headers,'{}',1700000010);throw new RuntimeException('Invalid webhook accepted.');}catch(RuntimeException){}
try{(new VazinPayGatewayClient('https://user@api.pay.vazin.online','live-key','webhook-secret',$transport))->invoice('inv_123456');throw new RuntimeException('Credential-bearing gateway URL accepted.');}catch(RuntimeException){}
try{(new VazinPayGatewayClient('https://pay.vazin.online','live-key','webhook-secret',$transport))->invoice('inv_123456');throw new RuntimeException('Legacy non-gateway host accepted.');}catch(RuntimeException){}
try{$pay->createInvoice('russiafa:RFE12345678901234567890','99','USD','','https://user@example.test/return');throw new RuntimeException('Credential-bearing return URL accepted.');}catch(InvalidArgumentException){}
echo "vazinpay-gateway-contract: OK\n";
