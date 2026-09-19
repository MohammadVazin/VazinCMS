<?php
declare(strict_types=1);

namespace VazinCMS;

use Closure;
use RuntimeException;

/**
 * Offline-capable Business message orchestrator.
 *
 * It contains deterministic FAQ/booking/handoff behavior only. Automatic
 * replies require an explicit automation policy and an explicit rate guard;
 * neither defaults to allow. All delivery is delegated to the network-free
 * TelegramAssistantOutbox.
 */
final class TelegramAssistantService
{
    private bool|Closure|null $automationPolicy;
    private ?Closure $rateGuard;

    public function __construct(
        private TelegramAssistantRepository $repository,
        private TelegramBusinessService $businessService,
        private TelegramAssistantOutbox $outbox,
        bool|callable|null $automationPolicy=null,
        ?callable $rateGuard=null
    ) {
        $this->automationPolicy=is_callable($automationPolicy)?Closure::fromCallable($automationPolicy):$automationPolicy;
        $this->rateGuard=$rateGuard===null?null:Closure::fromCallable($rateGuard);
    }

    public function handleBusinessConnection(int $connectionId,array $businessConnection,bool|callable|null $ownerAuthorizer=null): array
    {
        return $this->businessService->handleConnectionUpdate($connectionId,$businessConnection,$ownerAuthorizer);
    }

    /** Handles one business_message idempotently and never performs network I/O. */
    public function handleBusinessMessage(int $connectionId,array $message,?int $updateId=null): array
    {
        $normalized=$this->businessService->normalizeMessage($connectionId,$message,$updateId,true);
        $business=$normalized['business'];$actor=(string)$normalized['actor'];$chatId=(string)$normalized['telegram_chat_id'];
        // Telegram sets `from` to the owner/bot for outgoing Business messages.
        // Never let that overwrite the customer identity already bound to this chat.
        $contact=$actor==='customer'
            ?$this->repository->upsertContact($business,(array)$normalized['sender'],$chatId)
            :($this->repository->contactByBusinessChat((int)$business['id'],$chatId)
                ??$this->repository->upsertContact($business,['id'=>$chatId],$chatId));
        $conversation=$this->repository->findOrCreateConversation($business,$contact,(string)$normalized['telegram_chat_id']);
        $normalized['conversation_id']=$conversation['id'];
        $stored=$this->repository->storeMessage($normalized);
        if(!$stored['created'])return ['status'=>'duplicate','conversation_id'=>(int)$conversation['id'],'message_id'=>(int)$normalized['telegram_message_id']];

        if($actor==='bot'||$actor==='external_bot')return ['status'=>'ignored','reason'=>'bot_loop_guard','conversation_id'=>(int)$conversation['id']];
        if($actor==='owner'||$actor==='operator'){
            $this->repository->requestHandoff((int)$conversation['id'],'manual_owner_reply','پیام دستی صاحب حساب ثبت شد.');
            $this->repository->setConversationMode((int)$conversation['id'],'human');
            return ['status'=>'human_control','conversation_id'=>(int)$conversation['id']];
        }

        $conversation=$this->repository->conversationById((int)$conversation['id'])??$conversation;
        if(in_array((string)$conversation['mode'],['handoff_pending','human','closed'],true))return ['status'=>'stored','reason'=>'human_or_closed','conversation_id'=>(int)$conversation['id']];
        $text=trim((string)$normalized['text']);$intent=$this->intent($text,$business);
        if($intent['name']==='handoff'){
            $handoff=$this->repository->requestHandoff((int)$conversation['id'],'customer_request',$text);
            $queued=$this->automationAllowed($business,['intent'=>$intent,'conversation'=>$conversation])
                ?$this->queueReply($business,$this->repository->conversationById((int)$conversation['id'])??$conversation,
                    $this->localized((string)$business['locale'],'درخواست شما برای اوکسانا فرستاده شد.','Ваше сообщение передано Оксане.','Your message has been handed to Oksana.'),
                    'handoff-'.(int)$stored['message']['id'],null,true)
                :null;
            return ['status'=>'handoff_requested','handoff_id'=>(int)$handoff['id'],'queued'=>$queued!==null,'conversation_id'=>(int)$conversation['id']];
        }

        if(!$this->rateAllowed(['operation'=>'business_message','profile_id'=>(int)$business['profile_id'],'conversation_id'=>(int)$conversation['id'],'chat_id'=>(string)$conversation['telegram_chat_id']])){
            return ['status'=>'stored','reason'=>'rate_guard_denied','conversation_id'=>(int)$conversation['id']];
        }

        if(!$this->automationAllowed($business,['intent'=>$intent,'conversation'=>$conversation]))return ['status'=>'stored','reason'=>'automation_disabled','intent'=>$intent['name'],'conversation_id'=>(int)$conversation['id']];
        $reply=$this->replyForIntent($intent,$business,$text);
        $queued=$this->queueReply($business,$conversation,$reply['text'],'reply-'.(int)$stored['message']['id'],$reply['reply_markup']??null);
        return ['status'=>$queued===null?'stored':'queued','intent'=>$intent['name'],'queued'=>$queued!==null,'conversation_id'=>(int)$conversation['id'],'outbox'=>$queued];
    }

    /** Updates encrypted stored content without triggering a second assistant reply. */
    public function handleEditedBusinessMessage(int $connectionId,array $message,?int $updateId=null): array
    {
        $normalized=$this->businessService->normalizeMessage($connectionId,$message,$updateId,true);
        $updated=$this->repository->editMessage([
            'connection_id'=>$connectionId,'business_connection_row_id'=>$normalized['business_connection_row_id'],
            'telegram_chat_id'=>$normalized['telegram_chat_id'],'telegram_message_id'=>$normalized['telegram_message_id'],
        ],(string)$normalized['text'],(string)($normalized['edited_at']??$normalized['message_date']));
        return ['status'=>$updated?'updated':'ignored','message_id'=>$normalized['telegram_message_id']];
    }

    public function handleDeletedBusinessMessages(int $connectionId,array $deletion): array
    {
        $business=$this->businessService->requireConnection($connectionId,(string)($deletion['business_connection_id']??''),false);
        $chat=is_array($deletion['chat']??null)?$deletion['chat']:[];$chatId=TelegramAssistantRepository::telegramId((string)($chat['id']??''),true);
        $count=$this->repository->markMessagesDeleted($connectionId,(int)$business['id'],$chatId,is_array($deletion['message_ids']??null)?$deletion['message_ids']:[]);
        return ['status'=>'processed','deleted'=>$count];
    }

    /**
     * Processes callback_data from buttons sent on behalf of the Business user.
     * The caller must separately deliver/answer the queued callback job.
     */
    public function handleCallbackQuery(int $connectionId,array $callback): array
    {
        $callbackId=(string)($callback['id']??'');if(strlen($callbackId)<1||strlen($callbackId)>255)throw new RuntimeException('CallbackQuery معتبر نیست.');
        $message=is_array($callback['message']??null)?$callback['message']:[];$opaque=(string)($message['business_connection_id']??'');
        $business=$this->businessService->requireConnection($connectionId,$opaque,true);$chat=is_array($message['chat']??null)?$message['chat']:[];$chatId=TelegramAssistantRepository::telegramId((string)($chat['id']??''),true);
        $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_assistant_conversations WHERE business_connection_row_id=:business AND telegram_chat_id=:chat');$query->execute(['business'=>$business['id'],'chat'=>$chatId]);$conversation=$query->fetch();
        if(!is_array($conversation))throw new RuntimeException('گفت‌وگوی CallbackQuery پیدا نشد.');
        $data=(string)($callback['data']??'');if(strlen($data)>64)throw new RuntimeException('دادهٔ CallbackQuery بیش از حد مجاز است.');
        $answer=$this->outbox->enqueue($business,$conversation,'callback_answer',['callback_query_id'=>$callbackId,'text'=>''],'callback-'.$callbackId,120);
        return ['status'=>$answer===null?'stored':'queued','callback_data'=>$data,'conversation_id'=>(int)$conversation['id'],'outbox'=>$answer];
    }

    public function claimForOperator(int $conversationId,int $operatorUserId): array
    {
        if($operatorUserId<1)throw new RuntimeException('مدیر معتبر نیست.');
        return $this->repository->setHandoffState($conversationId,'accepted',$operatorUserId);
    }

    public function resolveHandoff(int $conversationId,int $operatorUserId,bool $resumeBot=false): array
    {
        $result=$this->repository->setHandoffState($conversationId,'resolved',$operatorUserId);
        if($resumeBot)$this->repository->setConversationMode($conversationId,'bot');
        return $result+['bot_resumed'=>$resumeBot];
    }

    private function queueReply(array $business,array $conversation,string $text,string $dedupe,?array $replyMarkup=null,bool $handoffAck=false): ?array
    {
        $payload=['text'=>mb_substr($text,0,4096),'reply_to_message_id'=>null,'disable_web_page_preview'=>true];
        if($replyMarkup!==null)$payload['reply_markup']=$replyMarkup;
        if($handoffAck)$payload['handoff_ack']=true;
        return $this->outbox->enqueue($business,$conversation,'send_message',$payload,$dedupe,3600);
    }

    private function automationAllowed(array $business,array $context): bool
    {
        if((int)($business['auto_reply_enabled']??0)!==1)return false;
        if($this->automationPolicy===true)return true;
        return $this->automationPolicy instanceof Closure&&($this->automationPolicy)($context+['business'=>$business])===true;
    }

    private function rateAllowed(array $context): bool
    {
        return $this->rateGuard instanceof Closure&&($this->rateGuard)($context)===true;
    }

    private function intent(string $text,array $business): array
    {
        $value=mb_strtolower(trim($text));
        if($value===''||preg_match('/^(\/start|start|hello|hi|سلام|привет|здравствуйте)\b/u',$value)===1)return ['name'=>'welcome'];
        // Use the Russian name stem so natural inflections such as
        // «с Оксаной» and «для Оксаны» are treated as an explicit handoff.
        if(preg_match('/(اوکسانا|اپراتور|انسان|مدیر|позовите|оператор|оксан|человек|human|operator|oksana)/u',$value)===1)return ['name'=>'handoff'];
        if(preg_match('/(رزرو|نوبت|وقت|запис|при[её]м|консультац|book|appointment|reservation)/u',$value)===1)return ['name'=>'booking'];
        $profile=$this->repository->profileById((int)$business['profile_id']);$settings=json_decode((string)($profile['settings_json']??'{}'),true);$faqs=is_array($settings['faq']??null)?$settings['faq']:[];
        foreach($faqs as$index=>$faq){if(!is_array($faq))continue;$keywords=is_array($faq['keywords']??null)?$faq['keywords']:[];foreach($keywords as$keyword){$keyword=mb_strtolower(trim((string)$keyword));if($keyword!==''&&str_contains($value,$keyword))return ['name'=>'faq','index'=>$index,'answer'=>(string)($faq['answer']??'')];}}
        return ['name'=>'fallback'];
    }

    private function replyForIntent(array $intent,array $business,string $originalText): array
    {
        $locale=(string)$business['locale'];
        if($intent['name']==='faq'&&trim((string)$intent['answer'])!=='')return ['text'=>(string)$intent['answer']];
        if($intent['name']==='booking'){
            if((int)($business['booking_enabled']??0)!==1)return ['text'=>$this->localized($locale,'رزرو آنلاین هنوز فعال نشده است؛ پیام شما برای اوکسانا می‌ماند.','Онлайн-запись пока не активна; ваше сообщение останется для Оксаны.','Online booking is not active yet; your message will remain for Oksana.')];
            $base=rtrim(trim((string)getenv('APP_URL')),'/');$parts=parse_url($base);$key=(string)($business['webhook_key']??'');
            if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||preg_match('/^[a-f0-9]{32}$/',$key)!==1){
                return ['text'=>$this->localized($locale,'لینک امن رزرو هنوز آماده نشده است.','Безопасная ссылка для записи пока не готова.','The secure booking link is not ready yet.')];
            }
            $url=$base.'/telegram/assistant/'.$key;
            $label=$this->localized($locale,'بازکردن رزرو','Открыть запись','Open booking');
            return ['text'=>$this->localized($locale,'برای دیدن خدمات و زمان‌های آزاد، رزرو را باز کنید.','Откройте запись, чтобы выбрать услугу и свободное время.','Open booking to choose a service and available time.'),'reply_markup'=>['inline_keyboard'=>[[['text'=>$label,'web_app'=>['url'=>$url]]]]]];
        }
        if($intent['name']==='welcome'){
            $profile=$this->repository->profileById((int)$business['profile_id']);$welcome=is_array($profile)?$this->repository->profileWelcome($profile):'';
            if($welcome!=='')return ['text'=>$welcome];
            return ['text'=>$this->localized($locale,'سلام، من دستیار اوکسانا هستم. برای رزرو یا ارتباط با اوکسانا همین‌جا بنویسید.','Здравствуйте! Я помощник Оксаны. Напишите, если хотите записаться или передать сообщение Оксане.','Hello! I am Oksana’s assistant. Write here to book or hand a message to Oksana.')];
        }
        return ['text'=>$this->localized($locale,'پیام شما دریافت شد. اگر رزرو می‌خواهید «رزرو» بنویسید؛ برای گفت‌وگو با اوکسانا نام او را بنویسید.','Сообщение получено. Напишите «запись», чтобы выбрать время, или «Оксана», чтобы передать разговор ей.','Message received. Write “book” to choose a time, or “Oksana” to hand the conversation to her.')];
    }

    private function localized(string $locale,string $fa,string $ru,string $en): string{return str_starts_with($locale,'ru')?$ru:(str_starts_with($locale,'en')?$en:$fa);}
}
