<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class AiCmsService
{
    private const PURPOSES=['draft','rewrite','translate','seo','taxonomy','internal_links','semantic_search'];

    public static function policy(PDO $pdo,int $siteId): array
    {
        $q=$pdo->prepare('SELECT * FROM cms_ai_policies WHERE site_id=:site');$q->execute(['site'=>$siteId]);$r=$q->fetch();
        return is_array($r)?$r:['site_id'=>$siteId,'connector_key'=>'vazin-ai','model_key'=>'','monthly_request_limit'=>1000,'allow_drafting'=>1,'allow_translation'=>1,'allow_semantic_search'=>1,'require_human_review'=>1,'settings_json'=>'{}'];
    }

    public static function request(PDO $pdo,int $siteId,?int $userId,?int $pageId,string $purpose,array $input,string $requestKey): array
    {
        if(!in_array($purpose,self::PURPOSES,true))throw new RuntimeException('Unsupported AI purpose.');
        $policy=self::policy($pdo,$siteId);self::assertAllowed($pdo,$siteId,$purpose,$policy);
        if(!preg_match('/^[A-Za-z0-9._:-]{8,180}$/',$requestKey))throw new RuntimeException('Invalid AI request key.');
        $existing=$pdo->prepare('SELECT * FROM cms_ai_requests WHERE request_key=:key');$existing->execute(['key'=>$requestKey]);if($row=$existing->fetch())return self::present($row);
        $connector=self::connector($pdo,(string)$policy['connector_key']);if(!$connector)throw new RuntimeException('AI connector is not configured.');
        $inputHash=hash('sha256',json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$model=(string)$policy['model_key'];
        $pdo->prepare("INSERT INTO cms_ai_requests(site_id,user_id,page_id,request_key,purpose,model_key,input_hash,status) VALUES(:site,:user,:page,:key,:purpose,:model,:hash,'pending')")->execute(['site'=>$siteId,'user'=>$userId,'page'=>$pageId,'key'=>$requestKey,'purpose'=>$purpose,'model'=>$model,'hash'=>$inputHash]);$id=(int)$pdo->lastInsertId();
        $payload=['purpose'=>$purpose,'model'=>$model,'input'=>$input,'site_id'=>$siteId,'page_id'=>$pageId];
        $result=ConnectorRuntime::request($pdo,(int)$connector['id'],'POST','/v1/cms/assist',['json'=>$payload,'tenant'=>SiteContext::key(),'idempotency_key'=>$requestKey]);
        if(!$result['ok']){$pdo->prepare("UPDATE cms_ai_requests SET status='failed',error_message=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['error'=>mb_substr((string)($result['error']['message']??'AI request failed'),0,1000),'id'=>$id]);return['ok'=>false,'request_id'=>$id,'status'=>'failed'];}
        $output=is_array($result['data'])?$result['data']:['text'=>(string)$result['data']];$status=!empty($policy['require_human_review'])&&$purpose!=='semantic_search'?'review_required':'completed';
        $pdo->prepare('UPDATE cms_ai_requests SET output_json=:output,status=:status,usage_json=:usage,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['output'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'status'=>$status,'usage'=>json_encode(['attempts'=>$result['attempts']??1,'latency_ms'=>$result['latency_ms']??null]),'id'=>$id]);
        CacheStore::set(SiteContext::cacheKey('ai:'.$requestKey),$output,3600,['ai']);$row=$pdo->query('SELECT * FROM cms_ai_requests WHERE id='.(int)$id)->fetch();return self::present($row);
    }

    public static function review(PDO $pdo,int $requestId,int $reviewerId,string $decision,string $note=''): void
    {
        if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Invalid AI review decision.');
        $q=$pdo->prepare("UPDATE cms_ai_requests SET status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='review_required'");$q->execute(['status'=>$decision,'id'=>$requestId]);if($q->rowCount()!==1)throw new RuntimeException('AI request is not awaiting review.');$pdo->prepare('INSERT INTO cms_ai_reviews(request_id,reviewer_id,decision,note) VALUES(:request,:reviewer,:decision,:note)')->execute(['request'=>$requestId,'reviewer'=>$reviewerId,'decision'=>$decision,'note'=>mb_substr($note,0,1000)]);
    }
    private static function assertAllowed(PDO $pdo,int $siteId,string $purpose,array $policy): void
    {
        if(in_array($purpose,['draft','rewrite','seo','taxonomy','internal_links'],true)&&empty($policy['allow_drafting']))throw new RuntimeException('AI drafting is disabled for this site.');
        if($purpose==='translate'&&empty($policy['allow_translation']))throw new RuntimeException('AI translation is disabled for this site.');
        if($purpose==='semantic_search'&&empty($policy['allow_semantic_search']))throw new RuntimeException('Semantic search is disabled for this site.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM cms_ai_requests WHERE site_id=:site AND created_at>=datetime('now','start of month')");try{$q->execute(['site'=>$siteId]);$count=(int)$q->fetchColumn();}catch(\Throwable){$q=$pdo->prepare("SELECT COUNT(*) FROM cms_ai_requests WHERE site_id=:site AND created_at>=date_trunc('month',CURRENT_TIMESTAMP)");$q->execute(['site'=>$siteId]);$count=(int)$q->fetchColumn();}
        if($count>=(int)$policy['monthly_request_limit'])throw new RuntimeException('Monthly AI request limit reached.');
    }

    private static function connector(PDO $pdo,string $key): ?array
    {
        $q=$pdo->prepare("SELECT * FROM service_connectors WHERE connector_key=:key AND status IN('ready','degraded') LIMIT 1");$q->execute(['key'=>$key]);$r=$q->fetch();return is_array($r)?$r:null;
    }

    private static function present(array $row): array
    {
        return['ok'=>!in_array($row['status'],['failed','rejected'],true),'request_id'=>(int)$row['id'],'status'=>$row['status'],'purpose'=>$row['purpose'],'output'=>json_decode((string)$row['output_json'],true)?:[],'error'=>$row['error_message']??null];
    }
}
