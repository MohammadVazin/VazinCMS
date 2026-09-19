<?php
declare(strict_types=1);

namespace VazinCMS;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/** Transactional, idempotent appointment scheduling for Telegram Assistant. */
final class AppointmentService
{
    public function __construct(private TelegramAssistantRepository $repository)
    {
    }

    /** Creates or edits a service and returns its row. */
    public function saveService(int $profileId,array $input,?int $serviceId=null): array
    {
        $profile=$this->repository->profileById($profileId)??throw new RuntimeException('پروفایل رزرو پیدا نشد.');
        $slug=strtolower(trim((string)($input['slug']??'')));$title=trim((string)($input['title']??''));
        $duration=(int)($input['duration_minutes']??0);$before=(int)($input['buffer_before_minutes']??0);$after=(int)($input['buffer_after_minutes']??0);
        $mode=(string)($input['mode']??'online');$currency=strtoupper(trim((string)($input['price_currency']??'')));
        $amount=$input['price_amount']??null;$amount=$amount===''?null:$amount;
        if(preg_match('/^[a-z0-9][a-z0-9-]{1,119}$/',$slug)!==1)throw new RuntimeException('شناسهٔ خدمت معتبر نیست.');
        if($title===''||mb_strlen($title)>190)throw new RuntimeException('عنوان خدمت معتبر نیست.');
        if($duration<5||$duration>1440||$before<0||$before>1440||$after<0||$after>1440)throw new RuntimeException('مدت خدمت یا فاصله‌ها معتبر نیست.');
        if(!in_array($mode,['online','in_person','both'],true))throw new RuntimeException('نوع ارائهٔ خدمت معتبر نیست.');
        if($currency!==''&&preg_match('/^[A-Z0-9]{3,12}$/',$currency)!==1)throw new RuntimeException('واحد پول معتبر نیست.');
        if($amount!==null&&(!is_numeric($amount)||(float)$amount<0))throw new RuntimeException('مبلغ خدمت معتبر نیست.');
        $values=[
            'profile'=>$profileId,'slug'=>$slug,'title'=>$title,'summary'=>mb_substr(trim((string)($input['summary']??'')),0,4000),
            'duration'=>$duration,'before'=>$before,'after'=>$after,'mode'=>$mode,
            'location'=>mb_substr(trim((string)($input['location_label']??'')),0,255),'amount'=>$amount,'currency'=>$currency,
            'enabled'=>array_key_exists('is_enabled',$input)?(!empty($input['is_enabled'])?1:0):1,'position'=>(int)($input['position']??0),
        ];
        if($serviceId===null){
            $statement=$this->repository->pdo()->prepare('INSERT INTO telegram_appointment_services(profile_id,slug,title,summary,duration_minutes,buffer_before_minutes,buffer_after_minutes,mode,location_label,price_amount,price_currency,is_enabled,position) VALUES(:profile,:slug,:title,:summary,:duration,:before,:after,:mode,:location,:amount,:currency,:enabled,:position)');
            $statement->execute($values);$serviceId=(int)$this->repository->pdo()->lastInsertId();
        }else{
            $values['id']=$serviceId;
            $statement=$this->repository->pdo()->prepare('UPDATE telegram_appointment_services SET slug=:slug,title=:title,summary=:summary,duration_minutes=:duration,buffer_before_minutes=:before,buffer_after_minutes=:after,mode=:mode,location_label=:location,price_amount=:amount,price_currency=:currency,is_enabled=:enabled,position=:position,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND profile_id=:profile');
            $statement->execute($values);if($statement->rowCount()!==1)throw new RuntimeException('خدمت رزرو پیدا نشد.');
        }
        return $this->service($serviceId,$profileId);
    }

    public function services(int $profileId,bool $enabledOnly=true): array
    {
        $sql='SELECT * FROM telegram_appointment_services WHERE profile_id=:profile'.($enabledOnly?' AND is_enabled=1':'').' ORDER BY position,title,id';
        $query=$this->repository->pdo()->prepare($sql);$query->execute(['profile'=>$profileId]);return $query->fetchAll();
    }

    public function service(int $serviceId,?int $profileId=null): array
    {
        $sql='SELECT s.*,p.timezone,p.booking_enabled,p.is_enabled profile_enabled FROM telegram_appointment_services s JOIN telegram_assistant_profiles p ON p.id=s.profile_id WHERE s.id=:id';
        $params=['id'=>$serviceId];if($profileId!==null){$sql.=' AND s.profile_id=:profile';$params['profile']=$profileId;}
        $query=$this->repository->pdo()->prepare($sql);$query->execute($params);$row=$query->fetch();
        return is_array($row)?$row:throw new RuntimeException('خدمت رزرو پیدا نشد.');
    }

    /** Replaces weekly availability atomically. Weekday follows ISO: 1=Mon ... 7=Sun input, stored as 0=Sun ... 6=Sat. */
    public function replaceAvailabilityRules(int $serviceId,array $rules): array
    {
        return $this->repository->transaction(function()use($serviceId,$rules):array{
            $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE':'';$serviceQuery=$this->repository->pdo()->prepare('SELECT * FROM telegram_appointment_services WHERE id=:id'.$lock);$serviceQuery->execute(['id'=>$serviceId]);$service=$serviceQuery->fetch();
            if(!is_array($service))throw new RuntimeException('خدمت رزرو پیدا نشد.');$normalized=$this->normalizeAvailabilityRules($serviceId,$service,$rules);
            $this->repository->pdo()->prepare('DELETE FROM telegram_appointment_availability_rules WHERE service_id=:service')->execute(['service'=>$serviceId]);
            $insert=$this->repository->pdo()->prepare('INSERT INTO telegram_appointment_availability_rules(service_id,weekday,start_time,end_time,slot_interval_minutes,effective_from,effective_until,is_enabled) VALUES(:service,:weekday,:start,:end,:interval,:from,:until,:enabled)');
            foreach($normalized as$row)$insert->execute($row);
            return $normalized;
        });
    }

    private function normalizeAvailabilityRules(int$serviceId,array$service,array$rules): array
    {
        $normalized=[];if(count($rules)>28)throw new RuntimeException('حداکثر ۲۸ بازهٔ هفتگی قابل ثبت است.');
        foreach($rules as$rule){
            if(!is_array($rule))throw new RuntimeException('قاعدهٔ زمان‌بندی معتبر نیست.');
            $weekday=(int)($rule['weekday']??-1);if($weekday<1||$weekday>7)throw new RuntimeException('روز هفته باید بین ۱ تا ۷ باشد.');if($weekday===7)$weekday=0;
            $start=(string)($rule['start_time']??'');$end=(string)($rule['end_time']??'');$interval=(int)($rule['slot_interval_minutes']??$service['duration_minutes']);
            $occupied=(int)$service['buffer_before_minutes']+(int)$service['duration_minutes']+(int)$service['buffer_after_minutes'];
            if(!self::clock($start)||!self::clock($end)||$start>=$end||$interval<$occupied||$interval>1440||self::clockMinutes($end)-self::clockMinutes($start)<$occupied)throw new RuntimeException('قاعدهٔ دسترس‌پذیری با مدت خدمت و فاصله‌ها سازگار نیست.');
            $from=self::dateOrNull($rule['effective_from']??null);$until=self::dateOrNull($rule['effective_until']??null);
            if($from!==null&&$until!==null&&$from>$until)throw new RuntimeException('بازهٔ قاعدهٔ دسترس‌پذیری معتبر نیست.');
            $normalized[]=['service'=>$serviceId,'weekday'=>$weekday,'start'=>$start,'end'=>$end,'interval'=>$interval,'from'=>$from,'until'=>$until,'enabled'=>array_key_exists('is_enabled',$rule)?(!empty($rule['is_enabled'])?1:0):1];
        }
        foreach($normalized as$index=>$left){
            if(!$left['enabled'])continue;
            foreach(array_slice($normalized,$index+1)as$right){
                if(!$right['enabled']||$left['weekday']!==$right['weekday']||!self::dateRangesOverlap($left['from'],$left['until'],$right['from'],$right['until']))continue;
                if($left['start']<$right['end']&&$left['end']>$right['start'])throw new RuntimeException('بازه‌های هفتگی هم‌پوشان هستند.');
            }
        }
        return$normalized;
    }

    /** Returns tenant-scoped weekly rules using ISO weekdays: 1=Mon ... 7=Sun. */
    public function availabilityRules(int $serviceId,?int $profileId=null): array
    {
        $this->service($serviceId,$profileId);
        $query=$this->repository->pdo()->prepare('SELECT weekday,start_time,end_time,slot_interval_minutes,effective_from,effective_until,is_enabled FROM telegram_appointment_availability_rules WHERE service_id=:service ORDER BY weekday,start_time,id');
        $query->execute(['service'=>$serviceId]);
        return array_map(static function(array$row):array{
            $weekday=(int)$row['weekday'];
            return [
                'weekday'=>$weekday===0?7:$weekday,
                'start_time'=>(string)$row['start_time'],
                'end_time'=>(string)$row['end_time'],
                'slot_interval_minutes'=>(int)$row['slot_interval_minutes'],
                'effective_from'=>$row['effective_from']!==null?(string)$row['effective_from']:null,
                'effective_until'=>$row['effective_until']!==null?(string)$row['effective_until']:null,
                'is_enabled'=>(int)$row['is_enabled']===1,
            ];
        },$query->fetchAll());
    }

    public function replaceAvailabilityRulesForProfile(int $profileId,int $serviceId,array $rules): array
    {
        $this->service($serviceId,$profileId);$this->replaceAvailabilityRules($serviceId,$rules);
        return $this->availabilityRules($serviceId,$profileId);
    }

    public function addAvailabilityException(int $serviceId,string $startsAt,string $endsAt,string $kind='unavailable',string $note=''): int
    {
        $service=$this->service($serviceId);$start=self::utcDateTime($startsAt,(string)$service['timezone']);$end=self::utcDateTime($endsAt,(string)$service['timezone']);
        if($end<=$start||!in_array($kind,['unavailable','available'],true))throw new RuntimeException('استثنای زمان‌بندی معتبر نیست.');
        $statement=$this->repository->pdo()->prepare('INSERT INTO telegram_appointment_availability_exceptions(service_id,starts_at,ends_at,kind,note) VALUES(:service,:start,:end,:kind,:note)');
        $statement->execute(['service'=>$serviceId,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),'kind'=>$kind,'note'=>mb_substr(trim($note),0,255)]);
        return (int)$this->repository->pdo()->lastInsertId();
    }

    /** Materializes additive, idempotent UTC slots for at most 90 calendar days. */
    public function generateSlots(int $serviceId,string $fromDate,string $untilDate): array
    {
        return $this->generateSlotsScoped(null,$serviceId,$fromDate,$untilDate);
    }

    public function generateSlotsForProfile(int $profileId,int $serviceId,string $fromDate,string $untilDate): array
    {
        return $this->generateSlotsScoped($profileId,$serviceId,$fromDate,$untilDate);
    }

    private function generateSlotsScoped(?int $profileId,int $serviceId,string $fromDate,string $untilDate): array
    {
        return $this->repository->transaction(function()use($profileId,$serviceId,$fromDate,$untilDate):array{
            $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE OF s':'';
            $sql='SELECT s.*,p.timezone FROM telegram_appointment_services s JOIN telegram_assistant_profiles p ON p.id=s.profile_id WHERE s.id=:id';
            $params=['id'=>$serviceId];if($profileId!==null){$sql.=' AND s.profile_id=:profile';$params['profile']=$profileId;}$sql.=$lock;
            $serviceQuery=$this->repository->pdo()->prepare($sql);$serviceQuery->execute($params);$service=$serviceQuery->fetch();
            if(!is_array($service))throw new RuntimeException('خدمت رزرو پیدا نشد.');
            $timezone=new DateTimeZone((string)$service['timezone']);$utc=new DateTimeZone('UTC');
            $from=self::localDate($fromDate,$timezone);$until=self::localDate($untilDate,$timezone);$today=new DateTimeImmutable('today',$timezone);
            if($from<$today||$until<$from||$from->diff($until)->days>89)throw new RuntimeException('بازهٔ تولید نوبت باید از امروز و حداکثر ۹۰ روز باشد.');
            $rangeStart=$from->setTimezone($utc);$rangeEnd=$until->modify('+1 day')->setTimezone($utc);
            $ruleQuery=$this->repository->pdo()->prepare('SELECT * FROM telegram_appointment_availability_rules WHERE service_id=:service AND is_enabled=1 ORDER BY weekday,start_time,id');$ruleQuery->execute(['service'=>$serviceId]);$rules=$ruleQuery->fetchAll();
            $exceptionQuery=$this->repository->pdo()->prepare('SELECT * FROM telegram_appointment_availability_exceptions WHERE service_id=:service AND ends_at>:from AND starts_at<:until ORDER BY starts_at,id');
            $exceptionQuery->execute(['service'=>$serviceId,'from'=>$rangeStart->format('Y-m-d H:i:s'),'until'=>$rangeEnd->format('Y-m-d H:i:s')]);$exceptions=$exceptionQuery->fetchAll();
            if($rules===[]&&!array_filter($exceptions,static fn(array$row):bool=>(string)$row['kind']==='available'))throw new RuntimeException('برای این خدمت برنامهٔ فعالی ثبت نشده است.');
            $insert=$this->repository->pdo()->prepare("INSERT INTO telegram_appointment_slots(service_id,starts_at,ends_at,status) VALUES(:service,:start,:end,'available') ON CONFLICT(service_id,starts_at) DO NOTHING");
            $overlap=$this->repository->pdo()->prepare('SELECT starts_at,ends_at FROM telegram_appointment_slots WHERE service_id=:service AND starts_at<:end AND ends_at>:start ORDER BY starts_at LIMIT 1');
            $created=0;$existing=0;$skipped=0;$candidates=0;$now=time();
            $duration=(int)$service['duration_minutes'];$before=(int)$service['buffer_before_minutes'];$after=(int)$service['buffer_after_minutes'];
            $materialize=function(DateTimeImmutable$windowStart,DateTimeImmutable$windowEnd,int$interval)use($insert,$overlap,$serviceId,$duration,$before,$after,$utc,$exceptions,$now,&$created,&$existing,&$skipped,&$candidates):void{
                $cursor=$windowStart->modify('+'.$before.' minutes');
                while($cursor->modify('+'.($duration+$after).' minutes')<=$windowEnd){
                    if(++$candidates>5000)throw new RuntimeException('تعداد زمان‌های قابل تولید بیش از حد مجاز است.');
                    $appointmentStart=$cursor;$appointmentEnd=$cursor->modify('+'.$duration.' minutes');
                    $occupiedStart=$appointmentStart->modify('-'.$before.' minutes')->setTimezone($utc);$occupiedEnd=$appointmentEnd->modify('+'.$after.' minutes')->setTimezone($utc);
                    $utcStart=$appointmentStart->setTimezone($utc);$utcEnd=$appointmentEnd->setTimezone($utc);
                    if($utcStart->getTimestamp()<=$now||self::blockedByException($occupiedStart,$occupiedEnd,$exceptions)){$skipped++;$cursor=$cursor->modify('+'.$interval.' minutes');continue;}
                    $startValue=$utcStart->format('Y-m-d H:i:s');$endValue=$utcEnd->format('Y-m-d H:i:s');
                    $conflictStart=$occupiedStart->modify('-'.$after.' minutes')->format('Y-m-d H:i:s');$conflictEnd=$occupiedEnd->modify('+'.$before.' minutes')->format('Y-m-d H:i:s');
                    $overlap->execute(['service'=>$serviceId,'start'=>$conflictStart,'end'=>$conflictEnd]);$conflict=$overlap->fetch();
                    if(is_array($conflict)){if((string)$conflict['starts_at']===$startValue&&(string)$conflict['ends_at']===$endValue)$existing++;else$skipped++;$cursor=$cursor->modify('+'.$interval.' minutes');continue;}
                    $insert->execute(['service'=>$serviceId,'start'=>$startValue,'end'=>$endValue]);
                    $insert->rowCount()===1?$created++:$existing++;$cursor=$cursor->modify('+'.$interval.' minutes');
                }
            };
            for($day=$from;$day<=$until;$day=$day->modify('+1 day')){
                $weekday=(int)$day->format('w');$date=$day->format('Y-m-d');
                foreach($rules as$rule){
                    if((int)$rule['weekday']!==$weekday||($rule['effective_from']&&$date<(string)$rule['effective_from'])||($rule['effective_until']&&$date>(string)$rule['effective_until']))continue;
                    $startClock=substr((string)$rule['start_time'],0,5);$endClock=substr((string)$rule['end_time'],0,5);
                    $materialize(new DateTimeImmutable($date.' '.$startClock.':00',$timezone),new DateTimeImmutable($date.' '.$endClock.':00',$timezone),(int)$rule['slot_interval_minutes']);
                }
            }
            $defaultInterval=max(5,$before+$duration+$after);
            foreach($exceptions as$exception){
                if((string)$exception['kind']!=='available')continue;
                $start=(new DateTimeImmutable((string)$exception['starts_at'],$utc));$end=(new DateTimeImmutable((string)$exception['ends_at'],$utc));
                if($start<$rangeStart)$start=$rangeStart;if($end>$rangeEnd)$end=$rangeEnd;
                if($end>$start)$materialize($start->setTimezone($timezone),$end->setTimezone($timezone),$defaultInterval);
            }
            return ['created'=>$created,'existing'=>$existing,'skipped'=>$skipped,'from_date'=>$from->format('Y-m-d'),'until_date'=>$until->format('Y-m-d')];
        });
    }

    public function availableSlots(int $serviceId,string $from,string $until,int $limit=200): array
    {
        $this->expireHolds(200);$limit=max(1,min(500,$limit));
        $query=$this->repository->pdo()->prepare("SELECT * FROM telegram_appointment_slots WHERE service_id=:service AND status='available' AND starts_at>=:from AND starts_at<:until AND starts_at>CURRENT_TIMESTAMP ORDER BY starts_at LIMIT $limit");
        $query->execute(['service'=>$serviceId,'from'=>self::utcDateTime($from,'UTC')->format('Y-m-d H:i:s'),'until'=>self::utcDateTime($until,'UTC')->format('Y-m-d H:i:s')]);return $query->fetchAll();
    }

    /**
     * Holds exactly one materialized slot. Atomic status transition makes one
     * caller the winner; a profile-scoped idempotency key returns its prior hold.
     */
    public function holdSlot(int $profileId,int $slotId,?int $contactId,?int $conversationId,string $idempotencyKey,array $customer=[],int $holdSeconds=600): array
    {
        self::idempotency($idempotencyKey);$idempotencyHash=hash('sha256',$idempotencyKey);$holdSeconds=max(60,min(1800,$holdSeconds));
        $timezone=trim((string)($customer['timezone']??'UTC'));try{new DateTimeZone($timezone);}catch(Throwable){throw new RuntimeException('منطقهٔ زمانی مشتری معتبر نیست.');}
        $notes=mb_substr(trim((string)($customer['notes']??'')),0,500);
        $customer=self::customerData($customer);
        return $this->repository->transaction(function()use($profileId,$slotId,$contactId,$conversationId,$idempotencyHash,$holdSeconds,$timezone,$customer,$notes):array{
            $prior=$this->findByIdempotency($profileId,$idempotencyHash);if($prior!==null)return $this->present($prior);
            $this->expireSlotIfNeeded($slotId);
            $query=$this->repository->pdo()->prepare('SELECT sl.*,s.profile_id,s.is_enabled service_enabled,p.booking_enabled,p.is_enabled profile_enabled FROM telegram_appointment_slots sl JOIN telegram_appointment_services s ON s.id=sl.service_id JOIN telegram_assistant_profiles p ON p.id=s.profile_id WHERE sl.id=:slot AND s.profile_id=:profile');
            $query->execute(['slot'=>$slotId,'profile'=>$profileId]);$slot=$query->fetch();
            if(!is_array($slot)||(int)$slot['service_enabled']!==1||(int)$slot['booking_enabled']!==1||(int)$slot['profile_enabled']!==1)throw new RuntimeException('نوبت رزرو در دسترس نیست.');
            if((string)$slot['status']!=='available'||TelegramAssistantRepository::timestamp((string)$slot['starts_at'])<=time())throw new RuntimeException('این نوبت دیگر در دسترس نیست.');
            if($contactId!==null)$this->assertContact($profileId,$contactId);
            if($conversationId!==null)$this->assertConversation($profileId,$conversationId,$contactId);
            $expires=TelegramAssistantRepository::utc('+'.$holdSeconds.' seconds');
            $claim=$this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='held',hold_expires_at=:expires,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='available'");
            $claim->execute(['expires'=>$expires,'id'=>$slotId]);if($claim->rowCount()!==1)throw new RuntimeException('این نوبت هم‌زمان توسط شخص دیگری انتخاب شد.');
            $reference=self::reference();$customerEncrypted=SecretStore::seal(TelegramAssistantRepository::json($customer),self::reservationContext($reference));
            $notesEncrypted=$notes===''?null:SecretStore::seal($notes,self::reservationNotesContext($reference));
            $insert=$this->repository->pdo()->prepare('INSERT INTO telegram_appointment_reservations(profile_id,service_id,slot_id,contact_id,conversation_id,public_ref,status,customer_timezone,customer_encrypted,notes_encrypted,idempotency_key,hold_expires_at) VALUES(:profile,:service,:slot,:contact,:conversation,:ref,\'held\',:timezone,:customer,:notes,:idempotency,:expires)');
            $insert->execute(['profile'=>$profileId,'service'=>$slot['service_id'],'slot'=>$slotId,'contact'=>$contactId,'conversation'=>$conversationId,'ref'=>$reference,'timezone'=>$timezone,'customer'=>$customerEncrypted,'notes'=>$notesEncrypted,'idempotency'=>$idempotencyHash,'expires'=>$expires]);
            $reservationId=(int)$this->repository->pdo()->lastInsertId();$this->event($reservationId,'held','customer','',['hold_expires_at'=>$expires]);
            return $this->present($this->reservationRow($reservationId));
        });
    }

    /** Submits a hold for manual approval; auto-confirm is an explicit opt-in. */
    public function confirm(string $reference,?int $contactId,string $idempotencyKey,bool $autoConfirm=false): array
    {
        self::publicRef($reference);self::idempotency($idempotencyKey);$key=hash('sha256',$idempotencyKey);
        return $this->repository->transaction(function()use($reference,$contactId,$key,$autoConfirm):array{
            $row=$this->reservationByReference($reference,$contactId,true);
            if(in_array((string)$row['status'],['pending','confirmed'],true))return $this->present($row);
            if((string)$row['status']!=='held')throw new RuntimeException('این رزرو قابل تأیید نیست.');
            if(TelegramAssistantRepository::timestamp((string)$row['hold_expires_at'])<time()){
                $this->expireReservation($row);throw new RuntimeException('مهلت نگهداری نوبت تمام شده است.');
            }
            $slot=$this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='booked',hold_expires_at=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='held'");$slot->execute(['id'=>$row['slot_id']]);
            if($slot->rowCount()!==1)throw new RuntimeException('نوبت قابل تأیید نیست.');
            $status=$autoConfirm?'confirmed':'pending';$confirmed=$autoConfirm?'CURRENT_TIMESTAMP':'NULL';
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status=:status,confirmation_key=:key,confirmed_at=$confirmed,hold_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='held'")
                ->execute(['status'=>$status,'key'=>$key,'id'=>$row['id']]);$this->event((int)$row['id'],$autoConfirm?'confirmed':'submitted','customer','',['approval_mode'=>$autoConfirm?'automatic':'manual']);
            $updated=$this->reservationRow((int)$row['id']);
            if(!$autoConfirm)$this->notifyOwnerOfPendingReservation($updated);
            return $this->present($updated);
        });
    }

    /** Operator-only approval of a pending request. */
    public function operatorConfirm(string $reference,int $operatorUserId): array
    {
        self::publicRef($reference);$this->assertOperator($operatorUserId);
        return $this->repository->transaction(function()use($reference,$operatorUserId):array{
            $row=$this->reservationByReference($reference,null,true);if((string)$row['status']==='confirmed')return $this->present($row);
            if((string)$row['status']!=='pending')throw new RuntimeException('درخواست منتظر تأیید نیست.');
            $update=$this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='confirmed',confirmed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='pending'");$update->execute(['id'=>$row['id']]);
            if($update->rowCount()!==1)throw new RuntimeException('وضعیت درخواست تغییر کرده است.');
            $this->event((int)$row['id'],'confirmed','operator',(string)$operatorUserId,[]);$this->resolveOwnerPendingNotifications((int)$row['id']);return $this->present($this->reservationRow((int)$row['id']));
        });
    }

    public function operatorConfirmForProfile(int $profileId,string $reference,int $operatorUserId): array
    {
        $this->reservationForProfile($profileId,$reference);return $this->operatorConfirm($reference,$operatorUserId);
    }

    /** Operator rejection frees the slot and records a non-sensitive reason code. */
    public function operatorReject(string $reference,int $operatorUserId,string $reasonCode='operator_rejected'): array
    {
        self::publicRef($reference);$this->assertOperator($operatorUserId);$reasonCode=preg_match('/^[a-z][a-z0-9_]{2,59}$/',$reasonCode)===1?$reasonCode:'operator_rejected';
        return $this->repository->transaction(function()use($reference,$operatorUserId,$reasonCode):array{
            $row=$this->reservationByReference($reference,null,true);
            if((string)$row['status']==='cancelled'){
                $event=$this->repository->pdo()->prepare("SELECT 1 FROM telegram_appointment_events WHERE reservation_id=:reservation AND event_type='rejected' LIMIT 1");$event->execute(['reservation'=>$row['id']]);
                if($event->fetchColumn())return $this->present($row);throw new RuntimeException('وضعیت درخواست تغییر کرده است.');
            }
            if((string)$row['status']!=='pending')throw new RuntimeException('درخواست منتظر رد یا تأیید نیست.');
            $update=$this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='pending'");$update->execute(['id'=>$row['id']]);
            if($update->rowCount()!==1)throw new RuntimeException('وضعیت درخواست تغییر کرده است.');
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='available',hold_expires_at=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='booked'")->execute(['id'=>$row['slot_id']]);
            $this->event((int)$row['id'],'rejected','operator',(string)$operatorUserId,['reason_code'=>$reasonCode]);$this->resolveOwnerPendingNotifications((int)$row['id']);return $this->present($this->reservationRow((int)$row['id']));
        });
    }

    public function operatorRejectForProfile(int $profileId,string $reference,int $operatorUserId,string $reasonCode='operator_rejected'): array
    {
        $this->reservationForProfile($profileId,$reference);return $this->operatorReject($reference,$operatorUserId,$reasonCode);
    }

    public function operatorCancel(int $profileId,string $reference,int $operatorUserId,string $reasonCode='operator_cancelled'): array
    {
        self::publicRef($reference);$this->assertOperator($operatorUserId);$reasonCode=preg_match('/^[a-z][a-z0-9_]{2,59}$/',$reasonCode)===1?$reasonCode:'operator_cancelled';
        return $this->repository->transaction(function()use($profileId,$reference,$operatorUserId,$reasonCode):array{
            $row=$this->reservationForProfile($profileId,$reference,true);if((string)$row['status']==='cancelled')return $this->present($row);
            if(!in_array((string)$row['status'],['held','pending','confirmed'],true))throw new RuntimeException('این رزرو قابل لغو نیست.');
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,hold_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id'=>$row['id']]);
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='available',hold_expires_at=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status IN ('held','booked')")->execute(['id'=>$row['slot_id']]);
            $this->event((int)$row['id'],'cancelled','operator',(string)$operatorUserId,['reason_code'=>$reasonCode]);$this->resolveOwnerPendingNotifications((int)$row['id']);return $this->present($this->reservationRow((int)$row['id']));
        });
    }

    public function operatorComplete(int $profileId,string $reference,int $operatorUserId): array
    {
        self::publicRef($reference);$this->assertOperator($operatorUserId);
        return $this->repository->transaction(function()use($profileId,$reference,$operatorUserId):array{
            $row=$this->reservationForProfile($profileId,$reference,true);if((string)$row['status']==='completed')return $this->present($row);
            if((string)$row['status']!=='confirmed')throw new RuntimeException('فقط رزرو تأییدشده قابل تکمیل است.');
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='completed',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='confirmed'")->execute(['id'=>$row['id']]);
            $this->event((int)$row['id'],'completed','operator',(string)$operatorUserId,[]);$this->resolveOwnerPendingNotifications((int)$row['id']);return $this->present($this->reservationRow((int)$row['id']));
        });
    }

    /** Cancels a hold or confirmed appointment and makes the slot reusable. */
    public function cancel(string $reference,?int $contactId,string $idempotencyKey,string $reason=''): array
    {
        self::publicRef($reference);self::idempotency($idempotencyKey);$key=hash('sha256',$idempotencyKey);
        return $this->repository->transaction(function()use($reference,$contactId,$key,$reason):array{
            $row=$this->reservationByReference($reference,$contactId,true);if((string)$row['status']==='cancelled')return $this->present($row);
            if(!in_array((string)$row['status'],['held','pending','confirmed'],true))throw new RuntimeException('این رزرو قابل لغو نیست.');
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='cancelled',cancellation_key=:key,cancelled_at=CURRENT_TIMESTAMP,hold_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['key'=>$key,'id'=>$row['id']]);
            $this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='available',hold_expires_at=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status IN ('held','booked')")
                ->execute(['id'=>$row['slot_id']]);$this->event((int)$row['id'],'cancelled','customer','',['reason_code'=>mb_substr(preg_replace('/[^a-z0-9_-]/i','_',trim($reason))??'',0,60)]);
            $this->resolveOwnerPendingNotifications((int)$row['id']);
            return $this->present($this->reservationRow((int)$row['id']));
        });
    }

    public function reservationsForContact(int $profileId,int $contactId,int $limit=50): array
    {
        $limit=max(1,min(200,$limit));$query=$this->repository->pdo()->prepare("SELECT * FROM telegram_appointment_reservations WHERE profile_id=:profile AND contact_id=:contact ORDER BY created_at DESC LIMIT $limit");$query->execute(['profile'=>$profileId,'contact'=>$contactId]);
        return array_map(fn(array$row):array=>$this->present($row),$query->fetchAll());
    }

    /** Tenant-scoped reservation list for the operator console. */
    public function reservationsForProfile(int $profileId,int $limit=200): array
    {
        if($this->repository->profileById($profileId)===null)throw new RuntimeException('پروفایل رزرو پیدا نشد.');
        $limit=max(1,min(500,$limit));$query=$this->repository->pdo()->prepare("SELECT * FROM telegram_appointment_reservations WHERE profile_id=:profile ORDER BY created_at DESC LIMIT $limit");
        $query->execute(['profile'=>$profileId]);return array_map(fn(array$row):array=>$this->present($row),$query->fetchAll());
    }

    /** Creates one explicit slot from local business time. */
    public function createSlot(int $profileId,int $serviceId,string $startsAt,string $endsAt): array
    {
        return $this->repository->transaction(function()use($profileId,$serviceId,$startsAt,$endsAt):array{
            $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE OF s':'';
            $serviceQuery=$this->repository->pdo()->prepare('SELECT s.*,p.timezone FROM telegram_appointment_services s JOIN telegram_assistant_profiles p ON p.id=s.profile_id WHERE s.id=:id AND s.profile_id=:profile'.$lock);
            $serviceQuery->execute(['id'=>$serviceId,'profile'=>$profileId]);$service=$serviceQuery->fetch();if(!is_array($service))throw new RuntimeException('خدمت رزرو پیدا نشد.');
            $start=self::utcDateTime($startsAt,(string)$service['timezone']);$end=self::utcDateTime($endsAt,(string)$service['timezone']);
            $duration=(int)round(($end->getTimestamp()-$start->getTimestamp())/60);
            if($end<=$start||$start->getTimestamp()<=time()||$start->diff($end)->days>0||$duration!==(int)$service['duration_minutes'])throw new RuntimeException('بازهٔ نوبت باید با مدت خدمت یکسان باشد.');
            $startValue=$start->format('Y-m-d H:i:s');$endValue=$end->format('Y-m-d H:i:s');
            $overlap=$this->repository->pdo()->prepare('SELECT 1 FROM telegram_appointment_slots WHERE service_id=:service AND starts_at<:end AND ends_at>:start LIMIT 1');
            $gap=(int)$service['buffer_before_minutes']+(int)$service['buffer_after_minutes'];$conflictStart=$start->modify('-'.$gap.' minutes')->format('Y-m-d H:i:s');$conflictEnd=$end->modify('+'.$gap.' minutes')->format('Y-m-d H:i:s');
            $overlap->execute(['service'=>$serviceId,'start'=>$conflictStart,'end'=>$conflictEnd]);if($overlap->fetchColumn())throw new RuntimeException('برای این خدمت در این بازه نوبت دیگری وجود دارد.');
            $statement=$this->repository->pdo()->prepare("INSERT INTO telegram_appointment_slots(service_id,starts_at,ends_at,status) VALUES(:service,:start,:end,'available')");
            try{$statement->execute(['service'=>$serviceId,'start'=>$startValue,'end'=>$endValue]);}
            catch(Throwable$error){throw new RuntimeException('برای این خدمت در همین زمان نوبت دیگری وجود دارد.',0,$error);}
            return $this->slotForProfile($profileId,(int)$this->repository->pdo()->lastInsertId());
        });
    }

    public function slotsForProfile(int $profileId,int $limit=300): array
    {
        $limit=max(1,min(1000,$limit));$query=$this->repository->pdo()->prepare("SELECT sl.* FROM telegram_appointment_slots sl JOIN telegram_appointment_services s ON s.id=sl.service_id WHERE s.profile_id=:profile AND sl.ends_at>=CURRENT_TIMESTAMP ORDER BY sl.starts_at LIMIT $limit");
        $query->execute(['profile'=>$profileId]);return $query->fetchAll();
    }

    public function blockSlot(int $profileId,int $slotId): array
    {
        $slot=$this->slotForProfile($profileId,$slotId);if((string)$slot['status']==='blocked')return $slot;
        if((string)$slot['status']!=='available')throw new RuntimeException('نوبت رزروشده یا نگه‌داری‌شده قابل مسدودکردن نیست.');
        $statement=$this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='blocked',lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='available'");
        $statement->execute(['id'=>$slotId]);if($statement->rowCount()!==1)throw new RuntimeException('وضعیت نوبت هم‌زمان تغییر کرد.');
        return $this->slotForProfile($profileId,$slotId);
    }

    public function expireHolds(int $limit=200): int
    {
        $limit=max(1,min(1000,$limit));
        return $this->repository->transaction(function()use($limit):int{
            $rows=$this->repository->pdo()->query("SELECT * FROM telegram_appointment_reservations WHERE status='held' AND hold_expires_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT $limit")->fetchAll();
            foreach($rows as$row)$this->expireReservation($row);return count($rows);
        });
    }

    private function expireSlotIfNeeded(int $slotId): void
    {
        $query=$this->repository->pdo()->prepare("SELECT * FROM telegram_appointment_reservations WHERE slot_id=:slot AND status='held' AND hold_expires_at<=CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");$query->execute(['slot'=>$slotId]);$row=$query->fetch();if(is_array($row))$this->expireReservation($row);
    }

    private function expireReservation(array $row): void
    {
        $this->repository->pdo()->prepare("UPDATE telegram_appointment_reservations SET status='expired',hold_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='held'")->execute(['id'=>$row['id']]);
        $this->repository->pdo()->prepare("UPDATE telegram_appointment_slots SET status='available',hold_expires_at=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='held'")->execute(['id'=>$row['slot_id']]);
        $this->event((int)$row['id'],'expired','system','',[]);
    }

    private function reservationByReference(string $reference,?int $contactId,bool $forUpdate=false): array
    {
        $sql='SELECT * FROM telegram_appointment_reservations WHERE public_ref=:ref';$params=['ref'=>$reference];if($contactId!==null){$sql.=' AND contact_id=:contact';$params['contact']=$contactId;}if($forUpdate&&$this->repository->driver()==='pgsql')$sql.=' FOR UPDATE';
        $query=$this->repository->pdo()->prepare($sql);$query->execute($params);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('رزرو پیدا نشد.');
    }

    private function reservationForProfile(int $profileId,string $reference,bool $forUpdate=false): array
    {
        $sql='SELECT * FROM telegram_appointment_reservations WHERE profile_id=:profile AND public_ref=:ref';if($forUpdate&&$this->repository->driver()==='pgsql')$sql.=' FOR UPDATE';$query=$this->repository->pdo()->prepare($sql);
        $query->execute(['profile'=>$profileId,'ref'=>$reference]);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('رزرو پیدا نشد.');
    }

    private function slotForProfile(int $profileId,int $slotId): array
    {
        $query=$this->repository->pdo()->prepare('SELECT sl.* FROM telegram_appointment_slots sl JOIN telegram_appointment_services s ON s.id=sl.service_id WHERE sl.id=:id AND s.profile_id=:profile');
        $query->execute(['id'=>$slotId,'profile'=>$profileId]);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('نوبت پیدا نشد.');
    }

    private function reservationRow(int $id): array
    {
        $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_appointment_reservations WHERE id=:id');$query->execute(['id'=>$id]);$row=$query->fetch();return is_array($row)?$row:throw new RuntimeException('رزرو پیدا نشد.');
    }

    private function findByIdempotency(int $profileId,string $hash): ?array
    {
        $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_appointment_reservations WHERE profile_id=:profile AND idempotency_key=:key');$query->execute(['profile'=>$profileId,'key'=>$hash]);$row=$query->fetch();return is_array($row)?$row:null;
    }

    private function present(array $row): array
    {
        $customer=json_decode(SecretStore::open((string)$row['customer_encrypted'],self::reservationContext((string)$row['public_ref'])),true);
        $notes='';if((string)($row['notes_encrypted']??'')!=='')$notes=SecretStore::open((string)$row['notes_encrypted'],self::reservationNotesContext((string)$row['public_ref']));
        $slot=$this->repository->pdo()->prepare('SELECT sl.starts_at,sl.ends_at,s.title service_title,s.duration_minutes,s.mode,s.location_label,s.price_amount,s.price_currency,p.timezone FROM telegram_appointment_slots sl JOIN telegram_appointment_services s ON s.id=sl.service_id JOIN telegram_assistant_profiles p ON p.id=s.profile_id WHERE sl.id=:id');$slot->execute(['id'=>$row['slot_id']]);$details=(array)$slot->fetch();
        $resolution='';if((string)$row['status']==='cancelled'){$event=$this->repository->pdo()->prepare("SELECT event_type FROM telegram_appointment_events WHERE reservation_id=:reservation AND event_type IN ('rejected','cancelled') ORDER BY id DESC LIMIT 1");$event->execute(['reservation'=>$row['id']]);$resolution=(string)$event->fetchColumn();}
        $utc=new DateTimeZone('UTC');$zone=new DateTimeZone((string)($details['timezone']??'UTC'));
        $slotStart=isset($details['starts_at'])?(new DateTimeImmutable((string)$details['starts_at'],$utc)):null;
        $slotEnd=isset($details['ends_at'])?(new DateTimeImmutable((string)$details['ends_at'],$utc)):null;
        return [
            'id'=>(int)$row['id'],'reference'=>(string)$row['public_ref'],'profile_id'=>(int)$row['profile_id'],'service_id'=>(int)$row['service_id'],'slot_id'=>(int)$row['slot_id'],
            'contact_id'=>$row['contact_id']!==null?(int)$row['contact_id']:null,'conversation_id'=>$row['conversation_id']!==null?(int)$row['conversation_id']:null,
            'status'=>(string)$row['status'],'resolution'=>$resolution,'customer_timezone'=>(string)$row['customer_timezone'],'customer'=>is_array($customer)?$customer:[],'notes'=>$notes,
            'hold_expires_at'=>$row['hold_expires_at'],'confirmed_at'=>$row['confirmed_at'],'cancelled_at'=>$row['cancelled_at'],'created_at'=>$row['created_at'],
            'slot'=>[
                'start_at'=>$slotStart?->format('Y-m-d\TH:i:s\Z'),'end_at'=>$slotEnd?->format('Y-m-d\TH:i:s\Z'),
                'local_date'=>$slotStart?->setTimezone($zone)->format('Y-m-d'),'local_time'=>$slotStart?->setTimezone($zone)->format('H:i'),
                'local_end_time'=>$slotEnd?->setTimezone($zone)->format('H:i'),
            ],
            'service'=>['title'=>$details['service_title']??'','duration_minutes'=>(int)($details['duration_minutes']??0),'mode'=>$details['mode']??'','location_label'=>$details['location_label']??'','price_amount'=>$details['price_amount']??null,'price_currency'=>$details['price_currency']??''],
        ];
    }

    private function assertContact(int $profileId,int $contactId): void
    {
        $query=$this->repository->pdo()->prepare('SELECT 1 FROM telegram_assistant_contacts WHERE id=:id AND profile_id=:profile AND is_blocked=0');$query->execute(['id'=>$contactId,'profile'=>$profileId]);if(!$query->fetchColumn())throw new RuntimeException('مشتری رزرو معتبر نیست.');
    }

    private function assertConversation(int $profileId,int $conversationId,?int $contactId): void
    {
        $sql='SELECT 1 FROM telegram_assistant_conversations WHERE id=:id AND profile_id=:profile';$params=['id'=>$conversationId,'profile'=>$profileId];if($contactId!==null){$sql.=' AND contact_id=:contact';$params['contact']=$contactId;}
        $query=$this->repository->pdo()->prepare($sql);$query->execute($params);if(!$query->fetchColumn())throw new RuntimeException('گفت‌وگوی رزرو معتبر نیست.');
    }

    private function assertOperator(int $userId): void
    {
        if($userId<1)throw new RuntimeException('مدیر معتبر نیست.');$query=$this->repository->pdo()->prepare("SELECT 1 FROM users WHERE id=:id AND status='active' AND role IN ('owner','admin')");$query->execute(['id'=>$userId]);if(!$query->fetchColumn())throw new RuntimeException('مدیر فعال پیدا نشد.');
    }

    private function event(int $reservationId,string $type,string $actor,string $actorRef,array $details): void
    {
        $this->repository->pdo()->prepare('INSERT INTO telegram_appointment_events(reservation_id,event_type,actor_type,actor_ref,details_json) VALUES(:reservation,:type,:actor,:ref,:details)')
            ->execute(['reservation'=>$reservationId,'type'=>$type,'actor'=>$actor,'ref'=>mb_substr($actorRef,0,80),'details'=>TelegramAssistantRepository::json($details)]);
    }

    /** Creates one internal, PII-free manager notice for a submitted request. */
    private function notifyOwnerOfPendingReservation(array $reservation): void
    {
        $managers=$this->repository->pdo()->query("SELECT id,role,status FROM users WHERE role IN ('owner','admin') AND status='active' ORDER BY CASE WHEN role='owner' THEN 0 ELSE 1 END,id")->fetchAll();
        $managers=array_values(array_filter($managers,static fn(array$manager):bool=>Access::allowed($manager,'telegram')));
        if($managers===[])return;
        $service=$this->repository->pdo()->prepare('SELECT title FROM telegram_appointment_services WHERE id=:id AND profile_id=:profile');
        $service->execute(['id'=>$reservation['service_id'],'profile'=>$reservation['profile_id']]);
        $title=mb_substr(trim((string)$service->fetchColumn()),0,120);
        $reference=(string)$reservation['public_ref'];
        $statement=$this->repository->pdo()->prepare("INSERT INTO notifications(user_id,channel,event_type,dedupe_key,subject,body) VALUES(:user,'admin','telegram_appointment_pending',:dedupe,:subject,:body) ON CONFLICT(dedupe_key) DO NOTHING");
        foreach($managers as$manager)$statement->execute([
            'user'=>(int)$manager['id'],
            'dedupe'=>'telegram-appointment-pending-'.(int)$reservation['id'].'-'.(int)$manager['id'],
            'subject'=>'درخواست رزرو جدید',
            'body'=>'درخواست '.$reference.($title!==''?' برای «'.$title.'»':'').' ثبت شد و منتظر تصمیم مدیر است.',
        ]);
    }

    private function resolveOwnerPendingNotifications(int $reservationId): void
    {
        $statement=$this->repository->pdo()->prepare("UPDATE notifications SET status='sent',sent_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE channel='admin' AND event_type='telegram_appointment_pending' AND status='pending' AND dedupe_key LIKE :dedupe");
        $statement->execute(['dedupe'=>'telegram-appointment-pending-'.$reservationId.'-%']);
    }

    private static function blockedByException(DateTimeImmutable $start,DateTimeImmutable $end,array $exceptions): bool
    {
        foreach($exceptions as$exception){if((string)$exception['kind']!=='unavailable')continue;$from=new DateTimeImmutable((string)$exception['starts_at'],new DateTimeZone('UTC'));$until=new DateTimeImmutable((string)$exception['ends_at'],new DateTimeZone('UTC'));if($start<$until&&$end>$from)return true;}
        return false;
    }

    private static function customerData(array $input): array
    {
        $name=mb_substr(trim((string)($input['name']??'')),0,190);$phone=mb_substr(trim((string)($input['phone']??'')),0,80);$email=mb_substr(trim((string)($input['email']??'')),0,190);
        if($name==='')throw new RuntimeException('نام رزروکننده لازم است.');if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new RuntimeException('ایمیل معتبر نیست.');
        return ['name'=>$name,'phone'=>$phone,'email'=>$email];
    }

    private static function idempotency(string $key): void{if(strlen($key)<8||strlen($key)>190||preg_match('/^[A-Za-z0-9._:-]+$/',$key)!==1)throw new RuntimeException('کلید idempotency معتبر نیست.');}
    private static function publicRef(string $ref): void{if(preg_match('/^APT-[A-F0-9]{20}$/',$ref)!==1)throw new RuntimeException('شناسهٔ عمومی رزرو معتبر نیست.');}
    private static function reference(): string{return 'APT-'.strtoupper(bin2hex(random_bytes(10)));}
    private static function reservationContext(string $ref): string{return 'telegram.appointment-customer.'.strtolower($ref);}
    private static function reservationNotesContext(string $ref): string{return 'telegram.appointment-notes.'.strtolower($ref);}
    private static function clock(string $value): bool{return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$value)===1;}
    private static function clockMinutes(string $value): int{[$hour,$minute]=array_map('intval',explode(':',$value,2));return $hour*60+$minute;}
    private static function dateRangesOverlap(?string $leftFrom,?string $leftUntil,?string $rightFrom,?string $rightUntil): bool{return($leftFrom??'0001-01-01')<=($rightUntil??'9999-12-31')&&($rightFrom??'0001-01-01')<=($leftUntil??'9999-12-31');}
    private static function dateOrNull(mixed $value): ?string{$value=trim((string)$value);if($value==='')return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('تاریخ معتبر نیست.');return $value;}
    private static function localDate(string $value,DateTimeZone $timezone): DateTimeImmutable{$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$timezone);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('تاریخ معتبر نیست.');return $date;}
    private static function utcDateTime(string $value,string $sourceTimezone): DateTimeImmutable{try{return(new DateTimeImmutable($value,new DateTimeZone($sourceTimezone)))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable){throw new RuntimeException('تاریخ و زمان معتبر نیست.');}}
}
