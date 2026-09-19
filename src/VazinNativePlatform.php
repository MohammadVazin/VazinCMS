<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO; use RuntimeException;
final class VazinNativePlatform
{
    public static function createProfile(PDO $pdo,string $key,string $label,array $connectors,array $extensions,array $settings=[]): void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,78}$/',$key))throw new RuntimeException('Invalid native profile key.');$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$args=['key'=>$key,'label'=>$label,'connectors'=>json_encode(array_values(array_unique($connectors))),'extensions'=>json_encode(array_values(array_unique($extensions))),'settings'=>json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $sql=$driver==='sqlite'?"INSERT INTO vazin_native_profiles(profile_key,label,required_connectors_json,default_extensions_json,default_settings_json) VALUES(:key,:label,:connectors,:extensions,:settings) ON CONFLICT(profile_key) DO UPDATE SET label=:label2,required_connectors_json=:connectors2,default_extensions_json=:extensions2,default_settings_json=:settings2,updated_at=CURRENT_TIMESTAMP":"INSERT INTO vazin_native_profiles(profile_key,label,required_connectors_json,default_extensions_json,default_settings_json) VALUES(:key,:label,:connectors,:extensions,:settings) ON CONFLICT(profile_key) DO UPDATE SET label=EXCLUDED.label,required_connectors_json=EXCLUDED.required_connectors_json,default_extensions_json=EXCLUDED.default_extensions_json,default_settings_json=EXCLUDED.default_settings_json,updated_at=CURRENT_TIMESTAMP";if($driver==='sqlite')$args+=['label2'=>$label,'connectors2'=>$args['connectors'],'extensions2'=>$args['extensions'],'settings2'=>$args['settings']];$pdo->prepare($sql)->execute($args);
    }
    public static function provision(PDO $pdo,int $siteId,string $profileKey): array
    {
        $q=$pdo->prepare('SELECT * FROM vazin_native_profiles WHERE profile_key=:key');$q->execute(['key'=>$profileKey]);$profile=$q->fetch();if(!$profile)throw new RuntimeException('Native profile not found.');$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql=$driver==='sqlite'?"INSERT INTO vazin_managed_sites(site_id,profile_key,provision_state) VALUES(:site,:profile,'provisioning') ON CONFLICT(site_id) DO UPDATE SET profile_key=:profile2,provision_state='provisioning',updated_at=CURRENT_TIMESTAMP":"INSERT INTO vazin_managed_sites(site_id,profile_key,provision_state) VALUES(:site,:profile,'provisioning') ON CONFLICT(site_id) DO UPDATE SET profile_key=EXCLUDED.profile_key,provision_state='provisioning',updated_at=CURRENT_TIMESTAMP";$args=['site'=>$siteId,'profile'=>$profileKey];if($driver==='sqlite')$args['profile2']=$profileKey;$pdo->prepare($sql)->execute($args);
        foreach(json_decode((string)$profile['default_settings_json'],true)?:[] as$k=>$v)MultisiteManager::setSetting($pdo,$siteId,(string)$k,is_scalar($v)?(string)$v:json_encode($v));
        foreach(json_decode((string)$profile['default_extensions_json'],true)?:[] as$key)self::siteExtension($pdo,$siteId,(string)$key,true);
        foreach(json_decode((string)$profile['required_connectors_json'],true)?:[] as$key)self::bindService($pdo,$siteId,(string)$key,(string)$key);
        self::event($pdo,$siteId,'profile.applied','ok',['profile'=>$profileKey]);return self::reconcile($pdo,$siteId);
    }
    public static function bindService(PDO $pdo,int $siteId,string $serviceKey,string $connectorKey,array $policy=[]): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$json=json_encode($policy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$sql=$driver==='sqlite'?"INSERT INTO vazin_native_bindings(site_id,service_key,connector_key,status,policy_json) VALUES(:site,:service,:connector,'pending',:policy) ON CONFLICT(site_id,service_key) DO UPDATE SET connector_key=:connector2,policy_json=:policy2,updated_at=CURRENT_TIMESTAMP":"INSERT INTO vazin_native_bindings(site_id,service_key,connector_key,status,policy_json) VALUES(:site,:service,:connector,'pending',:policy) ON CONFLICT(site_id,service_key) DO UPDATE SET connector_key=EXCLUDED.connector_key,policy_json=EXCLUDED.policy_json,updated_at=CURRENT_TIMESTAMP";$args=['site'=>$siteId,'service'=>$serviceKey,'connector'=>$connectorKey,'policy'=>$json];if($driver==='sqlite')$args+=['connector2'=>$connectorKey,'policy2'=>$json];$pdo->prepare($sql)->execute($args);
    }
    public static function reconcile(PDO $pdo,int $siteId): array
    {
        $rows=$pdo->prepare('SELECT * FROM vazin_native_bindings WHERE site_id=:site ORDER BY service_key');$rows->execute(['site'=>$siteId]);$bindings=$rows->fetchAll();$ready=0;$degraded=0;$details=[];
        foreach($bindings as$b){$q=$pdo->prepare('SELECT status FROM service_connectors WHERE connector_key=:key LIMIT 1');$q->execute(['key'=>$b['connector_key']]);$status=(string)($q->fetchColumn()?:'disabled');$mapped=in_array($status,['ready','degraded'],true)?$status:'error';$pdo->prepare('UPDATE vazin_native_bindings SET status=:status,updated_at=CURRENT_TIMESTAMP WHERE site_id=:site AND service_key=:service')->execute(['status'=>$mapped,'site'=>$siteId,'service'=>$b['service_key']]);if($mapped==='ready')$ready++;else$degraded++;$details[]=['service'=>$b['service_key'],'connector'=>$b['connector_key'],'status'=>$mapped];}
        $state=$degraded===0?'ready':($ready>0?'degraded':'failed');$pdo->prepare('UPDATE vazin_managed_sites SET provision_state=:state,last_health_json=:health,updated_at=CURRENT_TIMESTAMP WHERE site_id=:site')->execute(['state'=>$state,'health'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'site'=>$siteId]);self::event($pdo,$siteId,'reconcile',$state,['bindings'=>$details]);return['site_id'=>$siteId,'state'=>$state,'bindings'=>$details];
    }
    private static function siteExtension(PDO $pdo,int $siteId,string $key,bool $enabled): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'?'INSERT INTO cms_site_extensions(site_id,extension_key,is_enabled,config_json) VALUES(:site,:key,:enabled,\'{}\') ON CONFLICT(site_id,extension_key) DO UPDATE SET is_enabled=:enabled2,updated_at=CURRENT_TIMESTAMP':'INSERT INTO cms_site_extensions(site_id,extension_key,is_enabled,config_json) VALUES(:site,:key,:enabled,\'{}\') ON CONFLICT(site_id,extension_key) DO UPDATE SET is_enabled=EXCLUDED.is_enabled,updated_at=CURRENT_TIMESTAMP';$args=['site'=>$siteId,'key'=>$key,'enabled'=>$enabled?1:0];if($driver==='sqlite')$args['enabled2']=$enabled?1:0;$pdo->prepare($sql)->execute($args);
    }
    private static function event(PDO $pdo,int $siteId,string $key,string $status,array $details=[]): void
    {
        $pdo->prepare('INSERT INTO vazin_rollout_events(site_id,event_key,status,details_json) VALUES(:site,:key,:status,:details)')->execute(['site'=>$siteId,'key'=>$key,'status'=>$status,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
}
