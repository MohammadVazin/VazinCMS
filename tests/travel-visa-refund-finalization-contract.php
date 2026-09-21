<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use VazinCMS\TravelVisaCommerceService;

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$pdo->exec("
CREATE TABLE travel_visa_orders(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 tenant_key TEXT NOT NULL,
 public_ref TEXT NOT NULL UNIQUE,
 service_code TEXT NOT NULL,
 customer_user_id INTEGER,
 amount TEXT NOT NULL,
 currency TEXT NOT NULL,
 payment_status TEXT NOT NULL DEFAULT 'pending',
 lifecycle_status TEXT NOT NULL DEFAULT 'awaiting_payment',
 invoice_id TEXT,
 processing_started_at TEXT,
 processing_lock TEXT,
 refund_lock TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("
CREATE TABLE travel_visa_events(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id INTEGER NOT NULL,
 event_type TEXT NOT NULL,
 actor_ref TEXT NOT NULL,
 idempotency_key TEXT NOT NULL,
 payload_json TEXT NOT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(order_id,event_type,idempotency_key)
)");

$pdo->exec("
INSERT INTO travel_visa_orders
(tenant_key,public_ref,service_code,amount,currency,
 payment_status,lifecycle_status)
VALUES
('russiafa','TVREFUND120001','test','1.00','USD','paid','paid'),
('russiafa','TVREFUND120002','test','1.00','USD','paid','paid')
");

$s=new TravelVisaCommerceService($pdo);

$expect=function(bool $ok,string $msg):void{
    if(!$ok) throw new RuntimeException($msg);
};

/* Successful refund */

$r=$s->reserveRefund(
    'TVREFUND120001',
    'refund-success-001',
    'test'
);

$expect(
    $r['refund_lock']==='refund-success-001',
    'refund reservation missing'
);

$r=$s->completeRefund(
    'TVREFUND120001',
    'refund-success-001',
    'test'
);

$expect($r['payment_status']==='refunded','refund status');
$expect($r['lifecycle_status']==='cancelled','refund lifecycle');
$expect($r['refund_lock']===null,'refund lock not cleared');

/* completion replay */

$r2=$s->completeRefund(
    'TVREFUND120001',
    'refund-success-001',
    'test'
);

$expect($r2['payment_status']==='refunded','completion replay');

/* Failed provider refund releases lock */

$f=$s->reserveRefund(
    'TVREFUND120002',
    'refund-failure-001',
    'test'
);

$expect(
    $f['refund_lock']==='refund-failure-001',
    'failure reservation missing'
);

$f=$s->failRefund(
    'TVREFUND120002',
    'refund-failure-001',
    'test'
);

$expect($f['payment_status']==='paid','failed refund changed payment');
$expect($f['lifecycle_status']==='paid','failed refund changed lifecycle');
$expect($f['refund_lock']===null,'failed refund lock not released');

/* failure replay */

$f2=$s->failRefund(
    'TVREFUND120002',
    'refund-failure-001',
    'test'
);

$expect($f2['refund_lock']===null,'failure replay');

/* wrong lock */

$pdo->exec("
INSERT INTO travel_visa_orders
(tenant_key,public_ref,service_code,amount,currency,
 payment_status,lifecycle_status,refund_lock)
VALUES
('russiafa','TVREFUND120003','test','1.00','USD',
 'paid','paid','real-lock-003')
");

try {
    $s->completeRefund(
        'TVREFUND120003',
        'wrong-lock-003',
        'test'
    );

    throw new RuntimeException('wrong lock accepted');
} catch(RuntimeException $e) {
    $expect(
        $e->getMessage()==='refund_lock_conflict',
        'wrong lock failure mismatch'
    );
}

$events=$pdo->query("
SELECT event_type,idempotency_key
FROM travel_visa_events
ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

foreach($events as $e){
    echo $e['event_type'].'|'.$e['idempotency_key'].PHP_EOL;
}

$expected=[
    'refund.reserved',
    'refund.completed',
    'refund.reserved',
    'refund.failed'
];

$expect(
    array_column($events,'event_type')===$expected,
    'event sequence mismatch'
);

$expect(count($events)===4,'event count mismatch');

echo "refund completion: PASS\n";
echo "refund failure unlock: PASS\n";
echo "refund replay idempotency: PASS\n";
echo "refund wrong-lock rejection: PASS\n";
echo "travel-visa-refund-finalization-contract: OK\n";
