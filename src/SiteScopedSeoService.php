<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped SEO analysis and editorial suggestions (candidate-only).
 *
 * Reports/advisories/suggestions are written to NEW site-owned ledgers;
 * legacy global SEO tables remain untouched. No Telegram, email,
 * content publication, external requests or global notifications occur here.
 * SiteIsolationGate continues blocking multi-site HTTP access.
 */
final class SiteScopedSeoService
{
    private readonly PDO $pdo;
    private readonly int $siteId;

    public function __construct(PDO $pdo, int $siteId)
    {
        if ($siteId < 1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_seo_schema_unavailable');
        }
        $this->pdo=$pdo;
        $this->siteId=$siteId;
        $q=$pdo->prepare("SELECT 1 FROM cms_sites WHERE id=:site AND status IN ('active','staging')");
        $q->execute(['site'=>$siteId]);
        if (!$q->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
        $this->verifySchema();
    }

    public function analyzePage(int $actorId, int $pageId): array
    {
        $this->authorize($actorId);
        $q=$this->pdo->prepare(
            'SELECT * FROM cms_pages WHERE site_id=:site AND id=:page ' .
            "AND trash_status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'page'=>$pageId]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        if (!$page)throw new RuntimeException('tenant_content_unavailable');
        $report=SeoAnalyzer::analyze($page);
        $issuesJson=json_encode($report['issues'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $transaction=$this->start();
        try {
            $insert=$this->pdo->prepare(
                'INSERT INTO cms_site_seo_reports(site_id,page_id,score,content_hash,issues_json,analyzed_at) ' .
                'VALUES(:site,:page,:score,:hash,:issues,CURRENT_TIMESTAMP) ' .
                'ON CONFLICT(site_id,page_id) DO UPDATE SET ' .
                'score=excluded.score,content_hash=excluded.content_hash,' .
                'issues_json=excluded.issues_json,analyzed_at=CURRENT_TIMESTAMP ' .
                'WHERE cms_site_seo_reports.site_id=excluded.site_id'
            );
            $insert->execute([
                'site'=>$this->siteId,'page'=>$pageId,'score'=>$report['score'],
                'hash'=>$report['content_hash'],'issues'=>$issuesJson
            ]);
            if ($insert->rowCount()!==1)throw new RuntimeException('tenant_seo_report_conflict');

            $oldQ=$this->pdo->prepare(
                'SELECT rule_key,fingerprint FROM cms_site_content_advisories ' .
                'WHERE site_id=:site AND page_id=:page'
            );
            $oldQ->execute(['site'=>$this->siteId,'page'=>$pageId]);
            $old=[];
            foreach($oldQ->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $old[(string)$entry['rule_key']]=(string)$entry['fingerprint'];
            }
            $newNotices=0;
            $currentRules=[];
            $insertAdvisory=$this->pdo->prepare(
                'INSERT INTO cms_site_content_advisories(' .
                'site_id,page_id,rule_key,severity,category,title,message,details_json,status,fingerprint) ' .
                "VALUES(:site,:page,:rule,:severity,:category,:title,:message,:details,'open',:fingerprint) " .
                'ON CONFLICT(site_id,page_id,rule_key) DO UPDATE SET ' .
                'severity=excluded.severity,category=excluded.category,' .
                'title=excluded.title,message=excluded.message,details_json=excluded.details_json,' .
                "status=CASE WHEN cms_site_content_advisories.fingerprint<>excluded.fingerprint " .
                "THEN 'open' ELSE cms_site_content_advisories.status END," .
                'fingerprint=excluded.fingerprint,last_seen_at=CURRENT_TIMESTAMP,' .
                'resolved_at=CASE WHEN cms_site_content_advisories.fingerprint<>excluded.fingerprint ' .
                'THEN NULL ELSE cms_site_content_advisories.resolved_at END ' .
                'WHERE cms_site_content_advisories.site_id=excluded.site_id'
            );
            foreach($report['issues'] as $issue) {
                $rule=(string)$issue['rule'];
                if($rule==='' || isset($currentRules[$rule]))continue;
                $currentRules[$rule]=true;
                $fingerprint=hash('sha256',
                    $rule."\0".$issue['severity']."\0".$issue['message']."\0".
                    json_encode($issue['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $insertAdvisory->execute([
                    'site'=>$this->siteId,'page'=>$pageId,'rule'=>$rule,
                    'severity'=>$issue['severity'],'category'=>$issue['category'],
                    'title'=>$issue['title'],'message'=>$issue['message'],
                    'details'=>json_encode($issue['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    'fingerprint'=>$fingerprint
                ]);
                if($insertAdvisory->rowCount()!==1)throw new RuntimeException('tenant_seo_advisory_conflict');
                if ((!isset($old[$rule]) || !hash_equals($old[$rule],$fingerprint)) &&
                    in_array($issue['severity'],['warning','critical'],true))$newNotices++;
            }
            $stale=$this->pdo->prepare(
                "UPDATE cms_site_content_advisories SET status='resolved'," .
                'resolved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP ' .
                "WHERE site_id=:site AND page_id=:page AND status IN ('open','acknowledged')"
            );
            if ($currentRules===[]) {
                $stale->execute(['site'=>$this->siteId,'page'=>$pageId]);
            } else {
                $keys=array_keys($currentRules);
                $holders=implode(',',array_fill(0,count($keys),'?'));
                $stmt=$this->pdo->prepare(
                    "UPDATE cms_site_content_advisories SET status='resolved'," .
                    'resolved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP ' .
                    "WHERE site_id=? AND page_id=? AND rule_key NOT IN ($holders) " .
                    "AND status IN ('open','acknowledged')"
                );
                $stmt->execute(array_merge([$this->siteId,$pageId],$keys));
            }
            $this->finish($transaction);
            // No notifications: sending site-owned advisory to global manager
            // lists would disclose content across tenants.
            return $report+['new_notices'=>$newNotices];
        } catch(Throwable $error) {
            $this->abort($transaction);
            throw $error;
        }
    }

    public function analyzeAll(int $actorId, int $limit=100): array
    {
        $this->authorize($actorId);
        $limit=max(1,min(1000,$limit));
        $q=$this->pdo->prepare(
            'SELECT id FROM cms_pages WHERE site_id=:site ' .
            "AND trash_status='active' ORDER BY updated_at DESC,id DESC LIMIT ".$limit
        );
        $q->execute(['site'=>$this->siteId]);
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $stats=['analyzed'=>0,'failed'=>0,'new_notices'=>0];
        foreach($ids as $id) {
            try {
                $report=$this->analyzePage($actorId,(int)$id);
                $stats['analyzed']++;
                $stats['new_notices']+=(int)$report['new_notices'];
            } catch(Throwable) {
                $stats['failed']++;
            }
        }
        return $stats;
    }

    public function setAdvisoryStatus(int $actorId,int $id,string $status): void
    {
        $this->authorize($actorId);
        if($id<1 || !in_array($status,['acknowledged','resolved','ignored'],true)) {
            throw new RuntimeException('tenant_seo_status_invalid');
        }
        $q=$this->pdo->prepare(
            'UPDATE cms_site_content_advisories SET status=:status,' .
            'resolved_at=CASE WHEN :resolved IN (\'resolved\',\'ignored\') ' .
            'THEN CURRENT_TIMESTAMP ELSE NULL END ' .
            'WHERE site_id=:site AND id=:id'
        );
        $q->execute([
            'status'=>$status,'resolved'=>$status,'site'=>$this->siteId,'id'=>$id
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_content_unavailable');
    }

    public function cadence(): int
    {
        $q=$this->pdo->prepare(
            "SELECT setting_value FROM cms_settings WHERE site_id=:site " .
            "AND setting_key='publishing_cadence_days' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId]);
        return max(1,min(30,(int)($q->fetchColumn()?:3)));
    }

    public function saveCadence(int $actorId,int $days): void
    {
        $this->authorize($actorId);
        if(SiteUniqueKeys::target($this->pdo,'cms_settings','setting_key')!==
           '(site_id,setting_key)') {
            throw new RuntimeException('tenant_seo_settings_unique_not_ready');
        }
        $days=max(1,min(30,$days));
        $q=$this->pdo->prepare(
            "INSERT INTO cms_settings(site_id,setting_key,setting_value) " .
            "VALUES(:site,'publishing_cadence_days',:value) " .
            "ON CONFLICT(site_id,setting_key) DO UPDATE SET " .
            "setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP " .
            "WHERE cms_settings.site_id=excluded.site_id"
        );
        $q->execute(['site'=>$this->siteId,'value'=>(string)$days]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_seo_cadence_conflict');
    }

    public function propose(int $actorId): array
    {
        $this->authorize($actorId);
        $cadence=$this->cadence();
        $latestQ=$this->pdo->prepare(
            'SELECT id,title,published_at,created_at FROM cms_pages ' .
            "WHERE site_id=:site AND content_type='post' AND status='published' " .
            "AND trash_status='active' ORDER BY COALESCE(published_at,created_at) DESC,id DESC LIMIT 1"
        );
        $latestQ->execute(['site'=>$this->siteId]);
        $latest=$latestQ->fetch(PDO::FETCH_ASSOC);
        $base=is_array($latest)?strtotime((string)($latest['published_at']?:$latest['created_at'])):time();
        if($base===false)$base=time();
        $suggested=max(time()+3600,$base+$cadence*86400);
        $key='cadence-'.gmdate('Y-m-d',$suggested);
        $title=is_array($latest)?'پیشنهاد انتشار مطلب بعدی':'پیشنهاد ساخت نخستین مطلب';
        $rationale=is_array($latest)
            ? 'براساس فاصلهٔ '.$cadence.' روز پس از «'.mb_substr((string)$latest['title'],0,100).'».'
            : 'برای آغاز یک نوشتهٔ دقیق آماده کنید.';
        $q=$this->pdo->prepare(
            "INSERT INTO cms_site_editorial_suggestions(site_id,suggestion_key,title," .
            "rationale,suggested_at,status,details_json) " .
            "VALUES(:site,:key,:title,:rationale,:suggested,'open',:details) " .
            "ON CONFLICT(site_id,suggestion_key) DO UPDATE SET title=excluded.title," .
            "rationale=excluded.rationale,suggested_at=excluded.suggested_at," .
            "details_json=excluded.details_json,updated_at=CURRENT_TIMESTAMP " .
            "WHERE cms_site_editorial_suggestions.site_id=excluded.site_id"
        );
        $q->execute([
            'site'=>$this->siteId,'key'=>$key,'title'=>$title,
            'rationale'=>$rationale,'suggested'=>gmdate('Y-m-d H:i:s',$suggested),
            'details'=>json_encode([
                'cadence_days'=>$cadence,'last_page_id'=>$latest['id']??null
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_suggestion_write_conflict');
        return ['suggestion_key'=>$key,'suggested_at'=>gmdate('c',$suggested),
                'cadence_days'=>$cadence];
    }

    public function setSuggestionStatus(int $actorId,int $id,string $status): void
    {
        $this->authorize($actorId);
        if($id<1 || !in_array($status,['accepted','dismissed','completed'],true)) {
            throw new RuntimeException('tenant_suggestion_status_invalid');
        }
        $q=$this->pdo->prepare(
            'UPDATE cms_site_editorial_suggestions SET status=:status,' .
            'updated_at=CURRENT_TIMESTAMP WHERE site_id=:site AND id=:id'
        );
        $q->execute(['status'=>$status,'site'=>$this->siteId,'id'=>$id]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_content_unavailable');
    }

    public function dashboard(int $actorId): array
    {
        $this->authorize($actorId);
        $site=['site'=>$this->siteId];
        $reportsQ=$this->pdo->prepare(
            'SELECT r.*,p.title,p.locale,p.slug,p.status FROM cms_site_seo_reports r ' .
            'JOIN cms_pages p ON p.id=r.page_id AND p.site_id=r.site_id ' .
            "WHERE r.site_id=:site AND p.trash_status='active' " .
            'ORDER BY r.score ASC,r.analyzed_at DESC LIMIT 300'
        );
        $reportsQ->execute($site);
        $advisoriesQ=$this->pdo->prepare(
            'SELECT a.*,p.title AS page_title,p.locale,p.slug FROM cms_site_content_advisories a ' .
            'JOIN cms_pages p ON p.id=a.page_id AND p.site_id=a.site_id ' .
            "WHERE a.site_id=:site AND p.trash_status='active' " .
            "ORDER BY CASE a.status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END," .
            "CASE a.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END," .
            'a.last_seen_at DESC LIMIT 500'
        );
        $advisoriesQ->execute($site);
        $suggestionsQ=$this->pdo->prepare(
            'SELECT * FROM cms_site_editorial_suggestions WHERE site_id=:site ' .
            "ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END," .
            'suggested_at ASC LIMIT 100'
        );
        $suggestionsQ->execute($site);
        $countsQ=$this->pdo->prepare(
            "SELECT COUNT(*) AS total FROM cms_pages WHERE site_id=:site AND trash_status='active'"
        );
        $countsQ->execute($site);
        $analyzedQ=$this->pdo->prepare(
            'SELECT COUNT(*) AS analyzed,AVG(score) AS average FROM cms_site_seo_reports ' .
            'WHERE site_id=:site'
        );
        $analyzedQ->execute($site);
        $openQ=$this->pdo->prepare(
            "SELECT COUNT(*) FROM cms_site_content_advisories WHERE site_id=:site AND status='open'"
        );
        $openQ->execute($site);
        $analyzed=$analyzedQ->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'reports'=>$reportsQ->fetchAll(PDO::FETCH_ASSOC),
            'advisories'=>$advisoriesQ->fetchAll(PDO::FETCH_ASSOC),
            'suggestions'=>$suggestionsQ->fetchAll(PDO::FETCH_ASSOC),
            'cadence'=>$this->cadence(),
            'summary'=>[
                'pages'=>(int)$countsQ->fetchColumn(),
                'analyzed'=>(int)($analyzed['analyzed']??0),
                'open'=>(int)$openQ->fetchColumn(),
                'average'=>(int)round((float)($analyzed['average']??0))
            ]
        ];
    }

    private function verifySchema(): void
    {
        try {
            foreach([
                'cms_site_seo_reports'=>'site_id,page_id,score,content_hash',
                'cms_site_content_advisories'=>'site_id,page_id,id,rule_key',
                'cms_site_editorial_suggestions'=>'site_id,id,suggestion_key',
                'cms_site_editorial_events'=>'site_id,page_id,id',
            ] as $table=>$columns) {
                $this->pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0');
            }
            if(SiteUniqueKeys::target($this->pdo,
                'cms_site_editorial_suggestions','suggestion_key')!==
                '(site_id,suggestion_key)') {
                throw new RuntimeException('tenant_editorial_unique_missing');
            }
        } catch(Throwable) {
            throw new RuntimeException('tenant_seo_schema_unavailable');
        }
    }

    private function authorize(int $actorId): void
    {
        if($actorId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            "WHERE m.site_id=:site AND m.user_id=:user AND u.status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'user'=>$actorId]);
        if(!in_array($q->fetchColumn(),['owner','admin','editor'],true)) {
            throw new RuntimeException('tenant_access_denied');
        }
    }

    private function start(): bool
    {
        if($this->pdo->inTransaction())throw new RuntimeException('tenant_seo_ambient_transaction');
        if(!$this->pdo->beginTransaction())throw new RuntimeException('tenant_seo_transaction_failed');
        return true;
    }
    private function finish(bool $started): void
    {
        if($started)$this->pdo->commit();
    }
    private function abort(bool $started): void
    {
        if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();
    }
}
