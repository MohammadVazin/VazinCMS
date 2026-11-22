<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class MultisiteManager
{
    /**
     * Candidate tenant creation has no anonymous/global-owner fallback.
     * Persist the site and its explicit site-owner membership atomically.
     * A failed membership write must never leave an orphan active site.
     */
    public static function createSite(PDO $pdo,string $key,string $name,int $ownerId,string $locale='en',string $agencyKey=''): int
    {
        if(preg_match('/\A[a-z0-9][a-z0-9-]{2,78}\z/D',$key)!==1 ||
           mb_strlen(trim($name))<2 || $ownerId<1){
            throw new InvalidArgumentException('invalid_site_owner_or_metadata');
        }
        return DatabaseTransaction::immediate(
            $pdo,
            static function() use($pdo,$key,$name,$ownerId,$locale,$agencyKey):int {
                $staff=$pdo->prepare(
                    "SELECT 1 FROM users WHERE id=:owner AND role='owner' AND status='active'"
                );
                $staff->execute(['owner'=>$ownerId]);
                try {$authorized=$staff->fetchColumn()!==false;}
                finally {$staff->closeCursor();}
                if(!$authorized)throw new InvalidArgumentException('site_owner_not_authorized');
                $pdo->prepare(
                    "INSERT INTO cms_sites(site_key,name,status,owner_user_id,agency_key,default_locale,settings_json) ".
                    "VALUES(:key,:name,'active',:owner,:agency,:locale,'{}')"
                )->execute([
                    'key'=>$key,'name'=>trim($name),'owner'=>$ownerId,
                    'agency'=>$agencyKey,'locale'=>$locale
                ]);
                $id=(int)$pdo->lastInsertId();
                if($id<1)throw new InvalidArgumentException('site_id_missing');
                $pdo->prepare(
                    "INSERT INTO cms_site_memberships(site_id,user_id,site_role,permissions_json) ".
                    "VALUES(:site,:user,'owner','[\"*\"]')"
                )->execute(['site'=>$id,'user'=>$ownerId]);
                return $id;
            }
        );
    }
    /**
     * Stage an UNVERIFIED domain. Exact DNS-host validation prevents URL,
     * credentials, IP, port or wildcard interpretation. The primary-domain
     * replacement and insert are one transaction, so a duplicate cannot
     * silently clear an existing primary. This never sets verified_at.
     */
    public static function addDomain(PDO $pdo,int $siteId,string $domain,bool $primary=false): array
    {
        $host=SiteDomainDnsClaim::canonicalHost($domain);
        if($siteId<1)throw new InvalidArgumentException('invalid_site_id');
        $token='vzdom_'.bin2hex(random_bytes(16));
        return DatabaseTransaction::immediate(
            $pdo,
            static function() use ($pdo,$siteId,$host,$primary,$token):array {
                $pgsql=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql';
                $check=$pdo->prepare(
                    'SELECT status FROM cms_sites WHERE id=:site'.
                    ($pgsql?' FOR UPDATE':'')
                );
                $check->execute(['site'=>$siteId]);
                try {$status=$check->fetchColumn();}
                finally {$check->closeCursor();}
                if(!in_array($status,['active','staging'],true)){
                    throw new InvalidArgumentException('inactive_or_unknown_site');
                }
                if($primary){
                    $pdo->prepare(
                        'UPDATE cms_site_domains SET is_primary=FALSE WHERE site_id=:site'
                    )->execute(['site'=>$siteId]);
                }
                $insert=$pdo->prepare(
                    'INSERT INTO cms_site_domains(site_id,domain,is_primary,verification_token) '.
                    'VALUES(:site,:domain,:primary,:token)'
                );
                $insert->bindValue(':site',$siteId,PDO::PARAM_INT);
                $insert->bindValue(':domain',$host,PDO::PARAM_STR);
                // PG requires BOOLEAN; SQLite maps PDO bool to 0/1.
                $insert->bindValue(':primary',$primary,PDO::PARAM_BOOL);
                $insert->bindValue(':token',$token,PDO::PARAM_STR);
                $insert->execute();
                return ['domain'=>$host,'verification_token'=>$token];
            }
        );
    }
    /**
     * A token held in this SAME database is NOT proof of DNS/HTTP control.
     * No caller may mark a tenant domain verified until an independently
     * reviewed verifier proves a fresh external challenge, exact host, site
     * ownership, expiry and one-time use. Retain the method signature but
     * fail closed; do not let a legacy token-only path grant tenant access.
     *
     * Candidate-only protective gate, not a DNS verification implementation.
     */
    public static function verifyDomain(PDO $pdo,string $domain,string $token): void
    {
        throw new InvalidArgumentException('domain_external_proof_required');
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
