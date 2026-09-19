<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class ContentAdvisoryService
{
    public static function analyzePage(int $pageId, bool $notify = true): array
    {
        $pdo = Database::connection();
        $query = $pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');
        $query->execute(['id'=>$pageId]);
        $page = $query->fetch();
        if (!is_array($page)) throw new RuntimeException('صفحه برای تحلیل پیدا نشد.');
        $report = SeoAnalyzer::analyze($page);
        $issuesJson = json_encode($report['issues'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO seo_reports(page_id,score,content_hash,issues_json,analyzed_at) VALUES(:page,:score,:hash,:issues,CURRENT_TIMESTAMP) '
            . 'ON CONFLICT(page_id) DO UPDATE SET score=:score2,content_hash=:hash2,issues_json=:issues2,analyzed_at=CURRENT_TIMESTAMP'
        )->execute([
            'page'=>$pageId,'score'=>$report['score'],'hash'=>$report['content_hash'],'issues'=>$issuesJson,
            'score2'=>$report['score'],'hash2'=>$report['content_hash'],'issues2'=>$issuesJson,
        ]);

        $existingStatement = $pdo->prepare('SELECT * FROM content_advisories WHERE page_id=:page');
        $existingStatement->execute(['page'=>$pageId]);
        $existing = [];
        foreach ($existingStatement->fetchAll() as $row) $existing[(string)$row['rule_key']] = $row;
        $activeRules = [];
        $newNotices = [];
        foreach ($report['issues'] as $issue) {
            $rule = (string)$issue['rule'];
            $activeRules[] = $rule;
            $fingerprint = hash('sha256', $rule . "\0" . $issue['severity'] . "\0" . $issue['message'] . "\0" . json_encode($issue['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $previous = $existing[$rule] ?? null;
            $pdo->prepare(
                'INSERT INTO content_advisories(page_id,rule_key,severity,category,title,message,details_json,status,fingerprint) '
                . "VALUES(:page,:rule,:severity,:category,:title,:message,:details,'open',:fingerprint) "
                . "ON CONFLICT(page_id,rule_key) DO UPDATE SET severity=:severity2,category=:category2,title=:title2,message=:message2,details_json=:details2,status=CASE WHEN content_advisories.fingerprint<>:fingerprint2 THEN 'open' ELSE content_advisories.status END,fingerprint=:fingerprint3,last_seen_at=CURRENT_TIMESTAMP,resolved_at=CASE WHEN content_advisories.fingerprint<>:fingerprint4 THEN NULL ELSE content_advisories.resolved_at END"
            )->execute([
                'page'=>$pageId,'rule'=>$rule,'severity'=>$issue['severity'],'category'=>$issue['category'],'title'=>$issue['title'],'message'=>$issue['message'],
                'details'=>json_encode($issue['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'fingerprint'=>$fingerprint,
                'severity2'=>$issue['severity'],'category2'=>$issue['category'],'title2'=>$issue['title'],'message2'=>$issue['message'],
                'details2'=>json_encode($issue['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                'fingerprint2'=>$fingerprint,'fingerprint3'=>$fingerprint,'fingerprint4'=>$fingerprint,
            ]);
            $isNew = !is_array($previous) || !hash_equals((string)$previous['fingerprint'], $fingerprint);
            if ($isNew && in_array($issue['severity'], ['warning','critical'], true)) {
                $notice = $issue + ['page_id'=>$pageId,'fingerprint'=>$fingerprint];
                $newNotices[] = $notice;
                self::adminNotification($notice);
            }
        }
        if ($activeRules === []) {
            $pdo->prepare("UPDATE content_advisories SET status='resolved',resolved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE page_id=:page AND status IN ('open','acknowledged')")
                ->execute(['page'=>$pageId]);
        } else {
            $placeholders = implode(',', array_fill(0,count($activeRules),'?'));
            $resolve = $pdo->prepare("UPDATE content_advisories SET status='resolved',resolved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE page_id=? AND rule_key NOT IN ($placeholders) AND status IN ('open','acknowledged')");
            $resolve->execute(array_merge([$pageId],$activeRules));
        }
        if ($notify) foreach ($newNotices as $notice) TelegramSyncService::notifyManagers($notice);
        return $report + ['new_notices'=>count($newNotices)];
    }

    public static function analyzeAll(int $limit = 100): array
    {
        $limit = max(1,min(1000,$limit));
        $ids = Database::connection()->query('SELECT id FROM cms_pages ORDER BY updated_at DESC,id DESC LIMIT '.$limit)->fetchAll();
        $result = ['analyzed'=>0,'failed'=>0,'new_notices'=>0];
        foreach ($ids as $row) {
            try {
                $report = self::analyzePage((int)$row['id']);
                $result['analyzed']++;
                $result['new_notices'] += (int)$report['new_notices'];
            } catch (\Throwable) { $result['failed']++; }
        }
        return $result;
    }

    public static function setStatus(int $id, string $status): void
    {
        if ($id < 1 || !in_array($status,['acknowledged','resolved','ignored'],true)) throw new RuntimeException('وضعیت اخطار معتبر نیست.');
        $statement = Database::connection()->prepare('UPDATE content_advisories SET status=:status,resolved_at=CASE WHEN :status2 IN (\'resolved\',\'ignored\') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=:id');
        $statement->execute(['status'=>$status,'status2'=>$status,'id'=>$id]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('اخطار پیدا نشد.');
    }

    public static function openCount(): int
    {
        try { return (int)Database::connection()->query("SELECT COUNT(*) FROM content_advisories WHERE status='open'")->fetchColumn(); }
        catch (\Throwable) { return 0; }
    }

    private static function adminNotification(array $notice): void
    {
        $owners = Database::connection()->query("SELECT id FROM users WHERE role IN ('owner','admin') AND status='active'")->fetchAll();
        foreach ($owners as $owner) {
            Database::connection()->prepare(
                "INSERT INTO notifications(user_id,channel,event_type,dedupe_key,subject,body) VALUES(:user,'admin','content_advisory',:dedupe,:subject,:body) ON CONFLICT(dedupe_key) DO NOTHING"
            )->execute([
                'user'=>$owner['id'],'dedupe'=>'advisory-'.$owner['id'].'-'.$notice['page_id'].'-'.$notice['fingerprint'],
                'subject'=>$notice['title'],'body'=>$notice['message'],
            ]);
        }
    }
}
