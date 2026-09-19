<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO; use RuntimeException;
final class EcosystemCertification
{
    public static function certify(PDO $pdo,string $extensionKey,string $version): array
    {
        $row=$pdo->prepare('SELECT e.* FROM cms_extensions e WHERE extension_key=:key AND version=:version LIMIT 1');$row->execute(['key'=>$extensionKey,'version'=>$version]);$ext=$row->fetch();if(!$ext)throw new RuntimeException('Extension not installed.');
        $manifest=ExtensionManifest::fromDirectory((string)$ext['package_path']);$checks=[];
        $checks[]=['key'=>'manifest','status'=>'pass','details'=>'Manifest parsed'];
        $errors=ExtensionManager::compatibilityErrors($manifest);$checks[]=['key'=>'compatibility','status'=>$errors?'fail':'pass','details'=>implode('; ',$errors)];
        $trust=$pdo->prepare('SELECT signature_status,license_status FROM marketplace_provenance WHERE extension_key=:key AND version=:version ORDER BY id DESC LIMIT 1');$trust->execute(['key'=>$extensionKey,'version'=>$version]);$tr=$trust->fetch();
        $trusted=$ext['source']==='bundled'||($tr&&$tr['signature_status']==='trusted'&&in_array($tr['license_status'],['active','not_required'],true));$checks[]=['key'=>'trust','status'=>$trusted?'pass':'fail','details'=>$trusted?'trusted':'untrusted'];
        $revoked=MarketplaceTrust::isRevoked($pdo,$extensionKey,$version);$checks[]=['key'=>'revocation','status'=>$revoked?'fail':'pass','details'=>$revoked?'revoked':'clear'];
        self::replaceResults($pdo,$extensionKey,$version,$checks);$fails=count(array_filter($checks,fn($c)=>$c['status']==='fail'));$score=max(0,100-$fails*25);$status=$fails?'failed':'passed';
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$evidence=json_encode(['checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($driver==='sqlite')$pdo->prepare("INSERT INTO ecosystem_certifications(extension_key,version,status,score,reviewer,evidence_json,finished_at) VALUES(:key,:version,:status,:score,'automated',:evidence,CURRENT_TIMESTAMP) ON CONFLICT(extension_key,version) DO UPDATE SET status=:status2,score=:score2,evidence_json=:evidence2,finished_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP")->execute(['key'=>$extensionKey,'version'=>$version,'status'=>$status,'score'=>$score,'evidence'=>$evidence,'status2'=>$status,'score2'=>$score,'evidence2'=>$evidence]);
        else $pdo->prepare("INSERT INTO ecosystem_certifications(extension_key,version,status,score,reviewer,evidence_json,finished_at) VALUES(:key,:version,:status,:score,'automated',:evidence,CURRENT_TIMESTAMP) ON CONFLICT(extension_key,version) DO UPDATE SET status=EXCLUDED.status,score=EXCLUDED.score,evidence_json=EXCLUDED.evidence_json,finished_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP")->execute(['key'=>$extensionKey,'version'=>$version,'status'=>$status,'score'=>$score,'evidence'=>$evidence]);
        self::recordCompatibility($pdo,$manifest);return['extension_key'=>$extensionKey,'version'=>$version,'status'=>$status,'score'=>$score,'checks'=>$checks];
    }
    public static function canPublish(PDO $pdo,string$key,string$version): bool
    {
        $q=$pdo->prepare("SELECT c.status,p.status package_status,pub.trust_status FROM ecosystem_certifications c JOIN marketplace_packages p ON p.extension_key=c.extension_key AND p.version=c.version LEFT JOIN marketplace_publishers pub ON pub.id=p.publisher_id WHERE c.extension_key=:key AND c.version=:version LIMIT 1");$q->execute(['key'=>$key,'version'=>$version]);$r=$q->fetch();return(bool)($r&&$r['status']==='passed'&&$r['package_status']==='active'&&($r['trust_status']==='trusted'||$r['trust_status']===null));
    }
    private static function replaceResults(PDO $pdo,string$key,string$version,array$checks): void
    {
        $pdo->prepare('DELETE FROM ecosystem_review_results WHERE extension_key=:key AND version=:version')->execute(['key'=>$key,'version'=>$version]);$i=$pdo->prepare('INSERT INTO ecosystem_review_results(extension_key,version,check_key,status,details,evidence_json) VALUES(:key,:version,:check,:status,:details,:evidence)');foreach($checks as$c)$i->execute(['key'=>$key,'version'=>$version,'check'=>$c['key'],'status'=>$c['status'],'details'=>$c['details'],'evidence'=>'{}']);
    }
    private static function recordCompatibility(PDO $pdo,ExtensionManifest $manifest): void
    {
        $r=$manifest->data();$requires=$r['requires']??[];$db=['sqlite','pgsql'];$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$matrix=['current'=>['vazincms'=>Version::current(),'php'=>PHP_VERSION,'db'=>$driver],'tested_db'=>$db];$sqlDriver=$driver==='sqlite';$args=['key'=>$manifest->key(),'version'=>$manifest->version(),'cms'=>(string)($requires['vazincms']??'*'),'php'=>(string)($requires['php']??'*'),'db'=>json_encode($db),'deps'=>json_encode($requires['extensions']??[]),'matrix'=>json_encode($matrix)];
        $sql=$sqlDriver?'INSERT INTO ecosystem_compatibility(extension_key,version,vazincms_constraint,php_constraint,db_drivers_json,dependencies_json,tested_matrix_json) VALUES(:key,:version,:cms,:php,:db,:deps,:matrix) ON CONFLICT(extension_key,version) DO UPDATE SET vazincms_constraint=:cms2,php_constraint=:php2,db_drivers_json=:db2,dependencies_json=:deps2,tested_matrix_json=:matrix2,updated_at=CURRENT_TIMESTAMP':'INSERT INTO ecosystem_compatibility(extension_key,version,vazincms_constraint,php_constraint,db_drivers_json,dependencies_json,tested_matrix_json) VALUES(:key,:version,:cms,:php,:db,:deps,:matrix) ON CONFLICT(extension_key,version) DO UPDATE SET vazincms_constraint=EXCLUDED.vazincms_constraint,php_constraint=EXCLUDED.php_constraint,db_drivers_json=EXCLUDED.db_drivers_json,dependencies_json=EXCLUDED.dependencies_json,tested_matrix_json=EXCLUDED.tested_matrix_json,updated_at=CURRENT_TIMESTAMP';if($sqlDriver)$args+=['cms2'=>$args['cms'],'php2'=>$args['php'],'db2'=>$args['db'],'deps2'=>$args['deps'],'matrix2'=>$args['matrix']];$pdo->prepare($sql)->execute($args);
    }
}
