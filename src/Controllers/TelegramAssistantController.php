<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use VazinCMS\{
    Access,AppointmentService,Audit,Auth,Database,DeliveryPolicy,Security,TelegramAssistantAdminService,
    TelegramAssistantApiException,TelegramAssistantOutbox,TelegramAssistantRateLimiter,TelegramAssistantRepository,
    TelegramBusinessService,TelegramMiniAppSession,TelegramSyncService,View
};

final class TelegramAssistantController
{
    public function customerApp(string $key): void
    {
        try {
            DeliveryPolicy::assertTelegramBusinessEnabled();
            $context=$this->contextByKey($key);
            if((int)$context['connection_enabled']!==1||(int)$context['is_enabled']!==1)throw new RuntimeException('Mini App فعال نیست.');
            View::renderStandalone('telegram-assistant',[
                'key'=>$key,'locale'=>(string)$context['locale'],'preview'=>false,
                'profile'=>$this->presentProfile($context),
                'services'=>$this->presentServices($this->appointments()->services((int)$context['id'],true)),
                'slots'=>[],'bookings'=>[],'conversations'=>[],'csrf'=>'',
            ]);
        } catch(Throwable){http_response_code(404);View::renderStandalone('telegram-assistant',['key'=>'','locale'=>'ru','preview'=>true,'profile'=>[],'services'=>[],'slots'=>[],'bookings'=>[],'conversations'=>[],'csrf'=>'']);}
    }

    public function preview(string $key): void
    {
        try {
            if(!DeliveryPolicy::telegramAssistantPreviewEnabled())throw new RuntimeException('پیش‌نمایش غیرفعال است.');
            $context=$this->contextByKey($key);
            View::renderStandalone('telegram-assistant',[
                'key'=>$key,'locale'=>(string)$context['locale'],'preview'=>true,
                'profile'=>$this->presentProfile($context)+['customer'=>['first_name'=>'Анна']],
                'services'=>$this->presentServices($this->appointments()->services((int)$context['id'],true)),
                'slots'=>$this->presentSlots($this->appointments()->slotsForProfile((int)$context['id'],100),(string)$context['timezone']),
                'bookings'=>[],'conversations'=>[],'csrf'=>'',
            ]);
        }catch(Throwable){http_response_code(404);View::renderStandalone('telegram-assistant',['key'=>'','locale'=>'ru','preview'=>true,'profile'=>[],'services'=>[],'slots'=>[],'bookings'=>[],'conversations'=>[],'csrf'=>'']);}
    }

    public function admin(): void
    {
        $user=Access::require('telegram');$error=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf();
            try{
                $connectionId=(int)($_POST['connection_id']??0);$connection=TelegramSyncService::connectionById($connectionId);
                if((new TelegramAssistantRepository())->profileByConnection($connectionId)!==null)throw new RuntimeException('برای این اتصال قبلاً دستیار ساخته شده است.');
                (new TelegramAssistantRepository())->saveProfile($connectionId,[
                    'assistant_name'=>trim((string)($_POST['assistant_name']??'')),'locale'=>(string)($_POST['locale']??$connection['locale']),
                    'timezone'=>trim((string)($_POST['timezone']??'Asia/Novosibirsk')),'welcome'=>trim((string)($_POST['welcome']??'')),
                    'settings'=>['booking_confirmation'=>'manual','retention_days'=>30],
                    'auto_reply_enabled'=>false,'booking_enabled'=>true,'handoff_enabled'=>true,'is_enabled'=>false,
                ]);
                Audit::log('telegram.assistant_created','پروفایل دستیار Telegram ساخته شد',(int)$user['id'],['connection_id'=>$connectionId]);
                header('Location: /admin/telegram/assistant?connection_id='.$connectionId);return;
            }catch(Throwable $caught){$error=$caught->getMessage();}
        }
        $context=null;$requested=(int)($_GET['connection_id']??0);
        if($requested>0){try{$context=$this->contextByConnection($requested);}catch(Throwable){}}
        if($context===null){$context=$this->firstContext();}
        if($context===null){
            $connections=TelegramSyncService::connections();
            View::render('telegram-assistant-setup',['title'=>'دستیار Telegram','user'=>$user,'connections'=>$connections,'error'=>$error]);return;
        }
        $profileId=(int)$context['id'];$appointments=$this->appointments();$admin=$this->adminService();
        $businessService=new TelegramBusinessService(new TelegramAssistantRepository());
        View::renderStandalone('telegram-assistant-admin',[
            'key'=>(string)$context['webhook_key'],'locale'=>(string)$context['locale'],'preview'=>false,'csrf'=>Security::csrf(),
            'profile'=>$this->presentProfile($context),
            'services'=>$this->presentServices($appointments->services($profileId,false)),
            'slots'=>$this->presentSlots($appointments->slotsForProfile($profileId,300),(string)$context['timezone']),
            'bookings'=>$appointments->reservationsForProfile($profileId,200),'conversations'=>$admin->conversations($profileId,200),
            'businessConnections'=>$businessService->adminConnections($profileId),'miniAppUrl'=>$this->customerMiniAppUrl($context),
        ]);
    }

    public function api(string $key,string $suffix): void
    {
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try{
            $context=$this->contextByKey($key);$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
            $data=$this->dispatchApi($context,trim($suffix,'/'),$method);
            echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(TelegramAssistantApiException $error){
            http_response_code($error->status);$payload=['code'=>$error->errorCode,'message'=>$error->getMessage()];if($error->fields!==[])$payload['fields']=$error->fields;
            echo json_encode(['ok'=>false,'error'=>$payload],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(RuntimeException $error){
            [$status,$code]=$this->domainError($error->getMessage());http_response_code($status);
            echo json_encode(['ok'=>false,'error'=>['code'=>$code,'message'=>mb_substr($error->getMessage(),0,300)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(Throwable $error){
            $correlation=bin2hex(random_bytes(8));error_log('[VazinCMS telegram assistant api correlation='.$correlation.'] '.$error::class);
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>['code'=>'internal_error','message'=>'درخواست انجام نشد.','correlation'=>$correlation]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    private function dispatchApi(array $context,string $suffix,string $method): array
    {
        if($suffix==='session'){
            $this->method($method,'POST');DeliveryPolicy::assertTelegramBusinessEnabled();
            if((int)$context['connection_enabled']!==1||(int)$context['is_enabled']!==1)throw new TelegramAssistantApiException('connection_required','اتصال دستیار فعال نیست.',503);
            $body=$this->jsonBody();$limit=TelegramAssistantRateLimiter::consume('miniapp-session',(string)$context['id'],Security::clientIp()??'unknown',20,60);
            if(!$limit['allowed'])throw new TelegramAssistantApiException('rate_limited','درخواست‌ها بیش از حد مجاز است.',429);
            return $this->startCustomerSession($context,(string)($body['init_data']??''));
        }
        if(str_starts_with($suffix,'admin/'))return $this->dispatchAdminApi($context,substr($suffix,6),$method);
        return $this->dispatchCustomerApi($context,$suffix,$method);
    }

    private function dispatchCustomerApi(array $context,string $suffix,string $method): array
    {
        DeliveryPolicy::assertTelegramBusinessEnabled();$session=$this->customerSession($context);
        $rate=TelegramAssistantRateLimiter::consume('miniapp-api',(string)$context['id'],(string)$session['telegram_user_id'],120,60);
        if(!$rate['allowed'])throw new TelegramAssistantApiException('rate_limited','درخواست‌ها بیش از حد مجاز است.',429);
        $appointments=$this->appointments();$profileId=(int)$context['id'];$contactId=(int)($session['contact_id']??0);
        if($suffix==='profile'){$this->method($method,'GET');return ['profile'=>$this->presentProfile($context)+['customer'=>$session['data']['user']??[]]];}
        if($suffix==='services'){$this->method($method,'GET');return ['items'=>$this->presentServices($appointments->services($profileId,true)),'timezone'=>(string)$context['timezone']];}
        if($suffix==='slots'){
            $this->method($method,'GET');$serviceId=(int)($_GET['service_id']??0);$date=(string)($_GET['date']??'');
            if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1)throw new TelegramAssistantApiException('invalid_date','تاریخ معتبر نیست.',422,['date'=>'invalid']);
            $service=$appointments->service($serviceId,$profileId);$zone=new DateTimeZone((string)$service['timezone']);$start=new DateTimeImmutable($date.' 00:00:00',$zone);$end=$start->modify('+1 day');$utc=new DateTimeZone('UTC');
            $items=$appointments->availableSlots($serviceId,$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s'),200);
            return ['items'=>$this->presentSlots($items,(string)$service['timezone']),'timezone'=>(string)$service['timezone']];
        }
        if($suffix==='reservations'){$this->method($method,'GET');return ['items'=>$appointments->reservationsForContact($profileId,$contactId,100)];}
        if($suffix==='reservations/hold'){
            $this->method($method,'POST');$this->requireCapability($context,'booking');$body=$this->jsonBody();$conversationId=$this->conversationForContact($profileId,$contactId);
            $key=$this->idempotency($body);$reservation=$appointments->holdSlot($profileId,(int)($body['slot_id']??0),$contactId,$conversationId,$key,[
                'name'=>$body['name']??'','phone'=>$body['phone']??'','email'=>$body['email']??'','notes'=>$body['notes']??'',
                'timezone'=>$body['timezone']??$context['timezone'],
            ]);
            return ['reservation'=>$reservation];
        }
        if($suffix==='reservations/confirm'){
            $this->method($method,'POST');$this->requireCapability($context,'booking');$body=$this->jsonBody();return ['reservation'=>$appointments->confirm((string)($body['reference']??''),$contactId,$this->idempotency($body),false)];
        }
        if(preg_match('#^reservations/(APT-[A-F0-9]{20})/cancel$#',$suffix,$match)===1){
            $this->method($method,'POST');$body=$this->jsonBody();return ['reservation'=>$appointments->cancel($match[1],$contactId,$this->idempotency($body),'customer_cancelled')];
        }
        if($suffix==='handoff'){
            $this->method($method,'POST');$this->requireCapability($context,'handoff');$this->jsonBody();$conversationId=$this->conversationForContact($profileId,$contactId);
            $handoff=(new TelegramAssistantRepository())->requestHandoff($conversationId,'customer_request','');return ['requested'=>true,'handoff_id'=>(int)$handoff['id']];
        }
        throw new TelegramAssistantApiException('not_found','مسیر API پیدا نشد.',404);
    }

    private function dispatchAdminApi(array $context,string $suffix,string $method): array
    {
        $mutation=$method!=='GET';$user=$this->adminIdentity($mutation);$profileId=(int)$context['id'];$appointments=$this->appointments();$admin=$this->adminService();
        if($suffix==='summary'&&$method==='GET')return $this->adminSummary($profileId);
        if($suffix==='conversations'&&$method==='GET')return ['items'=>$admin->conversations($profileId,300)];
        if(preg_match('#^conversations/(\d+)$#',$suffix,$match)===1&&$method==='GET')return ['conversation'=>$admin->conversation($profileId,(int)$match[1])];
        if(preg_match('#^conversations/(\d+)/(takeover|release|close)$#',$suffix,$match)===1){$this->method($method,'POST');$this->jsonBody();$conversation=match($match[2]){'takeover'=>$admin->takeover($profileId,(int)$match[1],(int)$user['id']),'release'=>$admin->release($profileId,(int)$match[1],(int)$user['id']),default=>$admin->close($profileId,(int)$match[1],(int)$user['id'])};return ['conversation'=>$conversation];}
        if(preg_match('#^conversations/(\d+)/reply$#',$suffix,$match)===1){$this->method($method,'POST');$body=$this->jsonBody();return $admin->reply($profileId,(int)$match[1],(int)$user['id'],(string)($body['text']??''),$this->idempotency($body));}
        if($suffix==='reservations'&&$method==='GET')return ['items'=>$appointments->reservationsForProfile($profileId,300)];
        if(preg_match('#^reservations/(APT-[A-F0-9]{20})/reject$#',$suffix,$match)===1){
            $this->method($method,'POST');$body=$this->jsonBody();
            $reason=(string)($body['reason_code']??'operator_rejected');if(!in_array($reason,['operator_rejected','schedule_conflict','service_unavailable','duplicate_request'],true))throw new TelegramAssistantApiException('invalid_reason','دلیل رد درخواست معتبر نیست.',422,['reason_code'=>'invalid']);
            return ['reservation'=>$appointments->operatorRejectForProfile($profileId,$match[1],(int)$user['id'],$reason),'decision'=>'rejected'];
        }
        if(preg_match('#^reservations/(APT-[A-F0-9]{20})/status$#',$suffix,$match)===1){
            $this->method($method,'POST');$body=$this->jsonBody();$status=(string)($body['status']??'');
            $reservation=match($status){'confirmed'=>$appointments->operatorConfirmForProfile($profileId,$match[1],(int)$user['id']),'cancelled'=>$appointments->operatorCancel($profileId,$match[1],(int)$user['id']),'completed'=>$appointments->operatorComplete($profileId,$match[1],(int)$user['id']),default=>throw new TelegramAssistantApiException('invalid_status','وضعیت رزرو معتبر نیست.',422)};
            return ['reservation'=>$reservation];
        }
        if($suffix==='services'&&$method==='GET')return ['items'=>$this->presentServices($appointments->services($profileId,false))];
        if(preg_match('#^services/(\d+)/availability$#',$suffix,$match)===1){
            $serviceId=(int)$match[1];$service=$appointments->service($serviceId,$profileId);
            if($method==='GET')return ['service_id'=>$serviceId,'timezone'=>(string)$service['timezone'],'rules'=>$appointments->availabilityRules($serviceId,$profileId)];
            $this->method($method,'POST');$body=$this->jsonBody();$rules=$body['rules']??null;
            if(!is_array($rules)||!array_is_list($rules))throw new TelegramAssistantApiException('invalid_schedule','برنامهٔ هفتگی معتبر نیست.',422,['rules'=>'invalid']);
            $appointments->replaceAvailabilityRulesForProfile($profileId,$serviceId,$rules);
            return ['service_id'=>$serviceId,'timezone'=>(string)$service['timezone'],'rules'=>$appointments->availabilityRules($serviceId,$profileId)];
        }
        if(preg_match('#^services/(\d+)/slots/generate$#',$suffix,$match)===1){
            $this->method($method,'POST');$serviceId=(int)$match[1];$appointments->service($serviceId,$profileId);$body=$this->jsonBody();
            $result=$appointments->generateSlotsForProfile($profileId,$serviceId,(string)($body['from_date']??''),(string)($body['until_date']??''));
            return ['result'=>$result,'items'=>$this->presentSlots($appointments->slotsForProfile($profileId,500),(string)$context['timezone'])];
        }
        if($suffix==='services'||preg_match('#^services/(\d+)$#',$suffix,$match)===1){
            $this->method($method,'POST');$body=$this->jsonBody();$serviceId=isset($match[1])?(int)$match[1]:null;$existing=$serviceId!==null?$appointments->service($serviceId,$profileId):null;$title=trim((string)($body['title']??''));
            $service=$appointments->saveService($profileId,[
                'slug'=>$existing['slug']??('service-'.substr(hash('sha256',$title),0,16)),'title'=>$title,'summary'=>$body['summary']??'',
                'duration_minutes'=>$body['duration_minutes']??0,'buffer_before_minutes'=>$existing['buffer_before_minutes']??0,'buffer_after_minutes'=>$existing['buffer_after_minutes']??0,
                'mode'=>$existing['mode']??'online','location_label'=>$existing['location_label']??'','price_amount'=>$body['price_amount']??null,
                'price_currency'=>$body['currency']??'RUB','is_enabled'=>!empty($body['is_enabled']),'position'=>$existing['position']??0,
            ],$serviceId);return ['service'=>$this->presentService($service)];
        }
        if($suffix==='slots'&&$method==='GET')return ['items'=>$this->presentSlots($appointments->slotsForProfile($profileId,500),(string)$context['timezone'])];
        if($suffix==='slots'&&$method==='POST'){$body=$this->jsonBody();return ['slot'=>$this->presentSlot($appointments->createSlot($profileId,(int)($body['service_id']??0),(string)($body['start_at']??''),(string)($body['end_at']??'')),(string)$context['timezone'])];}
        if(preg_match('#^slots/(\d+)/block$#',$suffix,$match)===1){$this->method($method,'POST');$this->jsonBody();return ['slot'=>$this->presentSlot($appointments->blockSlot($profileId,(int)$match[1]),(string)$context['timezone'])];}
        $businessService=new TelegramBusinessService(new TelegramAssistantRepository());
        if($suffix==='business-connections'&&$method==='GET')return ['items'=>$businessService->adminConnections($profileId)];
        if(preg_match('#^business-connections/(\d+)/(allow|reject)$#',$suffix,$match)===1){
            $this->method($method,'POST');$reviewed=$businessService->reviewConnection($profileId,(int)$match[1],$match[2]==='allow');
            Audit::log('telegram.business_connection_reviewed','اتصال Telegram Business بررسی شد',(int)$user['id'],[
                'profile_id'=>$profileId,'business_row_id'=>(int)$match[1],'decision'=>$match[2],
                'result'=>(string)$reviewed['authorization_status'],
            ]);
            return ['connection'=>$reviewed];
        }
        if($suffix==='settings'&&$method==='GET')return ['profile'=>$this->presentProfile($context),'settings'=>$this->adminSettings($context),'mini_app_url'=>$this->customerMiniAppUrl($context)];
        if($suffix==='settings'&&$method==='POST'){
            $body=$this->jsonBody();$requestedProfileEnabled=null;
            if(array_key_exists('profile_enabled',$body)){
                if(!is_bool($body['profile_enabled']))throw new TelegramAssistantApiException('invalid_setting','وضعیت فعال‌سازی دستیار باید boolean باشد.',422);
                $requestedProfileEnabled=$body['profile_enabled'];
            }
            $repository=new TelegramAssistantRepository();$businessService=new TelegramBusinessService($repository);$profileEnabled=false;
            $repository->transaction(function()use($repository,$businessService,$context,$body,$requestedProfileEnabled,&$profileEnabled):void{
                $lock=$repository->driver()==='pgsql'?' FOR UPDATE':'';
                $query=$repository->pdo()->prepare('SELECT * FROM telegram_assistant_profiles WHERE id=:id AND connection_id=:connection'.$lock);
                $query->execute(['id'=>$context['id'],'connection'=>$context['connection_id']]);$fresh=$query->fetch();
                if(!is_array($fresh))throw new RuntimeException('پروفایل دستیار پیدا نشد.');
                $settings=json_decode((string)$fresh['settings_json'],true);if(!is_array($settings)||array_is_list($settings))throw new RuntimeException('تنظیمات پروفایل دستیار معتبر نیست.');
                // Merge only browser-editable keys into the locked fresh row;
                // server-only encrypted owner pins must never be overwritten.
                $settings['handoff_unknown_enabled']=!empty($body['handoff_unknown_enabled']);
                $profileEnabled=$requestedProfileEnabled??((int)$fresh['is_enabled']===1);
                $saved=$repository->saveProfile((int)$fresh['connection_id'],[
                    'assistant_name'=>$body['assistant_name']??$fresh['assistant_name'],'locale'=>$fresh['locale'],'timezone'=>$body['timezone']??$fresh['timezone'],
                    'avatar_url'=>$fresh['avatar_url'],'welcome'=>$body['welcome_message']??$repository->profileWelcome($fresh),'settings'=>$settings,
                    'auto_reply_enabled'=>!empty($body['auto_reply_enabled']),'booking_enabled'=>(int)$fresh['booking_enabled']===1,
                    'handoff_enabled'=>(int)$fresh['handoff_enabled']===1,'is_enabled'=>$profileEnabled,
                ]);
                $businessService->setProfileEnabled((int)$saved['id'],$profileEnabled);
            });
            Audit::log('telegram.assistant_settings_updated','تنظیمات دستیار Telegram ذخیره شد',(int)$user['id'],['profile_id'=>$profileId,'profile_enabled'=>$profileEnabled]);
            $fresh=$this->contextByConnection((int)$context['connection_id']);return ['profile'=>$this->presentProfile($fresh),'settings'=>$this->adminSettings($fresh),'business_connections'=>$businessService->adminConnections($profileId),'mini_app_url'=>$this->customerMiniAppUrl($fresh)];
        }
        throw new TelegramAssistantApiException('not_found','مسیر API مدیریت پیدا نشد.',404);
    }

    private function startCustomerSession(array $context,string $initData): array
    {
        $repository=new TelegramAssistantRepository();$businessService=new TelegramBusinessService($repository);
        $query=$repository->pdo()->prepare("SELECT business_connection_id FROM telegram_business_connections WHERE profile_id=:profile AND authorization_status='authorized' AND is_enabled=1 ORDER BY id DESC LIMIT 1");$query->execute(['profile'=>$context['id']]);$opaque=$query->fetchColumn();
        if(!is_string($opaque)||$opaque==='')throw new TelegramAssistantApiException('connection_required','حساب Business هنوز به دستیار متصل نشده است.',503);
        $business=$businessService->requireConnection((int)$context['connection_id'],$opaque,true);$connection=TelegramSyncService::connectionById((int)$context['connection_id']);
        $sessions=new TelegramMiniAppSession($repository);$started=$sessions->start((int)$context['id'],$initData,TelegramSyncService::botToken($connection),300,900);
        try{
            $user=(array)($started['data']['user']??[]);$contact=$repository->upsertContact($business,$user,(string)($user['id']??''));$repository->findOrCreateConversation($business,$contact,(string)($user['id']??''));
            $bound=$sessions->bindContact((string)$started['token'],(int)$contact['id']);
        }catch(Throwable $error){$sessions->revoke((string)$started['token']);throw $error;}
        return ['token'=>$started['token'],'expires_at'=>$started['expires_at'],'profile'=>$this->presentProfile($context)+['customer'=>$bound['data']['user']??[]]];
    }

    private function customerSession(array $context): array
    {
        $header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        if(preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D',$header,$match)!==1)throw new TelegramAssistantApiException('unauthorized','نشست Mini App لازم است.',401);
        $session=(new TelegramMiniAppSession(new TelegramAssistantRepository()))->authenticate($match[1],(int)$context['id']);
        if((int)($session['contact_id']??0)<1)throw new TelegramAssistantApiException('session_expired','نشست مشتری کامل نیست.',401);
        return $session;
    }

    private function adminIdentity(bool $mutation): array
    {
        $user=Auth::user();if(!is_array($user)||!in_array((string)$user['role'],['owner','admin'],true)||!Access::allowed($user,'telegram'))throw new TelegramAssistantApiException('unauthorized','ورود مدیر لازم است.',401);
        if($mutation){
            $given=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
            try{Security::verifyCsrfToken($given);}catch(RuntimeException){throw new TelegramAssistantApiException('csrf_invalid','نشست مدیریت منقضی یا نامعتبر است.',419);}
        }
        return $user;
    }

    private function adminService(): TelegramAssistantAdminService
    {
        $repository=new TelegramAssistantRepository();$business=new TelegramBusinessService($repository);
        return new TelegramAssistantAdminService($repository,$business,new TelegramAssistantOutbox($repository,$business,static fn(array $context):bool=>DeliveryPolicy::telegramAssistantDeliveryEnabled()));
    }
    private function appointments(): AppointmentService{return new AppointmentService(new TelegramAssistantRepository());}

    private function adminSummary(int $profileId): array
    {
        $pdo=Database::connection();$scalar=static function(string$sql,array$params=[])use($pdo):int{$query=$pdo->prepare($sql);$query->execute($params);return(int)$query->fetchColumn();};
        $profileQuery=$pdo->prepare('SELECT booking_enabled,is_enabled FROM telegram_assistant_profiles WHERE id=:profile');$profileQuery->execute(['profile'=>$profileId]);$profileState=$profileQuery->fetch();if(!is_array($profileState))throw new RuntimeException('پروفایل دستیار پیدا نشد.');
        $counts=[
            'unread_conversations'=>$scalar('SELECT COALESCE(SUM(unread_count),0) FROM telegram_assistant_conversations WHERE profile_id=:profile',['profile'=>$profileId]),
            'waiting_handoffs'=>$scalar("SELECT COUNT(*) FROM telegram_assistant_conversations WHERE profile_id=:profile AND mode='handoff_pending'",['profile'=>$profileId]),
            'pending_reservations'=>$scalar("SELECT COUNT(*) FROM telegram_appointment_reservations WHERE profile_id=:profile AND status='pending'",['profile'=>$profileId]),
            'available_slots_next_14_days'=>$scalar("SELECT COUNT(*) FROM telegram_appointment_slots sl JOIN telegram_appointment_services s ON s.id=sl.service_id WHERE s.profile_id=:profile AND s.is_enabled=1 AND sl.status='available' AND sl.starts_at>CURRENT_TIMESTAMP AND sl.starts_at<:until",['profile'=>$profileId,'until'=>gmdate('Y-m-d H:i:s',strtotime('+14 days'))]),
            'pending_business_connections'=>$scalar("SELECT COUNT(*) FROM telegram_business_connections WHERE profile_id=:profile AND authorization_status='pending'",['profile'=>$profileId]),
        ];
        $notices=[];
        if($counts['pending_reservations']>0)$notices[]=['id'=>'pending-reservations','severity'=>'warning','target'=>'reservations','count'=>$counts['pending_reservations']];
        if($counts['waiting_handoffs']>0)$notices[]=['id'=>'waiting-handoffs','severity'=>'warning','target'=>'inbox','count'=>$counts['waiting_handoffs']];
        if((int)$profileState['is_enabled']===1&&(int)$profileState['booking_enabled']===1&&$counts['available_slots_next_14_days']===0)$notices[]=['id'=>'no-slots','severity'=>'warning','target'=>'slots','count'=>0];
        if($counts['pending_business_connections']>0)$notices[]=['id'=>'business-connection','severity'=>'warning','target'=>'settings','count'=>$counts['pending_business_connections']];
        return ['counts'=>$counts,'notices'=>$notices];
    }

    private function contextByKey(string $key): array
    {
        if(preg_match('/^[a-f0-9]{32}$/',$key)!==1)throw new RuntimeException('کلید Mini App معتبر نیست.');
        $query=Database::connection()->prepare('SELECT p.*,c.webhook_key,c.name connection_name,c.bot_username,c.is_enabled connection_enabled FROM telegram_assistant_profiles p JOIN telegram_connections c ON c.id=p.connection_id WHERE c.webhook_key=:key');
        $query->execute(['key'=>$key]);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('پروفایل دستیار پیدا نشد.');
    }
    private function contextByConnection(int $connectionId): array
    {
        $query=Database::connection()->prepare('SELECT p.*,c.webhook_key,c.name connection_name,c.bot_username,c.is_enabled connection_enabled FROM telegram_assistant_profiles p JOIN telegram_connections c ON c.id=p.connection_id WHERE c.id=:connection');
        $query->execute(['connection'=>$connectionId]);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('پروفایل دستیار پیدا نشد.');
    }
    private function firstContext(): ?array{$row=Database::connection()->query('SELECT p.connection_id FROM telegram_assistant_profiles p ORDER BY p.id LIMIT 1')->fetchColumn();if(!$row)return null;return $this->contextByConnection((int)$row);}

    private function presentProfile(array $context): array
    {
        $repository=new TelegramAssistantRepository();$settings=json_decode((string)$context['settings_json'],true);if(!is_array($settings))$settings=[];
        $chatUrl=(string)($settings['chat_url']??'');if(!str_starts_with($chatUrl,'https://t.me/'))$chatUrl='';
        return ['assistant'=>['name'=>(string)$context['assistant_name'],'welcome'=>$repository->profileWelcome($context),'avatar_url'=>(string)$context['avatar_url'],'chat_url'=>$chatUrl],
            'business'=>['name'=>(string)$context['connection_name'],'timezone'=>(string)$context['timezone']],
            'capabilities'=>['booking'=>(int)$context['booking_enabled']===1,'handoff'=>(int)$context['handoff_enabled']===1],
            'settings'=>$this->publicSettings($settings)+['auto_reply_enabled'=>(int)$context['auto_reply_enabled']===1,'profile_enabled'=>(int)$context['is_enabled']===1],
            'timezone'=>(string)$context['timezone']];
    }
    private function publicSettings(array $settings): array{$allowed=['owner_display_name','booking_confirmation','quick_actions','handoff_text','unknown_text','faq','fallback_en','handoff_unknown_enabled'];return array_intersect_key($settings,array_flip($allowed));}
    private function adminSettings(array $context): array{$settings=json_decode((string)$context['settings_json'],true);if(!is_array($settings))$settings=[];$ownerPinned=isset($settings['business_owner_id_encrypted'])&&is_string($settings['business_owner_id_encrypted'])&&$settings['business_owner_id_encrypted']!=='';unset($settings['business_owner_id_encrypted']);return $settings+['assistant_name'=>$context['assistant_name'],'welcome_message'=>(new TelegramAssistantRepository())->profileWelcome($context),'timezone'=>$context['timezone'],'auto_reply_enabled'=>(int)$context['auto_reply_enabled']===1,'profile_enabled'=>(int)$context['is_enabled']===1,'business_owner_pinned'=>$ownerPinned];}

    private function customerMiniAppUrl(array $context): string
    {
        $base=rtrim(trim((string)getenv('APP_URL')),'/');$key=(string)($context['webhook_key']??'');
        if(filter_var($base,FILTER_VALIDATE_URL)===false||!str_starts_with($base,'https://')||preg_match('/^[a-f0-9]{32}$/',$key)!==1)return '';
        return $base.'/telegram/assistant/'.$key;
    }

    private function presentServices(array $rows): array{return array_map(fn(array $row):array=>$this->presentService($row),$rows);}
    private function presentService(array $row): array{return $row+['enabled'=>(int)($row['is_enabled']??0)===1,'currency'=>(string)($row['price_currency']??''),'price'=>['amount'=>$row['price_amount']??null,'currency'=>(string)($row['price_currency']??'')]];}
    private function presentSlots(array $rows,string $timezone): array{return array_map(fn(array $row):array=>$this->presentSlot($row,$timezone),$rows);}
    private function presentSlot(array $row,string $timezone): array{$utc=new DateTimeZone('UTC');$local=new DateTimeZone($timezone);$start=(new DateTimeImmutable((string)$row['starts_at'],$utc))->setTimezone($local);$end=(new DateTimeImmutable((string)$row['ends_at'],$utc))->setTimezone($local);return $row+['start_at'=>(new DateTimeImmutable((string)$row['starts_at'],$utc))->format('Y-m-d\TH:i:s\Z'),'end_at'=>(new DateTimeImmutable((string)$row['ends_at'],$utc))->format('Y-m-d\TH:i:s\Z'),'local_date'=>$start->format('Y-m-d'),'local_time'=>$start->format('H:i'),'local_end_time'=>$end->format('H:i'),'available'=>(string)$row['status']==='available'];}

    private function conversationForContact(int $profileId,int $contactId): int{$query=Database::connection()->prepare('SELECT id FROM telegram_assistant_conversations WHERE profile_id=:profile AND contact_id=:contact ORDER BY id DESC LIMIT 1');$query->execute(['profile'=>$profileId,'contact'=>$contactId]);$id=(int)$query->fetchColumn();if($id<1)throw new RuntimeException('گفت‌وگوی مشتری پیدا نشد.');return $id;}
    private function idempotency(array $body): string{$value=trim((string)($body['idempotency_key']??$_SERVER['HTTP_IDEMPOTENCY_KEY']??''));if(strlen($value)<8||strlen($value)>190||preg_match('/^[A-Za-z0-9._:-]+$/',$value)!==1)throw new TelegramAssistantApiException('invalid_idempotency','کلید idempotency معتبر نیست.',422);return $value;}
    private function requireCapability(array $context,string $capability): void
    {
        $field=match($capability){'booking'=>'booking_enabled','handoff'=>'handoff_enabled',default=>throw new TelegramAssistantApiException('invalid_capability','قابلیت معتبر نیست.',500)};
        if((int)($context[$field]??0)!==1)throw new TelegramAssistantApiException($capability.'_disabled','این قابلیت فعلاً فعال نیست.',503);
    }
    private function method(string $actual,string $expected): void{if($actual!==$expected)throw new TelegramAssistantApiException('method_not_allowed','روش درخواست مجاز نیست.',405);}
    private function jsonBody(): array
    {
        $type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));if($type!=='application/json')throw new TelegramAssistantApiException('json_required','بدنهٔ JSON لازم است.',415);
        $raw=file_get_contents('php://input',false,null,0,65537);if(!is_string($raw)||strlen($raw)>65536)throw new TelegramAssistantApiException('payload_too_large','درخواست بیش از حد بزرگ است.',413);
        $value=json_decode($raw,true,64);if(!is_array($value)||array_is_list($value))throw new TelegramAssistantApiException('invalid_json','JSON معتبر نیست.',400);return $value;
    }
    private function domainError(string $message): array
    {
        if(str_contains($message,'Telegram Web App منقضی')||str_contains($message,'نشست Mini App')||str_contains($message,'توکن نشست')||str_contains($message,'نشست قبلی Mini App'))return [401,'session_expired'];
        if(str_contains($message,'Telegram Web App')||str_contains($message,'دادهٔ Telegram')||str_contains($message,'امضای Telegram')||str_contains($message,'کاربر Telegram'))return [401,'invalid_init_data'];
        if(str_contains($message,'وضعیت درخواست تغییر کرده')||str_contains($message,'منتظر رد یا تأیید نیست')||str_contains($message,'منتظر تأیید نیست'))return [409,'reservation_state_changed'];
        if(str_contains($message,'هم‌زمان')||str_contains($message,'دیگر در دسترس'))return [409,'slot_taken'];
        if(str_contains($message,'قبلاً پردازش'))return [409,'replayed_request'];
        if(str_contains($message,'پیدا نشد'))return [404,'not_found'];
        if(str_contains($message,'غیرفعال')||str_contains($message,'فعال نیست'))return [503,'connection_required'];
        return [422,'validation_error'];
    }
}
