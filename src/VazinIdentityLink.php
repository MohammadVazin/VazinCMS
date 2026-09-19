<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use PDOException;
use RuntimeException;

final class VazinIdentityLink
{
    public static function resolve(PDO $pdo,array $profile,?int $linkUserId=null,bool $autoProvision=true):array
    {
        $profile=IdentityProfile::normalize($profile);
        $sub=$profile['sub'];$email=$profile['email'];$name=$profile['name'];

        $query=$pdo->prepare('SELECT u.* FROM user_identities i JOIN users u ON u.id=i.user_id WHERE i.provider=:provider AND i.provider_subject=:subject');
        $query->execute(['provider'=>'vazin_id','subject'=>$sub]);$user=$query->fetch();
        if($user){
            if($linkUserId!==null&&(int)$user['id']!==$linkUserId)throw new RuntimeException('این Vazin ID قبلاً به حساب دیگری متصل شده است.');
            if(($user['status']??'')!=='active')throw new RuntimeException('حساب محلی غیرفعال است.');
            self::updateIdentity($pdo,$profile);
            return$user;
        }

        if($linkUserId!==null){
            $query=$pdo->prepare('SELECT * FROM users WHERE id=:id');$query->execute(['id'=>$linkUserId]);$user=$query->fetch();
            if(!$user||($user['status']??'')!=='active')throw new RuntimeException('حساب محلی فعال پیدا نشد.');
            $query=$pdo->prepare('SELECT 1 FROM user_identities WHERE user_id=:uid AND provider=:provider');$query->execute(['uid'=>$linkUserId,'provider'=>'vazin_id']);
            if($query->fetchColumn())throw new RuntimeException('این حساب محلی قبلاً به Vazin ID دیگری متصل شده است.');
        }else{
            if(!$autoProvision)throw new RuntimeException('ساخت خودکار حساب غیرفعال است؛ مدیر باید ابتدا حساب شما را ایجاد یا متصل کند.');
            $query=$pdo->prepare('SELECT id FROM users WHERE lower(email)=:email');$query->execute(['email'=>$email]);
            if($query->fetchColumn())throw new RuntimeException('حسابی با این ایمیل وجود دارد؛ ابتدا محلی وارد شوید و اتصال Vazin ID را صریحاً تأیید کنید.');
            $password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
            $localName=$name!==''?$name:$email;
            self::uniqueGuard(static fn()=>$pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:password,'client','active')")->execute(['name'=>$localName,'email'=>$email,'password'=>$password]));
            $query=$pdo->prepare('SELECT * FROM users WHERE id=:id');$query->execute(['id'=>$pdo->lastInsertId()]);$user=$query->fetch();
        }

        self::insertIdentity($pdo,(int)$user['id'],$profile);
        return$user;
    }

    private static function updateIdentity(PDO $pdo,array $profile):void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lock=$driver==='pgsql'?' FOR UPDATE':'';
        $current=$pdo->prepare('SELECT * FROM user_identities WHERE provider=:provider AND provider_subject=:subject'.$lock);
        $current->execute(['provider'=>'vazin_id','subject'=>$profile['sub']]);
        $identity=$current->fetch();
        if(!$identity)throw new RuntimeException('اتصال Vazin ID پیدا نشد؛ دوباره تلاش کنید.');

        $columns=['email=:email','email_verified=1','last_login_at=CURRENT_TIMESTAMP'];
        $params=['email'=>$profile['email'],'provider'=>'vazin_id','subject'=>$profile['sub']];
        $shared=$profile['preferences_shared']??null;
        $incoming=$profile['profile_revision']??null;
        $stored=$identity['profile_revision']===null?null:(int)$identity['profile_revision'];
        $newer=is_int($incoming)&&($stored===null||$incoming>$stored);
        $equal=is_int($incoming)&&$stored!==null&&$incoming===$stored;

        if($newer){
            $columns[]='profile_revision=:profile_revision';$params['profile_revision']=$incoming;
            if(in_array($identity['display_name_source']??null,[null,'vazin_id'],true)){
                $columns[]='display_name=:display_name';$columns[]="display_name_source='vazin_id'";$params['display_name']=$profile['name'];
            }
            if($shared===true){
                if(in_array($identity['profile_source']??null,[null,'vazin_id'],true)){
                    foreach(self::optionalColumns() as $claim=>$column){$columns[]=$column.'=:'.$column;$params[$column]=$profile[$claim]??null;}
                    $columns[]="profile_source='vazin_id'";
                }
                $columns[]='preferences_shared=1';
            }elseif($shared===false){
                if(($identity['profile_source']??null)==='vazin_id'){
                    foreach(self::optionalColumns() as $column)$columns[]=$column.'=NULL';
                    $columns[]='profile_source=NULL';
                }
                $columns[]='preferences_shared=0';
            }
        }elseif($shared===false&&($incoming===null||$equal)){
            if(($identity['profile_source']??null)==='vazin_id'){
                foreach(self::optionalColumns() as $column)$columns[]=$column.'=NULL';
                $columns[]='profile_source=NULL';
            }
            $columns[]='preferences_shared=0';
        }
        self::uniqueGuard(static fn()=>$pdo->prepare('UPDATE user_identities SET '.implode(',',$columns).' WHERE provider=:provider AND provider_subject=:subject')->execute($params));
    }

    private static function insertIdentity(PDO $pdo,int $userId,array $profile):void
    {
        $columns=['user_id','provider','provider_subject','email','email_verified','linked_at','last_login_at','display_name','display_name_source'];
        $values=[':uid',':provider',':subject',':email','1','CURRENT_TIMESTAMP','CURRENT_TIMESTAMP',':display_name',':display_name_source'];
        $params=['uid'=>$userId,'provider'=>'vazin_id','subject'=>$profile['sub'],'email'=>$profile['email'],'display_name'=>$profile['name']!==''?$profile['name']:null,'display_name_source'=>$profile['name']!==''?'vazin_id':null];
        $shared=$profile['preferences_shared']??null;
        foreach(self::optionalColumns() as $claim=>$column){
            $columns[]=$column;$values[]=':'.$column;
            $params[$column]=$shared===true&&isset($profile['profile_revision'])?($profile[$claim]??null):null;
        }
        $columns[]='profile_source';$values[]=':profile_source';$params['profile_source']=$shared===true&&isset($profile['profile_revision'])?'vazin_id':null;
        $columns[]='preferences_shared';$values[]=':preferences_shared';$params['preferences_shared']=$shared===null?null:($shared?1:0);
        $columns[]='profile_revision';$values[]=':profile_revision';$params['profile_revision']=$profile['profile_revision']??null;
        self::uniqueGuard(static fn()=>$pdo->prepare('INSERT INTO user_identities('.implode(',',$columns).') VALUES('.implode(',',$values).')')->execute($params));
    }

    private static function optionalColumns():array
    {
        return ['preferred_username'=>'preferred_username','picture'=>'picture_url','locale'=>'locale','theme'=>'theme','updated_at'=>'profile_updated_at'];
    }

    private static function uniqueGuard(callable $operation):void
    {
        try{$operation();}catch(PDOException $error){
            if(in_array((string)$error->getCode(),['23000','23505'],true))throw new RuntimeException('اتصال هم‌زمان دیگری ثبت شد؛ صفحه را تازه‌سازی و دوباره تلاش کنید.',0,$error);
            throw$error;
        }
    }
}
