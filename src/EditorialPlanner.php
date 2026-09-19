<?php
declare(strict_types=1);

namespace VazinCMS;

final class EditorialPlanner
{
    public static function refresh(): array
    {
        $pdo = Database::connection();
        $cadence = 3;
        try {
            $query = $pdo->prepare("SELECT setting_value FROM cms_settings WHERE setting_key='publishing_cadence_days'");
            $query->execute();
            $cadence = max(1,min(30,(int)($query->fetchColumn()?:3)));
        } catch (\Throwable) {
        }
        $latest = $pdo->query("SELECT id,title,published_at,created_at FROM cms_pages WHERE content_type='post' AND status='published' ORDER BY COALESCE(published_at,created_at) DESC,id DESC LIMIT 1")->fetch();
        $base = is_array($latest) ? strtotime((string)($latest['published_at']?:$latest['created_at'])) : time();
        if ($base === false) $base = time();
        $suggested = max(time()+3600, $base + $cadence*86400);
        $key = 'cadence-' . gmdate('Y-m-d',$suggested);
        $title = is_array($latest) ? 'پیشنهاد انتشار مطلب بعدی' : 'پیشنهاد ساخت نخستین مطلب';
        $rationale = is_array($latest)
            ? 'براساس فاصلهٔ پیشنهادی '.$cadence.' روزه پس از «'.mb_substr((string)$latest['title'],0,100).'».'
            : 'هنوز پست منتشرشده‌ای وجود ندارد؛ یک معرفی کوتاه و دقیق شروع مناسبی است.';
        $pdo->prepare(
            "INSERT INTO editorial_suggestions(suggestion_key,title,rationale,suggested_at,status,details_json) VALUES(:key,:title,:rationale,:at,'open',:details) "
            . "ON CONFLICT(suggestion_key) DO UPDATE SET title=:title2,rationale=:rationale2,suggested_at=:at2,details_json=:details2,updated_at=CURRENT_TIMESTAMP"
        )->execute([
            'key'=>$key,'title'=>$title,'rationale'=>$rationale,'at'=>gmdate('Y-m-d H:i:s',$suggested),
            'details'=>json_encode(['cadence_days'=>$cadence,'last_page_id'=>$latest['id']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'title2'=>$title,'rationale2'=>$rationale,'at2'=>gmdate('Y-m-d H:i:s',$suggested),
            'details2'=>json_encode(['cadence_days'=>$cadence,'last_page_id'=>$latest['id']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        return ['suggestion_key'=>$key,'suggested_at'=>gmdate('c',$suggested),'cadence_days'=>$cadence];
    }

    public static function saveCadence(int $days): void
    {
        $days=max(1,min(30,$days));
        Database::connection()->prepare("INSERT INTO cms_settings(setting_key,setting_value) VALUES('publishing_cadence_days',:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP")
            ->execute(['value'=>(string)$days,'value2'=>(string)$days]);
    }

    public static function setStatus(int $id,string $status):void
    {
        if($id<1||!in_array($status,['accepted','dismissed','completed'],true))throw new \RuntimeException('وضعیت پیشنهاد معتبر نیست.');
        Database::connection()->prepare('UPDATE editorial_suggestions SET status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['status'=>$status,'id'=>$id]);
    }
}
