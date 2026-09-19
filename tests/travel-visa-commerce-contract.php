<?php
declare(strict_types=1); require dirname(__DIR__).'/src/TravelVisaCommerceContract.php'; require dirname(__DIR__).'/src/TravelVisaCommerceService.php'; use VazinCMS\TravelVisaCommerceContract as C; use VazinCMS\TravelVisaCommerceService as S;
if(C::invoice('travelvisa:ORDER123456','99.00','USD','https://example.test/return')['currency']!=='USD')throw new RuntimeException('invoice');
if(!C::canStartProcessing('paid',null,null)||C::canRefund('paid','2026-01-01',null))throw new RuntimeException('lifecycle');
$db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec(file_get_contents(dirname(__DIR__).'/database/migrations/10.30.0-sqlite.sql'));$s=new S($db);
$s->create('demo','ORDER123456','visa-evisa',null,'99.00','USD');$s->bindInvoice('ORDER123456','inv_123456','bind-key-123');$paid=['external_order_id'=>'travelvisa:ORDER123456','amount'=>'99.00','currency'=>'USD','status'=>'paid'];$s->applyReadback('ORDER123456',$paid,'delivery-123');$s->reserveRefund('ORDER123456','refund-key-123','customer:1');
try{$s->reserveProcessing('ORDER123456','process-key-123','owner:1');throw new RuntimeException('refund race');}catch(RuntimeException){}
echo "travel-visa-commerce-contract: OK\n";
