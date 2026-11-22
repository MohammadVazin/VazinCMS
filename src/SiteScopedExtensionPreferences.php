<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Inert tenant-scoped extension/theme preferences.
 *
 * Preferences are requests for a future per-site runtime; they NEVER execute,
 * install, deploy, activate, disable or modify global CMS extensions/themes.
 * No change is made to cms_extensions, active modules, theme files or hooks.
 */
final class SiteScopedExtensionPreferences
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if($siteId<1 || !SiteScopeMode::active($pdo)){
            throw new RuntimeException('tenant_extension_schema_unavailable');
        }
        $site=$pdo->prepare(
            "SELECT 1 FROM cms_sites WHERE id=:site AND status IN('active','staging')"
        );
        $site->execute(['site'=>$siteId]);
        if(!$site->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
        try{
            $pdo->query('SELECT site_id,extension_key,desired_enabled,changed_by ' .
                'FROM cms_site_extension_preferences WHERE 1=0');
            $pdo->query('SELECT site_id,extension_key,requested_by ' .
                'FROM cms_site_theme_preferences WHERE 1=0');
        }catch(Throwable){
            throw new RuntimeException('tenant_extension_schema_unavailable');
        }
    }

    public function catalog(int $actorId):array
    {
        $this->requireManager($actorId);
        $q=$this->pdo->prepare(
            'SELECT e.extension_key,e.extension_type,e.name,e.version,e.description,' .
            'e.source,e.status,p.desired_enabled,' .
            'CASE WHEN t.extension_key=e.extension_key THEN 1 ELSE 0 END AS requested_theme ' .
            'FROM cms_extensions e ' .
            'LEFT JOIN cms_site_extension_preferences p ' .
            'ON p.extension_key=e.extension_key AND p.site_id=:site_p ' .
            'LEFT JOIN cms_site_theme_preferences t ' .
            'ON t.extension_key=e.extension_key AND t.site_id=:site_t ' .
            'WHERE e.extension_type IN (\'module\',\'theme\') ' .
            'ORDER BY e.extension_type,e.name,e.extension_key'
        );
        $q->execute(['site_p'=>$this->siteId,'site_t'=>$this->siteId]);
        return array_map(static function(array $row):array{
            $row['can_request']=($row['source']==='bundled' &&
                in_array($row['status'],['active','inactive'],true));
            $row['requested_enabled']=$row['desired_enabled']===null
                ? null:(bool)$row['desired_enabled'];
            $row['requested_theme']=(bool)$row['requested_theme'];
            $row['applied_to_runtime']=false;
            unset($row['desired_enabled']);
            return $row;
        },$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function requestModule(int $actorId,string $key,bool $enable):void
    {
        $this->requireManager($actorId);
        $this->assertBundled($key,'module');
        $q=$this->pdo->prepare(
            'INSERT INTO cms_site_extension_preferences(' .
            'site_id,extension_key,desired_enabled,changed_by) ' .
            'VALUES(:site,:key,:desired,:actor) ' .
            'ON CONFLICT(site_id,extension_key) DO UPDATE SET ' .
            'desired_enabled=excluded.desired_enabled,changed_by=excluded.changed_by,' .
            'updated_at=CURRENT_TIMESTAMP ' .
            'WHERE cms_site_extension_preferences.site_id=excluded.site_id'
        );
        $q->execute([
            'site'=>$this->siteId,'key'=>$key,'desired'=>$enable?1:0,'actor'=>$actorId
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_extension_preference_conflict');
    }

    public function requestTheme(int $actorId,string $key):void
    {
        $this->requireManager($actorId);
        $this->assertBundled($key,'theme');
        $q=$this->pdo->prepare(
            'INSERT INTO cms_site_theme_preferences(site_id,extension_key,requested_by) ' .
            'VALUES(:site,:key,:actor) ' .
            'ON CONFLICT(site_id) DO UPDATE SET extension_key=excluded.extension_key,' .
            'requested_by=excluded.requested_by,updated_at=CURRENT_TIMESTAMP ' .
            'WHERE cms_site_theme_preferences.site_id=excluded.site_id'
        );
        $q->execute(['site'=>$this->siteId,'key'=>$key,'actor'=>$actorId]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_theme_preference_conflict');
    }

    private function assertBundled(string $key,string $type):void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/D',$key)){
            throw new RuntimeException('tenant_extension_key_invalid');
        }
        $q=$this->pdo->prepare(
            "SELECT 1 FROM cms_extensions WHERE extension_key=:key " .
            "AND extension_type=:type AND source='bundled' " .
            "AND status IN ('active','inactive') LIMIT 1"
        );
        $q->execute(['key'=>$key,'type'=>$type]);
        if(!$q->fetchColumn())throw new RuntimeException('tenant_extension_not_eligible');
    }

    private function requireManager(int $actorId):void
    {
        if($actorId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            'WHERE m.site_id=:site AND m.user_id=:actor ' .
            "AND u.status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        if(!in_array($q->fetchColumn(),['owner','admin'],true)){
            throw new RuntimeException('tenant_access_denied');
        }
    }
}
