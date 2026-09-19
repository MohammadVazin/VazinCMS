<?php
declare(strict_types=1);

$root=dirname(__DIR__);$runtime=sys_get_temp_dir().'/vazincms-telegram-assistant-'.bin2hex(random_bytes(6));mkdir($runtime,0700,true);
putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE=:memory:');putenv('VAZINCMS_STORAGE_PATH='.$runtime);putenv('SESSION_SECURE=false');
putenv('APP_KEY=telegram-assistant-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');putenv('APP_URL=https://assistant.example.test');
putenv('EXTERNAL_DELIVERY_ENABLED=true');putenv('TELEGRAM_NETWORK_ENABLED=true');putenv('TELEGRAM_BUSINESS_ENABLED=true');
putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
require $root.'/src/bootstrap.php';

use VazinCMS\{AppointmentService,Auth,Database,ExtensionManager,ExtensionRuntime,TelegramAssistantAdminService,TelegramAssistantMaintenance,TelegramAssistantOutbox,TelegramAssistantRepository,TelegramAssistantWorker,TelegramBusinessService,TelegramMiniAppSession,TelegramSyncService};

$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$expectError=static function(callable $action,string $message)use($check):void{$caught=null;try{$action();}catch(Throwable $error){$caught=$error;}$check($caught instanceof Throwable,$message);};
$cleanup=static function(string $path)use(&$cleanup):void{if(!is_dir($path))return;foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())$cleanup($entry->getPathname());else@unlink($entry->getPathname());}@rmdir($path);};
$fixture=static function(string $name)use($root):array{$value=json_decode((string)file_get_contents($root.'/tests/fixtures/telegram/'.$name.'.json'),true,64,JSON_THROW_ON_ERROR);return is_array($value)?$value:throw new RuntimeException('fixture invalid');};

try{
 $uiScript=(string)file_get_contents($root.'/public/assets/telegram-assistant-1090.js');
 $check(str_contains($uiScript,"typeof priceValue === 'object'")&&!str_contains($uiScript,'service?.price?.amount ?? service?.price_amount ?? service?.price'),'Price-on-request UI can render an object value');
 $pdo=Database::connection();$pdo->exec((string)file_get_contents($root.'/database/schema-sqlite.sql'));
 $migrations=glob($root.'/database/migrations/*-sqlite.sql')?:[];usort($migrations,static fn(string$a,string$b):int=>version_compare(explode('-',basename($a))[0],explode('-',basename($b))[0]));
 foreach($migrations as$migration){$version=explode('-',basename($migration))[0];$seen=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');$seen->execute(['version'=>$version]);if(!$seen->fetchColumn())$pdo->exec((string)file_get_contents($migration));}
 $check((bool)$pdo->query("SELECT 1 FROM schema_migrations WHERE version='10.9.0'")->fetchColumn(),'10.9 migration missing');
 $columns=$pdo->query('PRAGMA table_info(telegram_miniapp_sessions)')->fetchAll();$check(in_array('contact_id',array_column($columns,'name'),true),'Mini App contact link missing');

 $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Assistant Owner','assistant-owner@example.test',:password,'owner','active')")->execute(['password'=>password_hash('contract-only',PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $token='123456789:'.str_repeat('A',35);
 $connectionId=TelegramSyncService::createConnection(['name'=>'Assistant Contract','chat_id'=>'-1001234567890','bot_token'=>$token,'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'ru','web_app_enabled'=>1,'is_enabled'=>1],$ownerId);
 $repository=new TelegramAssistantRepository();$profile=$repository->saveProfile($connectionId,[
  'assistant_name'=>'Помощник Оксаны','locale'=>'ru','timezone'=>'Asia/Novosibirsk','welcome'=>'Здравствуйте! Я помощник Оксаны.',
  'settings'=>['booking_confirmation'=>'manual','retention_days'=>30,'faq'=>[]],'auto_reply_enabled'=>true,'booking_enabled'=>true,'handoff_enabled'=>true,'is_enabled'=>true,
 ]);$profileId=(int)$profile['id'];

 $connectionUpdate=$fixture('business-connection');$connectionUpdate['business_connection']['date']=time();
 $recorded=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$connectionUpdate);$check(($recorded['status']??'')==='connection_recorded','Business connection was not dispatched');
 $businessService=new TelegramBusinessService($repository);$business=$businessService->authorize($connectionId,'bc_fixture_oksana_001',true);$check((int)$business['is_enabled']===1,'Business connection authorization failed');

 $message=$fixture('business-message');$message['business_message']['date']=time();
 $stored=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$message);$check(($stored['reason']??'')==='automation_disabled','Assistant must store but not reply while autoreply gate is off');
 $auditPayload=(string)$pdo->query("SELECT payload_json FROM telegram_updates WHERE update_id=900002")->fetchColumn();
 $check(!str_contains($auditPayload,'Fixture Customer')&&!str_contains($auditPayload,'Хочу выбрать'),'Raw Business customer payload leaked into telegram_updates');
 $encrypted=$pdo->query("SELECT content_encrypted,content_key,expires_at FROM telegram_assistant_messages WHERE telegram_message_id=501")->fetch();
 $check(is_array($encrypted)&&strlen((string)$encrypted['content_key'])===32&&!str_contains((string)$encrypted['content_encrypted'],'Хочу'),'Business content was not encrypted');
 $expiry=strtotime((string)$encrypted['expires_at'].' UTC');$check($expiry!==false&&$expiry>=time()+29*86400&&$expiry<=time()+31*86400,'Message retention is not approximately 30 days');

 $appointments=new AppointmentService($repository);$service=$appointments->saveService($profileId,[
  'slug'=>'private-consultation','title'=>'Личная консультация','summary'=>'По предварительной записи','duration_minutes'=>60,'mode'=>'both','location_label'=>'Онлайн / Новосибирск','price_amount'=>null,'price_currency'=>'RUB','is_enabled'=>1,
 ]);
 $zone=new DateTimeZone('Asia/Novosibirsk');$start=(new DateTimeImmutable('tomorrow 11:00',$zone));$end=$start->modify('+60 minutes');
 $slot=$appointments->createSlot($profileId,(int)$service['id'],$start->format('Y-m-d\TH:i'),$end->format('Y-m-d\TH:i'));

 putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=true');putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=true');
 $handoff=$fixture('business-message');$handoff['update_id']=900003;$handoff['business_message']['message_id']=503;$handoff['business_message']['date']=time();$handoff['business_message']['text']='Хочу поговорить с Оксаной';
 $handoffResult=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$handoff);$check(($handoffResult['status']??'')==='handoff_requested'&&!empty($handoffResult['queued']),'Handoff was not requested/acknowledged');

 $booking=$fixture('business-message');$booking['update_id']=900006;$booking['business_message']['message_id']=601;$booking['business_message']['from']['id']=700000102;$booking['business_message']['chat']['id']=700000102;$booking['business_message']['date']=time();$booking['business_message']['text']='Хочу записаться';
 $bookingResult=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$booking);$check(($bookingResult['intent']??'')==='booking'&&!empty($bookingResult['queued']),'Booking intent did not queue Web App CTA');

 $calls=[];$worker=TelegramAssistantWorker::process(20,static function(array $connection,array $job)use(&$calls):callable{return static function(string $method,array $parameters)use(&$calls):array{$calls[]=['method'=>$method,'parameters'=>$parameters];return ['ok'=>true,'result'=>['message_id'=>8000+count($calls),'date'=>time()]];};});
 $check($worker['sent']===2&&count($calls)===2,'Assistant worker did not deliver both safe queued replies');
 $webAppSeen=false;foreach($calls as$call){$url=(string)($call['parameters']['reply_markup']['inline_keyboard'][0][0]['web_app']['url']??'');if($url==='https://assistant.example.test/telegram/assistant/'.TelegramSyncService::connectionById($connectionId)['webhook_key'])$webAppSeen=true;}
 $check($webAppSeen,'Safe worker stripped or changed the same-origin Web App booking button');

 $loop=$fixture('business-message-loop');$loop['business_message']['date']=time();$before=(int)$pdo->query('SELECT COUNT(*) FROM telegram_assistant_outbox')->fetchColumn();$loopResult=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$loop);$after=(int)$pdo->query('SELECT COUNT(*) FROM telegram_assistant_outbox')->fetchColumn();
 $check(($loopResult['reason']??'')==='bot_loop_guard'&&$before===$after,'sender_business_bot loop guard failed');

 $deleted=$fixture('deleted-business-messages');$deletedResult=TelegramSyncService::receiveWebhook(TelegramSyncService::connectionById($connectionId),$deleted);$check(($deletedResult['deleted']??0)===1,'Deleted Business message was not tombstoned');

 $contact=$pdo->query("SELECT * FROM telegram_assistant_contacts WHERE telegram_user_id='700000101'")->fetch();$conversation=$pdo->query("SELECT * FROM telegram_assistant_conversations WHERE contact_id=".(int)$contact['id'])->fetch();
 $operatorOutbox=new TelegramAssistantOutbox($repository,$businessService,true);$adminAssistant=new TelegramAssistantAdminService($repository,$businessService,$operatorOutbox);
 $repository->setConversationMode((int)$conversation['id'],'bot');$botConversation=$repository->conversationById((int)$conversation['id']);
 $deniedInBot=$operatorOutbox->enqueue($business,$botConversation,'send_message',['text'=>'must not queue','operator_reply'=>true],'operator-wrong-mode-bot',3600);
 $check($deniedInBot===null,'Operator reply was queued while conversation mode was bot');
 $queuedOperator=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Ответ оператора','operator-human-001');
 $freshHuman=$repository->conversationById((int)$conversation['id']);
 $check(!empty($queuedOperator['queued'])&&($freshHuman['mode']??'')==='human','AdminService reply did not reload the conversation after its implicit takeover');
 $repository->setConversationMode((int)$conversation['id'],'bot');$cancelledCalls=[];
 $cancelledWorker=TelegramAssistantWorker::process(20,static function(array $connection,array $job)use(&$cancelledCalls):callable{return static function(string $method,array $parameters)use(&$cancelledCalls):array{$cancelledCalls[]=['method'=>$method,'parameters'=>$parameters];return ['ok'=>true,'result'=>['message_id'=>8901,'date'=>time()]];};});
 $check($cancelledWorker['sent']===0&&$cancelledCalls===[],'Claim delivered an operator reply after conversation left human mode');
 $cancelledStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$queuedOperator['outbox_id'])->fetchColumn();$check($cancelledStatus==='cancelled','Invalid operator reply was not cancelled during claim');
 $adminAssistant->takeover($profileId,(int)$conversation['id'],$ownerId);$deliveredOperator=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Ответ оператора разрешён','operator-human-002');
 $operatorCalls=[];$operatorWorker=TelegramAssistantWorker::process(20,static function(array $connection,array $job)use(&$operatorCalls):callable{return static function(string $method,array $parameters)use(&$operatorCalls):array{$operatorCalls[]=['method'=>$method,'parameters'=>$parameters];return ['ok'=>true,'result'=>['message_id'=>8902,'date'=>time()]];};});
 $check(!empty($deliveredOperator['queued'])&&$operatorWorker['sent']===1&&count($operatorCalls)===1&&($operatorCalls[0]['parameters']['text']??'')==='Ответ оператора разрешён','Human-mode operator reply was not claimed and delivered exactly once');
 $invalidHeadIds=[];for($index=1;$index<=21;$index++){$queued=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Expired scan head '.$index,'operator-scan-head-'.str_pad((string)$index,3,'0',STR_PAD_LEFT));$invalidHeadIds[]=(int)$queued['outbox_id'];}
 $pdo->exec("UPDATE telegram_assistant_outbox SET expires_at=datetime('now','-1 minute') WHERE id IN (".implode(',',$invalidHeadIds).')');$scanTail=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Valid scan tail','operator-scan-tail-001');$scanCalls=[];
 $scanWorker=TelegramAssistantWorker::process(20,static function(array$connection,array$job)use(&$scanCalls):callable{return static function(string$method,array$parameters)use(&$scanCalls):array{$scanCalls[]=$parameters['text']??'';return ['ok'=>true,'result'=>['message_id'=>8940+count($scanCalls),'date'=>time()]];};});
 $cancelledHeads=(int)$pdo->query("SELECT COUNT(*) FROM telegram_assistant_outbox WHERE id IN (".implode(',',$invalidHeadIds).") AND status='cancelled'")->fetchColumn();$scanTailStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$scanTail['outbox_id'])->fetchColumn();
 $check($scanWorker['sent']===1&&$cancelledHeads===21&&$scanTailStatus==='sent'&&$scanCalls===['Valid scan tail'],'Worker stopped after a full invalid scan page and delayed a valid tail job');
 $expiredHead=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Expired queue head','operator-budget-expired-000');$pdo->exec("UPDATE telegram_assistant_outbox SET expires_at=datetime('now','-1 minute') WHERE id=".(int)$expiredHead['outbox_id']);
 $budgetOne=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Budget reply one','operator-budget-001');$budgetTwo=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Budget reply two','operator-budget-002');
 $clockValues=[0.0,0.0,2.0];$clockIndex=0;$budgetCalls=[];
 $budgetWorker=TelegramAssistantWorker::process(20,static function(array$connection,array$job)use(&$budgetCalls):callable{return static function(string$method,array$parameters)use(&$budgetCalls):array{$budgetCalls[]=$parameters['text']??'';return ['ok'=>true,'result'=>['message_id'=>8950+count($budgetCalls),'date'=>time()]];};},1.0,static function()use(&$clockValues,&$clockIndex):float{$value=$clockValues[min($clockIndex,count($clockValues)-1)];$clockIndex++;return$value;});
 $expiredHeadStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$expiredHead['outbox_id'])->fetchColumn();$budgetOneStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$budgetOne['outbox_id'])->fetchColumn();$budgetTwoStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$budgetTwo['outbox_id'])->fetchColumn();
 $check($budgetWorker['sent']===1&&$budgetWorker['budget_exhausted']===true&&count($budgetCalls)===1&&$expiredHeadStatus==='cancelled'&&$budgetOneStatus==='sent'&&$budgetTwoStatus==='pending','Worker budget failed to scan past an invalid head or over-claimed the queue');
 $budgetRemainderCalls=[];$budgetRemainder=TelegramAssistantWorker::process(20,static function(array$connection,array$job)use(&$budgetRemainderCalls):callable{return static function(string$method,array$parameters)use(&$budgetRemainderCalls):array{$budgetRemainderCalls[]=$parameters['text']??'';return ['ok'=>true,'result'=>['message_id'=>8960+count($budgetRemainderCalls),'date'=>time()]];};});
 $check($budgetRemainder['sent']===1&&$budgetRemainderCalls===['Budget reply two'],'Worker did not deliver the unclaimed budget remainder on the next run');
 $claimedOperator=$adminAssistant->reply($profileId,(int)$conversation['id'],$ownerId,'Не отправлять после освобождения','operator-human-race-003');$claimedRows=$operatorOutbox->claim(20);
 $claimedRow=array_values(array_filter($claimedRows,static fn(array$row):bool=>(int)$row['id']===(int)$claimedOperator['outbox_id']))[0]??null;$check(is_array($claimedRow),'Claim-before-release setup failed');
 $repository->setConversationMode((int)$conversation['id'],'bot');$raceDeliveryCalled=false;
 $raceDelivery=$operatorOutbox->deliverClaimed((int)$claimedRow['id'],(string)$claimedRow['lock_token'],static function(array$job)use(&$raceDeliveryCalled):array{$raceDeliveryCalled=true;return ['message_id'=>8903];});
 $raceStatus=(string)$pdo->query('SELECT status FROM telegram_assistant_outbox WHERE id='.(int)$claimedRow['id'])->fetchColumn();
 $check(!$raceDeliveryCalled&&$raceDelivery['status']==='cancelled'&&$raceStatus==='cancelled','Claimed operator reply delivered after conversation release');
 $hold=$appointments->holdSlot($profileId,(int)$slot['id'],(int)$contact['id'],(int)$conversation['id'],'contract-hold-001',['name'=>'Fixture Customer','notes'=>'Short booking note','timezone'=>'Asia/Novosibirsk']);
 $same=$appointments->holdSlot($profileId,(int)$slot['id'],(int)$contact['id'],(int)$conversation['id'],'contract-hold-001',['name'=>'Fixture Customer','timezone'=>'Asia/Novosibirsk']);$check($same['reference']===$hold['reference'],'Idempotent hold did not return prior reservation');
 $expectError(static fn():array=>$appointments->holdSlot($profileId,(int)$slot['id'],(int)$contact['id'],(int)$conversation['id'],'contract-hold-002',['name'=>'Other','timezone'=>'Asia/Novosibirsk']),'Double booking was not rejected');
 $pending=$appointments->confirm($hold['reference'],(int)$contact['id'],'contract-confirm-001',false);$check($pending['status']==='pending'&&$pending['notes']==='Short booking note','Manual submission or optional note failed');
 $confirmed=$appointments->operatorConfirmForProfile($profileId,$hold['reference'],$ownerId);$check($confirmed['status']==='confirmed','Operator confirmation failed');

 $user=['id'=>700000101,'first_name'=>'Fixture Customer','language_code'=>'ru'];$values=['auth_date'=>(string)time(),'query_id'=>'AAE-contract-query','user'=>json_encode($user,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];ksort($values,SORT_STRING);$checkString=implode("\n",array_map(static fn(string$key,string$value):string=>$key.'='.$value,array_keys($values),array_values($values)));$secret=hash_hmac('sha256',$token,'WebAppData',true);$values['hash']=hash_hmac('sha256',$checkString,$secret);$initData=http_build_query($values,'','&',PHP_QUERY_RFC3986);
 $sessions=new TelegramMiniAppSession($repository);$mini=$sessions->start($profileId,$initData,$token,300,900);$bound=$sessions->bindContact($mini['token'],(int)$contact['id']);$check((int)$bound['contact_id']===(int)$contact['id'],'Mini App session was not bound to the customer contact');
 $rotated=$sessions->start($profileId,$initData,$token,300,900);$check($rotated['token']!==$mini['token'],'Mini App refresh did not rotate its bearer token');
 $expectError(static fn():array=>$sessions->authenticate($mini['token'],$profileId),'Rotated Mini App bearer token remained active');
 $bound=$sessions->bindContact($rotated['token'],(int)$contact['id']);$check((int)$bound['contact_id']===(int)$contact['id'],'Rotated Mini App session lost its customer binding');
 $mine=$appointments->reservationsForContact($profileId,(int)$bound['contact_id']);$check(count($mine)===1&&$mine[0]['reference']===$hold['reference'],'My Bookings is not scoped to bound contact');

 ExtensionManager::reset();ExtensionManager::synchronize(true);ExtensionRuntime::reset();
 $route=static function(string$method,string$path,?string$bearer=null,?string$csrf=null):array{
  $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REQUEST_URI']=$path;unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],$_SERVER['HTTP_X_CSRF_TOKEN'],$_SERVER['CONTENT_TYPE']);
  if($bearer!==null)$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$bearer;if($csrf!==null)$_SERVER['HTTP_X_CSRF_TOKEN']=$csrf;http_response_code(200);
  ob_start();$handled=ExtensionRuntime::dispatch($method,$path);$body=(string)ob_get_clean();$decoded=json_decode($body,true);
  return ['handled'=>$handled,'status'=>http_response_code(),'body'=>$body,'json'=>is_array($decoded)?$decoded:[]];
 };
 $key=(string)TelegramSyncService::connectionById($connectionId)['webhook_key'];$base='/telegram/assistant/'.$key.'/api';
 putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=true');
 $previewRoute=$route('GET','/telegram/assistant-preview/'.$key);$check($previewRoute['handled']&&$previewRoute['status']===200&&str_contains($previewRoute['body'],'Помощник Оксаны')&&!str_contains($previewRoute['body'],'__VZ_DYNAMIC_'),'Standalone Mini App preview left protected placeholders in HTML');
 $profileRoute=$route('GET',$base.'/profile',$rotated['token']);$check($profileRoute['handled']&&$profileRoute['status']===200&&($profileRoute['json']['data']['profile']['capabilities']['booking']??false)===true,'Customer Bearer/profile route contract failed');
 $adminDenied=$route('GET',$base.'/admin/conversations',$rotated['token']);$check($adminDenied['handled']&&$adminDenied['status']===401&&($adminDenied['json']['error']['code']??'')==='unauthorized','Customer bearer crossed into the admin API');
 $pdo->prepare('UPDATE telegram_assistant_profiles SET booking_enabled=0,handoff_enabled=0 WHERE id=:id')->execute(['id'=>$profileId]);
 $handoffDenied=$route('POST',$base.'/handoff',$rotated['token']);$check($handoffDenied['status']===503&&($handoffDenied['json']['error']['code']??'')==='handoff_disabled','Disabled handoff capability was bypassed');
 $bookingDenied=$route('POST',$base.'/reservations/hold',$rotated['token']);$check($bookingDenied['status']===503&&($bookingDenied['json']['error']['code']??'')==='booking_disabled','Disabled booking capability was bypassed');
 $pdo->prepare('UPDATE telegram_assistant_profiles SET booking_enabled=1,handoff_enabled=1 WHERE id=:id')->execute(['id'=>$profileId]);
 Auth::login($ownerId);$adminGet=$route('GET',$base.'/admin/conversations');$check($adminGet['status']===200&&isset($adminGet['json']['data']['items'][0]['last_message']),'Admin inbox route or preview DTO failed');
 $csrfDenied=$route('POST',$base.'/admin/conversations/'.(int)$conversation['id'].'/takeover',null,'invalid-csrf');$check($csrfDenied['status']===419&&($csrfDenied['json']['error']['code']??'')==='csrf_invalid','Admin mutation CSRF contract failed');

 $maintenance=TelegramAssistantMaintenance::run(100);$check(isset($maintenance['sessions'],$maintenance['messages']),'Assistant maintenance did not run');
 echo "Telegram assistant contract: OK\n";
}finally{
 foreach(['EXTERNAL_DELIVERY_ENABLED','TELEGRAM_NETWORK_ENABLED','TELEGRAM_BUSINESS_ENABLED','TELEGRAM_ASSISTANT_DELIVERY_ENABLED','TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED','TELEGRAM_ASSISTANT_PREVIEW_ENABLED']as$flag)putenv($flag.'=false');$cleanup($runtime);
}
