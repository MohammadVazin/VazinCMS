<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO; use RuntimeException;
final class AutomationEngine
{
    public static function transition(PDO $pdo,string$workflowKey,string$entity,int$entityId,string$from,string$to,int$actorId,array$payload=[]): void
    {
        $q=$pdo->prepare("SELECT w.id,t.* FROM cms_workflows w JOIN cms_workflow_transitions t ON t.workflow_id=w.id WHERE w.workflow_key=:key AND w.status='active' AND t.from_state=:from AND t.to_state=:to LIMIT 1");$q->execute(['key'=>$workflowKey,'from'=>$from,'to'=>$to]);$t=$q->fetch();if(!$t)throw new RuntimeException('Workflow transition not allowed.');
        $pdo->prepare('INSERT INTO cms_workflow_events(workflow_id,entity_type,entity_id,from_state,to_state,actor_id,payload_json) VALUES(:workflow,:entity,:id,:from,:to,:actor,:payload)')->execute(['workflow'=>$t['id'],'entity'=>$entity,'id'=>$entityId,'from'=>$from,'to'=>$to,'actor'=>$actorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        self::runActions($pdo,json_decode((string)$t['actions_json'],true)?:[],$payload+['entity_type'=>$entity,'entity_id'=>$entityId,'to_state'=>$to]);
    }
    public static function trigger(PDO $pdo,string$event,array$payload=[]): int
    {
        $q=$pdo->prepare('SELECT * FROM cms_automation_recipes WHERE trigger_event=:event AND is_enabled=1 ORDER BY id');$q->execute(['event'=>$event]);$count=0;foreach($q->fetchAll() as$r){self::runActions($pdo,json_decode((string)$r['actions_json'],true)?:[],$payload);$count++;}return$count;
    }
    private static function runActions(PDO $pdo,array$actions,array$payload): void
    {
        foreach($actions as$a){$type=(string)($a['type']??'');if($type==='webhook'){Webhook::enqueue((string)($a['event']??'webhook.test'),$payload,isset($a['endpoint_id'])?(int)$a['endpoint_id']:null);}elseif($type==='connector'){ConnectorRuntime::request($pdo,(int)($a['connector_id']??0),(string)($a['method']??'POST'),(string)($a['path']??'/'),['json'=>$payload,'idempotency_key'=>(string)($a['idempotency_key']??'')]);}elseif($type==='audit'){Audit::log((string)($a['event']??'automation.action'),(string)($a['message']??'Automation action'),null,$payload);}else{throw new RuntimeException('Unsupported automation action.');}}
    }
}
