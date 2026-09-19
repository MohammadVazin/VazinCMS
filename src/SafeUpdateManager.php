<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

final class SafeUpdateManager
{
    public static function preflight(PDO $pdo,string $componentType,string $componentKey,string $targetVersion,string $targetChecksum): array
    {
        if(!in_array($componentType,['core','module','theme'],true))throw new RuntimeException('Unsupported component type.');
        if(!preg_match('/^[a-zA-Z0-9._-]{2,190}$/',$componentKey))throw new RuntimeException('Invalid component key.');
        if(!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$targetVersion))throw new RuntimeException('Invalid target version.');
        if(!preg_match('/^[a-f0-9]{64}$/',$targetChecksum))throw new RuntimeException('Invalid target checksum.');
        if($componentType!=='core'){
            $q=$pdo->prepare("SELECT 1 FROM cms_package_quarantine WHERE extension_key=:key AND status='active' LIMIT 1");$q->execute(['key'=>$componentKey]);if($q->fetchColumn())throw new RuntimeException('Component is quarantined.');
        }
        return['ok'=>true,'component_type'=>$componentType,'component_key'=>$componentKey,'target_version'=>$targetVersion,'target_checksum'=>$targetChecksum];
    }

    public static function begin(PDO $pdo,array $input,?int $actorId,string $snapshotPath): int
    {
        self::preflight($pdo,$input['component_type'],$input['component_key'],$input['after_version'],$input['after_checksum']);
        if($snapshotPath===''||!is_file($snapshotPath))throw new RuntimeException('Required snapshot is missing.');
        $pdo->prepare("INSERT INTO cms_update_history(component_type,component_key,before_version,before_checksum,after_version,after_checksum,actor_id,status,snapshot_path) VALUES(:type,:key,:before_version,:before_checksum,:after_version,:after_checksum,:actor,'started',:snapshot)")->execute(['type'=>$input['component_type'],'key'=>$input['component_key'],'before_version'=>$input['before_version']??null,'before_checksum'=>$input['before_checksum']??null,'after_version'=>$input['after_version'],'after_checksum'=>$input['after_checksum'],'actor'=>$actorId,'snapshot'=>$snapshotPath]);
        return(int)$pdo->lastInsertId();
    }
    public static function finish(PDO $pdo,int $id,bool $healthy,array $health=[],?string $error=null): void
    {
        $status=$healthy?'succeeded':'failed';$pdo->prepare('UPDATE cms_update_history SET status=:status,health_json=:health,error_message=:error,finished_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['status'=>$status,'health'=>json_encode($health,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'error'=>$error,'id'=>$id]);
    }

    public static function markRolledBack(PDO $pdo,int $id,array $health=[]): void
    {
        $pdo->prepare("UPDATE cms_update_history SET status='rolled_back',health_json=:health,finished_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['health'=>json_encode($health,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$id]);
    }

    public static function quarantine(PDO $pdo,string $key,?string $version,?string $checksum,string $reason): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?"INSERT INTO cms_package_quarantine(extension_key,version,checksum,reason,status) VALUES(:key,:version,:checksum,:reason,'active') ON CONFLICT(extension_key,version,checksum) DO UPDATE SET reason=:reason2,status='active',released_at=NULL":"INSERT INTO cms_package_quarantine(extension_key,version,checksum,reason,status) VALUES(:key,:version,:checksum,:reason,'active') ON CONFLICT(extension_key,version,checksum) DO UPDATE SET reason=EXCLUDED.reason,status='active',released_at=NULL";$args=['key'=>$key,'version'=>$version,'checksum'=>$checksum,'reason'=>$reason];if($driver==='sqlite')$args['reason2']=$reason;$pdo->prepare($sql)->execute($args);
    }
}
