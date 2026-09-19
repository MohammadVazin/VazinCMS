<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class MultisiteManager
{
    public static function createSite(PDO $pdo,string $key,string $name,int $ownerId,string $locale='en',string $agencyKey=''): int
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,78}$/',$key)||mb_strlen(trim($name))<2)throw new InvalidArgumentException('Invalid site metadata.');
        $pdo->prepare("INSERT INTO cms_sites(site_key,name,status,owner_user_id,agency_key,default_locale,settings_json) VALUES(:key,:name,'active',:owner,:agency,:locale,'{}')")->execute(['key'=>$key,'name'=>$name,'owner'=>$ownerId?:null,'agency'=>$agencyKey,'locale'=>$locale]);$id=(int)$pdo->lastInsertId();
        if($ownerId>0)$pdo->prepare("INSERT INTO cms_site_memberships(site_id,user_id,site_role,permissions_json) VALUES(:site,:user,'owner','[\"*\"]')")->execute(['site'=>$id,'user'=>$ownerId]);return$id;
    }
    public static function addDomain(PDO $pdo,int $siteId,string $domain,bool $primary=false): array
    {
        $domain=strtolower(trim($domain));if(!filter_var('https://'.$domain,FILTER_VALIDATE_URL)||str_contains($domain,'/'))throw new InvalidArgumentException('Invalid domain.');$token='vzdom_'.bin2hex(random_bytes(16));
        if($primary)$pdo->prepare('UPDATE cms_site_domains SET is_primary=0 WHERE site_id=:site')->execute(['site'=>$siteId]);$pdo->prepare('INSERT INTO cms_site_domains(site_id,domain,is_primary,verification_token) VALUES(:site,:domain,:primary,:token)')->execute(['site'=>$siteId,'domain'=>$domain,'primary'=>$primary?1:0,'token'=>$token]);return['domain'=>$domain,'verification_token'=>$token];
    }
    public static function verifyDomain(PDO $pdo,string $domain,string $token): void
    {
        $q=$pdo->prepare('UPDATE cms_site_domains SET verified_at=CURRENT_TIMESTAMP WHERE lower(domain)=:domain AND verification_token=:token');$q->execute(['domain'=>strtolower($domain),'token'=>$token]);if($q->rowCount()!==1)throw new InvalidArgumentException('Domain verification failed.');
    }
    public static function setSetting(PDO $pdo,int $siteId,string $key,string $value): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?'INSERT INTO cms_site_settings(site_id,setting_key,setting_value) VALUES(:site,:key,:value) ON CONFLICT(site_id,setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP':'INSERT INTO cms_site_settings(site_id,setting_key,setting_value) VALUES(:site,:key,:value) ON CONFLICT(site_id,setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value,updated_at=CURRENT_TIMESTAMP';$args=['site'=>$siteId,'key'=>$key,'value'=>$value];if($driver==='sqlite')$args['value2']=$value;$pdo->prepare($sql)->execute($args);
    }
    public static function addMembership(PDO $pdo,int $siteId,int $userId,string $role,array $permissions=[]): void
    {
        if(!in_array($role,['owner','admin','editor','author','support','viewer'],true))throw new InvalidArgumentException('Invalid site role.');$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$json=json_encode(array_values(array_unique($permissions)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$sql=$driver==='sqlite'?'INSERT INTO cms_site_memberships(site_id,user_id,site_role,permissions_json) VALUES(:site,:user,:role,:permissions) ON CONFLICT(site_id,user_id) DO UPDATE SET site_role=:role2,permissions_json=:permissions2':'INSERT INTO cms_site_memberships(site_id,user_id,site_role,permissions_json) VALUES(:site,:user,:role,:permissions) ON CONFLICT(site_id,user_id) DO UPDATE SET site_role=EXCLUDED.site_role,permissions_json=EXCLUDED.permissions_json';$args=['site'=>$siteId,'user'=>$userId,'role'=>$role,'permissions'=>$json];if($driver==='sqlite')$args+=['role2'=>$role,'permissions2'=>$json];$pdo->prepare($sql)->execute($args);
    }
    public static function cloneSite(PDO $pdo,int $sourceSiteId,string $targetKey,string $targetName,int $ownerId): int
    {
        $target=self::createSite($pdo,$targetKey,$targetName,$ownerId);$pdo->prepare("INSERT INTO cms_site_clones(source_site_id,target_site_id,status,details_json) VALUES(:source,:target,'running','{}')")->execute(['source'=>$sourceSiteId,'target'=>$target]);$cloneId=(int)$pdo->lastInsertId();
        foreach($pdo->query('SELECT setting_key,setting_value FROM cms_site_settings WHERE site_id='.(int)$sourceSiteId)->fetchAll() as$r)self::setSetting($pdo,$target,(string)$r['setting_key'],(string)$r['setting_value']);$pdo->prepare("UPDATE cms_site_clones SET status='completed',details_json=:details,finished_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['details'=>json_encode(['settings_cloned'=>true]),'id'=>$cloneId]);return$target;
    }
}
