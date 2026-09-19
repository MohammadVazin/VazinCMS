<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

/** Tenant-scoped operator operations used by the assistant console. */
final class TelegramAssistantAdminService
{
    public function __construct(
        private TelegramAssistantRepository $repository,
        private TelegramBusinessService $businessService,
        private TelegramAssistantOutbox $outbox
    ) {
    }

    public function conversations(int $profileId,int $limit=200): array
    {
        $this->profile($profileId);$limit=max(1,min(500,$limit));
        $query=$this->repository->pdo()->prepare(
            'SELECT c.id,c.mode,c.assigned_user_id,c.unread_count,c.last_customer_at,c.last_operator_at,c.last_message_at,c.updated_at,'
            . 'ct.first_name,ct.last_name,ct.username,ct.language_code,h.status handoff_status '
            . 'FROM telegram_assistant_conversations c JOIN telegram_assistant_contacts ct ON ct.id=c.contact_id '
            . "LEFT JOIN telegram_assistant_handoffs h ON h.conversation_id=c.id AND h.status IN ('requested','accepted') "
            . "WHERE c.profile_id=:profile ORDER BY CASE WHEN h.status='requested' THEN 0 ELSE 1 END,COALESCE(c.last_message_at,c.created_at) DESC LIMIT $limit"
        );
        $query->execute(['profile'=>$profileId]);$rows=$query->fetchAll();$result=[];
        $last=$this->repository->pdo()->prepare('SELECT * FROM telegram_assistant_messages WHERE conversation_id=:conversation ORDER BY message_date DESC,id DESC LIMIT 1');
        foreach($rows as$row){
            $item=self::presentConversation($row);$last->execute(['conversation'=>$row['id']]);$message=$last->fetch();
            if(is_array($message)){
                $content=[];if($message['deleted_at']===null){try{$content=$this->repository->messageContent($message);}catch(\Throwable){}}
                $item['last_message']=['text'=>mb_substr((string)($content['text']??''),0,240),'actor'=>(string)$message['actor'],'direction'=>(string)$message['direction'],'created_at'=>(string)$message['message_date'],'deleted'=>$message['deleted_at']!==null];
            }
            $result[]=$item;
        }
        return $result;
    }

    public function conversation(int $profileId,int $conversationId,bool $markRead=true): array
    {
        $row=$this->conversationRow($profileId,$conversationId);
        $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_assistant_messages WHERE conversation_id=:conversation ORDER BY message_date,id LIMIT 300');
        $query->execute(['conversation'=>$conversationId]);$messages=[];
        foreach($query->fetchAll() as$message){
            $content=$message['deleted_at']===null?$this->repository->messageContent($message):[];
            $messages[]=[
                'id'=>(int)$message['id'],'actor'=>(string)$message['actor'],'direction'=>(string)$message['direction'],
                'content_type'=>(string)$message['content_type'],'text'=>(string)($content['text']??''),
                'message_date'=>(string)$message['message_date'],'created_at'=>(string)$message['created_at'],
                'edited_at'=>$message['edited_at'],'deleted'=>$message['deleted_at']!==null,
            ];
        }
        if($markRead){$this->repository->pdo()->prepare('UPDATE telegram_assistant_conversations SET unread_count=0 WHERE id=:id AND profile_id=:profile')->execute(['id'=>$conversationId,'profile'=>$profileId]);$row['unread_count']=0;}
        return self::presentConversation($row)+['messages'=>$messages];
    }

    public function takeover(int $profileId,int $conversationId,int $operatorUserId): array
    {
        $this->assertOperator($operatorUserId);$this->conversationRow($profileId,$conversationId);
        $handoff=$this->openHandoff($conversationId);
        if($handoff!==null)$this->repository->setHandoffState($conversationId,'accepted',$operatorUserId);
        else $this->repository->setConversationMode($conversationId,'human',$operatorUserId);
        return $this->conversation($profileId,$conversationId,false);
    }

    public function release(int $profileId,int $conversationId,int $operatorUserId): array
    {
        $this->assertOperator($operatorUserId);$this->conversationRow($profileId,$conversationId);
        if($this->openHandoff($conversationId)!==null)$this->repository->setHandoffState($conversationId,'resolved',$operatorUserId);
        $this->repository->setConversationMode($conversationId,'bot');
        return $this->conversation($profileId,$conversationId,false);
    }

    public function close(int $profileId,int $conversationId,int $operatorUserId): array
    {
        $this->assertOperator($operatorUserId);$this->conversationRow($profileId,$conversationId);
        if($this->openHandoff($conversationId)!==null)$this->repository->setHandoffState($conversationId,'resolved',$operatorUserId);
        $this->repository->setConversationMode($conversationId,'closed');
        return $this->conversation($profileId,$conversationId,false);
    }

    public function reply(int $profileId,int $conversationId,int $operatorUserId,string $text,string $idempotencyKey): array
    {
        $this->assertOperator($operatorUserId);$text=trim($text);
        if($text===''||mb_strlen($text)>4000)throw new RuntimeException('متن پاسخ معتبر نیست.');
        if(strlen($idempotencyKey)<8||strlen($idempotencyKey)>190||preg_match('/^[A-Za-z0-9._:-]+$/',$idempotencyKey)!==1)throw new RuntimeException('کلید idempotency معتبر نیست.');
        $conversation=$this->conversationRow($profileId,$conversationId);
        if((string)$conversation['mode']!=='human'){
            $this->takeover($profileId,$conversationId,$operatorUserId);
            // Enqueue policy evaluates the supplied snapshot. Reload after the
            // scoped takeover so it observes mode=human instead of stale mode.
            $conversation=$this->conversationRow($profileId,$conversationId);
        }
        $business=$this->businessService->requireConnection((int)$conversation['connection_id'],(string)$conversation['business_connection_id'],true);
        $queued=$this->outbox->enqueue($business,$conversation,'send_message',[
            'text'=>$text,'operator_reply'=>true,'disable_web_page_preview'=>true,
        ],'operator-'.$operatorUserId.'-'.$idempotencyKey,3600);
        if($queued===null)throw new RuntimeException('پاسخ خارج از پنجرهٔ مجاز یا هنگام غیرفعال‌بودن ارسال قابل صف‌گذاری نیست.');
        return ['queued'=>(bool)($queued['created']??false),'outbox_id'=>(int)($queued['job']['id']??0),'conversation_id'=>$conversationId];
    }

    private function conversationRow(int $profileId,int $conversationId): array
    {
        $query=$this->repository->pdo()->prepare(
            'SELECT c.*,ct.first_name,ct.last_name,ct.username,ct.language_code,b.connection_id,b.business_connection_id,h.status handoff_status '
            . 'FROM telegram_assistant_conversations c JOIN telegram_assistant_contacts ct ON ct.id=c.contact_id '
            . 'JOIN telegram_business_connections b ON b.id=c.business_connection_row_id '
            . "LEFT JOIN telegram_assistant_handoffs h ON h.conversation_id=c.id AND h.status IN ('requested','accepted') "
            . 'WHERE c.id=:id AND c.profile_id=:profile'
        );
        $query->execute(['id'=>$conversationId,'profile'=>$profileId]);$row=$query->fetch();
        return is_array($row)?$row:throw new RuntimeException('گفت‌وگو پیدا نشد.');
    }

    private function openHandoff(int $conversationId): ?array
    {
        $query=$this->repository->pdo()->prepare("SELECT * FROM telegram_assistant_handoffs WHERE conversation_id=:conversation AND status IN ('requested','accepted') ORDER BY id DESC LIMIT 1");
        $query->execute(['conversation'=>$conversationId]);$row=$query->fetch();return is_array($row)?$row:null;
    }

    private function profile(int $profileId): array
    {
        return $this->repository->profileById($profileId)??throw new RuntimeException('پروفایل دستیار پیدا نشد.');
    }

    private function assertOperator(int $operatorUserId): void
    {
        $query=$this->repository->pdo()->prepare("SELECT 1 FROM users WHERE id=:id AND status='active' AND role IN ('owner','admin')");
        $query->execute(['id'=>$operatorUserId]);if(!$query->fetchColumn())throw new RuntimeException('اپراتور مجاز نیست.');
    }

    private static function presentConversation(array $row): array
    {
        $name=trim((string)($row['first_name']??'').' '.(string)($row['last_name']??''));
        if($name==='')$name=(string)($row['username']??'مشتری Telegram');
        $mode=match((string)$row['mode']){'bot'=>'automated','handoff_pending'=>'waiting_human','closed'=>'conversation_closed',default=>(string)$row['mode']};
        return [
            'id'=>(int)$row['id'],'name'=>$name,'username'=>(string)($row['username']??''),'language_code'=>(string)($row['language_code']??''),
            'mode'=>$mode,'status'=>$mode,'handoff'=>['status'=>(string)$row['mode']==='handoff_pending'?'waiting_human':($row['handoff_status']??null)],
            'assigned_user_id'=>$row['assigned_user_id']!==null?(int)$row['assigned_user_id']:null,
            'unread_count'=>(int)$row['unread_count'],'last_customer_at'=>$row['last_customer_at'],'last_operator_at'=>$row['last_operator_at'],
            'last_message_at'=>$row['last_message_at'],'updated_at'=>$row['updated_at'],
        ];
    }
}
