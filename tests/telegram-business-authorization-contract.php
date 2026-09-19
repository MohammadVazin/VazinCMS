<?php
declare(strict_types=1);

$root=dirname(__DIR__);$runtime=sys_get_temp_dir().'/vazincms-business-auth-'.bin2hex(random_bytes(6));mkdir($runtime,0700,true);
putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE=:memory:');putenv('VAZINCMS_STORAGE_PATH='.$runtime);putenv('SESSION_SECURE=false');
putenv('APP_KEY=telegram-business-authorization-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');putenv('APP_URL=https://assistant-owner.example.test');
putenv('EXTERNAL_DELIVERY_ENABLED=true');putenv('TELEGRAM_NETWORK_ENABLED=true');putenv('TELEGRAM_BUSINESS_ENABLED=true');
putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
require $root.'/src/bootstrap.php';

use VazinCMS\Controllers\TelegramAssistantController;
use VazinCMS\{Auth,Database,ExtensionManager,ExtensionRuntime,Security,TelegramAssistantOutbox,TelegramAssistantRepository,TelegramBusinessService,TelegramSyncService};

$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$expectError=static function(callable $action,string $message)use($check):void{$caught=null;try{$action();}catch(Throwable $error){$caught=$error;}$check($caught instanceof Throwable,$message);};
$cleanup=static function(string $path)use(&$cleanup):void{if(!is_dir($path))return;foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())$cleanup($entry->getPathname());else@unlink($entry->getPathname());}@rmdir($path);};
$update=static fn(string$opaque,string$owner,bool$enabled=true):array=>[
 'id'=>$opaque,'user'=>['id'=>(int)$owner,'is_bot'=>false,'first_name'=>'Not persisted'],
 'user_chat_id'=>(int)$owner,'date'=>time(),'rights'=>['can_reply'=>true,'can_read_messages'=>false],'is_enabled'=>$enabled,
];

try{
 $pdo=Database::connection();$pdo->exec((string)file_get_contents($root.'/database/schema-sqlite.sql'));
 $migrations=glob($root.'/database/migrations/*-sqlite.sql')?:[];usort($migrations,static fn(string$a,string$b):int=>version_compare(explode('-',basename($a))[0],explode('-',basename($b))[0]));
 foreach($migrations as$migration){$version=explode('-',basename($migration))[0];$seen=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');$seen->execute(['version'=>$version]);if(!$seen->fetchColumn())$pdo->exec((string)file_get_contents($migration));}
 $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Owner','business-owner@example.test',:password,'owner','active')")->execute(['password'=>password_hash('contract-only',PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Customer','business-customer@example.test',:password,'client','active')")->execute(['password'=>password_hash('contract-only',PASSWORD_DEFAULT)]);$customerId=(int)$pdo->lastInsertId();
 $token='123456789:'.str_repeat('A',35);
 $connectionId=TelegramSyncService::createConnection(['name'=>'Business Authorization','chat_id'=>'-1001234567890','bot_token'=>$token,'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'ru','web_app_enabled'=>1,'is_enabled'=>1],$ownerId);
 $repository=new TelegramAssistantRepository();$profile=$repository->saveProfile($connectionId,[
  'assistant_name'=>'Business Assistant','locale'=>'ru','timezone'=>'UTC','welcome'=>'Welcome','settings'=>['retention_days'=>30],
  'auto_reply_enabled'=>false,'booking_enabled'=>true,'handoff_enabled'=>true,'is_enabled'=>false,
 ]);$profileId=(int)$profile['id'];$businessService=new TelegramBusinessService($repository);

 $channelOnlyId=TelegramSyncService::createConnection(['name'=>'Channel Only','chat_id'=>'-1001234567891','bot_token'=>$token,'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'ru','is_enabled'=>1],$ownerId);
 TelegramSyncService::assertWebhookIdentityCompatible($channelOnlyId,['can_connect_to_business'=>false]);
 $expectError(static fn()=>TelegramSyncService::assertWebhookIdentityCompatible($connectionId,['can_connect_to_business'=>false]),'Assistant getMe preflight accepted a bot without Business capability');
 TelegramSyncService::assertWebhookIdentityCompatible($connectionId,['can_connect_to_business'=>true]);

 $ownerA='700000001';$ownerB='700000002';$opaqueA='bc_owner_primary_001';
 $pending=$businessService->handleConnectionUpdate($connectionId,$update($opaqueA,$ownerA));
 $check($pending['authorization_status']==='pending'&&(int)$pending['is_enabled']===0,'New Business connection was not quarantined');
 $safe=$businessService->adminConnections($profileId);$safeJson=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $check(count($safe)===1&&!str_contains($safeJson,$ownerA)&&!str_contains($safeJson,$opaqueA)&&str_contains($safeJson,'•••'),'Admin DTO leaked an unmasked Telegram identifier');

 $businessService->setProfileEnabled($profileId,true);$stillPending=$businessService->requireConnection($connectionId,$opaqueA,false);
 $check((int)$repository->profileById($profileId)['is_enabled']===1&&(int)$stillPending['is_enabled']===0,'Profile activation bypassed pending approval');
 $approved=$businessService->reviewConnection($profileId,(int)$pending['id'],true);
 $check($approved['authorization_status']==='authorized'&&$approved['runtime_enabled']===true&&$approved['expected_owner_pinned']===true,'Owner review did not pin and authorize the connection');
 $settings=(string)$repository->profileById($profileId)['settings_json'];$check(!str_contains($settings,$ownerA)&&str_contains($settings,'business_owner_id_encrypted'),'Expected owner was not encrypted in profile settings');
 $same=$businessService->handleConnectionUpdate($connectionId,$update($opaqueA,$ownerA));$check($same['authorization_status']==='authorized'&&(int)$same['is_enabled']===1,'A later update reset the approved same-owner connection');

 $business=$businessService->requireConnection($connectionId,$opaqueA,true);$contact=$repository->upsertContact($business,['id'=>(int)$ownerB,'first_name'=>'Customer'],$ownerB);$conversation=$repository->findOrCreateConversation($business,$contact,$ownerB);
 $pdo->prepare('UPDATE telegram_assistant_conversations SET last_customer_at=CURRENT_TIMESTAMP,last_message_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$conversation['id']]);$conversation=$repository->conversationById((int)$conversation['id']);
 $queued=(new TelegramAssistantOutbox($repository,$businessService,true))->enqueue($business,$conversation,'send_message',['text'=>'cancel on disable'],'profile-disable-contract',3600);$check(is_array($queued),'Outbox setup failed');
 $businessService->setProfileEnabled($profileId,false);$disabled=$businessService->requireConnection($connectionId,$opaqueA,false);
 $outboxStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$queued['job']['id'])->fetchColumn();
 $check((int)$disabled['is_enabled']===0&&$outboxStatus==='cancelled','Profile disable did not deactivate Business and cancel undelivered work');
 $businessService->setProfileEnabled($profileId,true);$check((int)$businessService->requireConnection($connectionId,$opaqueA,false)['is_enabled']===1,'Approved pinned owner did not safely reactivate');

 $business=$businessService->requireConnection($connectionId,$opaqueA,true);$conversation=$repository->conversationById((int)$conversation['id']);
 $raceOutbox=new TelegramAssistantOutbox($repository,$businessService,true);$claimedBeforeDisable=$raceOutbox->enqueue($business,$conversation,'send_message',['text'=>'must not send after claimed disable'],'claimed-disable-contract',3600);
 $claimedRows=$raceOutbox->claim(20);$claimedRow=array_values(array_filter($claimedRows,static fn(array$row):bool=>(int)$row['id']===(int)$claimedBeforeDisable['job']['id']))[0]??null;
 $check(is_array($claimedRow),'Claim-before-disable setup failed');$businessService->setProfileEnabled($profileId,false);$deliveryCalled=false;
 $revokedDelivery=$raceOutbox->deliverClaimed((int)$claimedRow['id'],(string)$claimedRow['lock_token'],static function(array$job)use(&$deliveryCalled):array{$deliveryCalled=true;return ['message_id'=>9901];});
 $claimedDisabledStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$claimedRow['id'])->fetchColumn();
 $check(!$deliveryCalled&&in_array($revokedDelivery['status'],['lost','cancelled'],true)&&$claimedDisabledStatus==='cancelled','A claimed job delivered after profile disable returned');
 $businessService->setProfileEnabled($profileId,true);$business=$businessService->requireConnection($connectionId,$opaqueA,true);

 $parentDisabledJob=(new TelegramAssistantOutbox($repository,$businessService,true))->enqueue($business,$conversation,'send_message',['text'=>'cancel when parent connection is disabled'],'parent-disable-contract',3600);
 $check(is_array($parentDisabledJob),'Parent-connection outbox setup failed');
 $pdo->prepare('UPDATE telegram_connections SET is_enabled=0 WHERE id=:id')->execute(['id'=>$connectionId]);
 $expectError(static fn()=>$businessService->requireConnection($connectionId,$opaqueA,true),'Disabled parent Telegram connection remained active for Business delivery');
 $claimed=(new TelegramAssistantOutbox($repository,$businessService,true))->claim(20);$parentDisabledStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$parentDisabledJob['job']['id'])->fetchColumn();
 $check($claimed===[]&&$parentDisabledStatus==='cancelled','Disabled parent Telegram connection did not cancel queued delivery');
 $pdo->prepare('UPDATE telegram_connections SET is_enabled=1 WHERE id=:id')->execute(['id'=>$connectionId]);$businessService->setProfileEnabled($profileId,true);

 $attacker=$businessService->handleConnectionUpdate($connectionId,$update('bc_other_owner_001',$ownerB));
 $check($attacker['authorization_status']==='rejected'&&(int)$attacker['is_enabled']===0,'Different Business owner did not fail closed');
 $expectError(static fn()=>$businessService->reviewConnection($profileId,(int)$attacker['id'],true),'Rejected different owner was re-authorized');
 $sameOwnerPending=$businessService->handleConnectionUpdate($connectionId,$update('bc_owner_secondary_001',$ownerA));
 $rejected=$businessService->reviewConnection($profileId,(int)$sameOwnerPending['id'],false);$check($rejected['authorization_status']==='rejected','Explicit admin rejection failed');

 ExtensionManager::reset();ExtensionManager::synchronize(true);ExtensionRuntime::reset();
 $route=static function(string$method,string$path,?string$csrf=null):array{
  $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REQUEST_URI']=$path;unset($_SERVER['HTTP_X_CSRF_TOKEN'],$_SERVER['CONTENT_TYPE']);if($csrf!==null)$_SERVER['HTTP_X_CSRF_TOKEN']=$csrf;http_response_code(200);
  ob_start();$handled=ExtensionRuntime::dispatch($method,$path);$body=(string)ob_get_clean();$decoded=json_decode($body,true);
  return ['handled'=>$handled,'status'=>http_response_code(),'body'=>$body,'json'=>is_array($decoded)?$decoded:[]];
 };
 $key=(string)TelegramSyncService::connectionById($connectionId)['webhook_key'];$base='/telegram/assistant/'.$key.'/api';
 Auth::login($customerId);$denied=$route('GET',$base.'/admin/business-connections');$check($denied['status']===401,'Non-admin read the Business review queue');
 Auth::login($ownerId);$adminGet=$route('GET',$base.'/admin/business-connections');
 $check($adminGet['status']===200&&!str_contains($adminGet['body'],$ownerA)&&!str_contains($adminGet['body'],$ownerB)&&!str_contains($adminGet['body'],$opaqueA),'Admin route exposed raw Telegram identifiers');
 $routePending=$businessService->handleConnectionUpdate($connectionId,$update('bc_owner_route_001',$ownerA));
 $csrfDenied=$route('POST',$base.'/admin/business-connections/'.(int)$routePending['id'].'/allow','invalid');$check($csrfDenied['status']===419,'Business review mutation bypassed CSRF');
 $csrf=Security::csrf();$allowed=$route('POST',$base.'/admin/business-connections/'.(int)$routePending['id'].'/allow',$csrf);
 $check($allowed['status']===200&&($allowed['json']['data']['connection']['authorization_status']??'')==='authorized','Authenticated CSRF-protected approval route failed');

 $_SERVER['REQUEST_METHOD']='GET';$_GET=['connection_id'=>$connectionId];ob_start();(new TelegramAssistantController())->admin();$adminHtml=(string)ob_get_clean();
 $expectedUrl='https://assistant-owner.example.test/telegram/assistant/'.$key;
 $check(str_contains($adminHtml,$expectedUrl)&&str_contains($adminHtml,'data-mini-app-url'),'Authenticated admin page omitted the exact customer Mini App URL');
 echo "Telegram Business authorization contract: OK\n";
}finally{
 foreach(['EXTERNAL_DELIVERY_ENABLED','TELEGRAM_NETWORK_ENABLED','TELEGRAM_BUSINESS_ENABLED','TELEGRAM_ASSISTANT_DELIVERY_ENABLED','TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED','TELEGRAM_ASSISTANT_PREVIEW_ENABLED']as$flag)putenv($flag.'=false');$cleanup($runtime);
}
