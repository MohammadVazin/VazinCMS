<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO;
final class TrustScanner
{
    public static function run(PDO $pdo): array
    {
        $rows=$pdo->query("SELECT extension_key,version,checksum,status FROM cms_extensions WHERE source='runtime' AND status<>'removed'")->fetchAll();$q=0;$b=0;$details=[];
        foreach($rows as$r){$action='ok';$reason='';
            if(MarketplaceTrust::isRevoked($pdo,(string)$r['extension_key'],(string)$r['version'])){$action='block';$reason='revoked';$b++;SafeUpdateManager::quarantine($pdo,(string)$r['extension_key'],(string)$r['version'],(string)$r['checksum'],'Marketplace revocation');}
            $a=$pdo->prepare("SELECT severity,action,title FROM cms_security_advisories WHERE extension_key=:key AND (affected_constraint='*' OR affected_constraint=:version) ORDER BY CASE severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC LIMIT 1");$a->execute(['key'=>$r['extension_key'],'version'=>$r['version']]);if($adv=$a->fetch()){if(in_array($adv['action'],['quarantine','block'],true)){$action=$adv['action'];$reason=(string)$adv['title'];$q++;SafeUpdateManager::quarantine($pdo,(string)$r['extension_key'],(string)$r['version'],(string)$r['checksum'],$reason);}}
            $details[]=['key'=>$r['extension_key'],'version'=>$r['version'],'action'=>$action,'reason'=>$reason];
        }
        $scan='scan_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));$pdo->prepare('INSERT INTO cms_trust_scans(scan_key,checked_extensions,quarantined,blocked,details_json) VALUES(:key,:checked,:q,:b,:details)')->execute(['key'=>$scan,'checked'=>count($rows),'q'=>$q,'b'=>$b,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return['scan_key'=>$scan,'checked'=>count($rows),'quarantined'=>$q,'blocked'=>$b,'details'=>$details];
    }
}
