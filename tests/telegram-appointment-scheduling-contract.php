<?php
declare(strict_types=1);

$root=dirname(__DIR__);$runtime=sys_get_temp_dir().'/vazincms-appointment-scheduling-'.bin2hex(random_bytes(6));mkdir($runtime,0700,true);
putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE=:memory:');putenv('VAZINCMS_STORAGE_PATH='.$runtime);putenv('SESSION_SECURE=false');
putenv('APP_KEY=appointment-scheduling-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');putenv('APP_URL=https://assistant.example.test');
putenv('EXTERNAL_DELIVERY_ENABLED=false');putenv('TELEGRAM_NETWORK_ENABLED=false');putenv('TELEGRAM_BUSINESS_ENABLED=false');
putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
require $root.'/src/bootstrap.php';

use VazinCMS\{AppointmentService,Database,Scheduler,TelegramAssistantRepository,TelegramSyncService};

$check=static function(bool$condition,string$message):void{if(!$condition)throw new RuntimeException($message);};
$expectError=static function(callable$action,string$message)use($check):void{$caught=null;try{$action();}catch(Throwable$error){$caught=$error;}$check($caught instanceof Throwable,$message);};
$cleanup=static function(string$path)use(&$cleanup):void{if(!is_dir($path))return;foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())$cleanup($entry->getPathname());else@unlink($entry->getPathname());}@rmdir($path);};

try{
    $pdo=Database::connection();$pdo->exec((string)file_get_contents($root.'/database/schema-sqlite.sql'));
    $migrations=glob($root.'/database/migrations/*-sqlite.sql')?:[];usort($migrations,static fn(string$a,string$b):int=>version_compare(explode('-',basename($a))[0],explode('-',basename($b))[0]));
    foreach($migrations as$migration){$version=explode('-',basename($migration))[0];$seen=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');$seen->execute(['version'=>$version]);if(!$seen->fetchColumn())$pdo->exec((string)file_get_contents($migration));}
    $check((bool)$pdo->query("SELECT 1 FROM schema_migrations WHERE version='10.9.1'")->fetchColumn(),'10.9.1 migration marker missing');

    $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Scheduling Owner','schedule-owner@example.test',:password,'owner','active')")
        ->execute(['password'=>password_hash('contract-only',PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Denied Admin','denied-admin@example.test',:password,'admin','active')")
        ->execute(['password'=>password_hash('contract-only',PASSWORD_DEFAULT)]);$deniedAdminId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO staff_permissions(user_id,permission,is_granted,updated_by) VALUES(:user,'telegram',0,:owner)")->execute(['user'=>$deniedAdminId,'owner'=>$ownerId]);
    $connectionId=TelegramSyncService::createConnection(['name'=>'Schedule Contract','chat_id'=>'-1002234567890','bot_token'=>'223456789:'.str_repeat('A',35),'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'ru','web_app_enabled'=>1,'is_enabled'=>1],$ownerId);
    $repository=new TelegramAssistantRepository();$profile=$repository->saveProfile($connectionId,[
        'assistant_name'=>'Schedule Assistant','locale'=>'ru','timezone'=>'Asia/Novosibirsk','welcome'=>'Welcome',
        'settings'=>['booking_confirmation'=>'manual'],'auto_reply_enabled'=>false,'booking_enabled'=>true,'handoff_enabled'=>true,'is_enabled'=>true,
    ]);$profileId=(int)$profile['id'];
    $appointments=new AppointmentService($repository);$service=$appointments->saveService($profileId,[
        'slug'=>'schedule-contract','title'=>'Private scheduling contract','summary'=>'Contract only','duration_minutes'=>60,
        'buffer_before_minutes'=>10,'buffer_after_minutes'=>5,'mode'=>'online','price_currency'=>'RUB','is_enabled'=>1,
    ]);$serviceId=(int)$service['id'];

    $secondConnection=TelegramSyncService::createConnection(['name'=>'Other Schedule Contract','chat_id'=>'-1003234567890','bot_token'=>'323456789:'.str_repeat('B',35),'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'en','web_app_enabled'=>1,'is_enabled'=>1],$ownerId);
    $secondProfile=$repository->saveProfile($secondConnection,['assistant_name'=>'Other','locale'=>'en','timezone'=>'UTC','welcome'=>'Other','settings'=>[],'booking_enabled'=>true,'handoff_enabled'=>true,'is_enabled'=>true]);
    $expectError(static fn():array=>$appointments->availabilityRules($serviceId,(int)$secondProfile['id']),'Availability rules crossed profile tenancy');

    $zone=new DateTimeZone('Asia/Novosibirsk');$first=(new DateTimeImmutable('tomorrow',$zone));$second=$first->modify('+1 day');
    $from=$first->format('Y-m-d');$until=$second->format('Y-m-d');$weekday=(int)$first->format('N');
    $rule=['weekday'=>$weekday,'start_time'=>'09:00','end_time'=>'12:00','slot_interval_minutes'=>90,'effective_from'=>$from,'effective_until'=>$from,'is_enabled'=>true];
    $appointments->replaceAvailabilityRulesForProfile($profileId,$serviceId,[$rule]);
    $savedRules=$appointments->availabilityRules($serviceId,$profileId);
    $check(count($savedRules)===1&&$savedRules[0]['weekday']===$weekday&&$savedRules[0]['is_enabled']===true,'Weekly rule did not round-trip with ISO weekday');
    $expectError(static fn():array=>$appointments->replaceAvailabilityRules($serviceId,[
        $rule,['weekday'=>$weekday,'start_time'=>'11:00','end_time'=>'14:00','slot_interval_minutes'=>90,'effective_from'=>$from,'effective_until'=>$from],
    ]),'Overlapping weekly rules were accepted');
    $expectError(static fn():array=>$appointments->replaceAvailabilityRules($serviceId,[array_replace($rule,['slot_interval_minutes'=>60])]),'Interval shorter than occupied service time was accepted');
    $expectError(static fn():array=>$appointments->replaceAvailabilityRules($serviceId,[array_replace($rule,['effective_from'=>'2026-02-31'])]),'Invalid calendar date was accepted');

    $appointments->addAvailabilityException($serviceId,$from.'T09:00',$from.'T10:20','unavailable','private closure');
    $appointments->addAvailabilityException($serviceId,$from.'T09:30',$from.'T12:00','available','overlapping extra window');
    $appointments->addAvailabilityException($serviceId,$until.'T14:00',$until.'T16:30','available','extra window');
    $firstGeneration=$appointments->generateSlotsForProfile($profileId,$serviceId,$from,$until);
    $check($firstGeneration['created']===3&&$firstGeneration['existing']===0&&$firstGeneration['skipped']===3,'Initial generation did not apply weekly, exception, and overlap guards');
    $secondGeneration=$appointments->generateSlotsForProfile($profileId,$serviceId,$from,$until);
    $check($secondGeneration['created']===0&&$secondGeneration['existing']===3&&$secondGeneration['skipped']===3,'Slot generation was not additive and idempotent');

    $available=$pdo->query("SELECT * FROM telegram_appointment_slots WHERE service_id=$serviceId AND status='available' ORDER BY starts_at")->fetchAll();
    $check(count($available)===3,'Unexpected number of materialized slots');
    $expectError(static fn():array=>$appointments->createSlot($profileId,$serviceId,$until.'T18:00',$until.'T18:30'),'Manual slot duration diverged from the service duration');
    $expectError(static fn():array=>$appointments->createSlot($profileId,$serviceId,$until.'T15:15',$until.'T16:15'),'Manual slot bypassed generated slot buffer spacing');
    $slot=$available[0];$hold=$appointments->holdSlot($profileId,(int)$slot['id'],null,null,'schedule-hold-001',[
        'name'=>'Sensitive Customer','phone'=>'+79990000000','email'=>'sensitive@example.test','notes'=>'private booking note','timezone'=>'Asia/Novosibirsk',
    ]);
    $pending=$appointments->confirm($hold['reference'],null,'schedule-confirm-001',false);$check($pending['status']==='pending','Manual reservation was not submitted as pending');
    $notice=$pdo->query("SELECT * FROM notifications WHERE channel='admin' AND event_type='telegram_appointment_pending'")->fetch();
    $noticeCount=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE channel='admin' AND event_type='telegram_appointment_pending'")->fetchColumn();
    $noticeText=(string)($notice['subject']??'').' '.(string)($notice['body']??'');
    $check(is_array($notice)&&$noticeCount===1&&(int)$notice['user_id']===$ownerId&&($notice['status']??'')==='pending','Internal manager notice ignored Telegram staff permissions');
    foreach(['Sensitive Customer','+79990000000','sensitive@example.test','private booking note']as$sensitive)$check(!str_contains($noticeText,$sensitive),'PII leaked into internal manager notice');

    $pdo->prepare("INSERT INTO notifications(user_id,channel,event_type,dedupe_key,subject,body) VALUES(:user,'email','contract_email','schedule-email-contract','Email contract','Queue me')")->execute(['user'=>$ownerId]);
    $pdo->prepare("INSERT INTO scheduled_tasks(name,task_type,interval_minutes,config,created_by) VALUES('Notification contract','notification_queue',60,'{}',:user)")->execute(['user'=>$ownerId]);$taskId=(int)$pdo->lastInsertId();
    $run=Scheduler::runDue($taskId);$check(($run[0]['status']??'')==='succeeded','Notification scheduler contract did not run');
    $adminStatus=(string)$pdo->query("SELECT status FROM notifications WHERE channel='admin' AND event_type='telegram_appointment_pending'")->fetchColumn();
    $emailStatus=(string)$pdo->query("SELECT status FROM notifications WHERE channel='email' AND event_type='contract_email'")->fetchColumn();
    $check($adminStatus==='pending'&&$emailStatus==='queued','Internal manager notice escaped into the external notification queue');

    $rejected=$appointments->operatorRejectForProfile($profileId,$hold['reference'],$ownerId,'operator_rejected');
    $check($rejected['status']==='cancelled'&&$rejected['resolution']==='rejected','Operator rejection did not expose a rejected resolution');
    $slotStatus=(string)$pdo->query('SELECT status FROM telegram_appointment_slots WHERE id='.(int)$slot['id'])->fetchColumn();
    $noticeStatus=(string)$pdo->query("SELECT status FROM notifications WHERE channel='admin' AND event_type='telegram_appointment_pending'")->fetchColumn();
    $check($slotStatus==='available'&&$noticeStatus==='sent','Rejection did not release the slot and resolve its notice');
    $rejectedAgain=$appointments->operatorRejectForProfile($profileId,$hold['reference'],$ownerId,'operator_rejected');
    $check($rejectedAgain['resolution']==='rejected','Repeated rejection was not idempotent');

    echo "Telegram appointment scheduling contract: OK\n";
}finally{
    foreach(['EXTERNAL_DELIVERY_ENABLED','TELEGRAM_NETWORK_ENABLED','TELEGRAM_BUSINESS_ENABLED','TELEGRAM_ASSISTANT_DELIVERY_ENABLED','TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED','TELEGRAM_ASSISTANT_PREVIEW_ENABLED']as$flag)putenv($flag.'=false');$cleanup($runtime);
}
