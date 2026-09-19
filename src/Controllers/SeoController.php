<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Audit,Auth,ContentAdvisoryService,Database,EditorialPlanner,Security,View};

final class SeoController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner','admin']);
        $error = null; $message = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string)($_POST['action'] ?? 'analyze_all');
                if ($action === 'analyze_all') {
                    $result = ContentAdvisoryService::analyzeAll(1000); EditorialPlanner::refresh();
                    $message = $result['analyzed'].' صفحه تحلیل شد و '.$result['new_notices'].' اخطار تازه ساخته شد.';
                    Audit::log('seo.analyzed','تحلیل سئو و محتوا اجرا شد',(int)$user['id'],$result);
                } elseif ($action === 'advisory_status') {
                    ContentAdvisoryService::setStatus((int)($_POST['id']??0),(string)($_POST['status']??''));
                    $message = 'وضعیت اخطار ذخیره شد.';
                    Audit::log('content_advisory.status_changed','وضعیت اخطار محتوایی تغییر کرد',(int)$user['id'],['id'=>(int)($_POST['id']??0),'status'=>(string)($_POST['status']??'')]);
                } elseif ($action === 'suggestion_status') {
                    EditorialPlanner::setStatus((int)($_POST['id']??0),(string)($_POST['status']??'')); $message = 'وضعیت پیشنهاد انتشار ذخیره شد.';
                } elseif ($action === 'save_cadence') {
                    EditorialPlanner::saveCadence((int)($_POST['cadence_days']??3)); EditorialPlanner::refresh(); $message = 'فاصلهٔ پیشنهادی انتشار ذخیره شد.';
                } else throw new \RuntimeException('عملیات درخواستی معتبر نیست.');
            } catch (\Throwable $exception) { $error = $exception->getMessage(); }
        }
        try { EditorialPlanner::refresh(); } catch (\Throwable) {}
        $pdo = Database::connection();
        $reports = $pdo->query('SELECT r.*,p.title,p.locale,p.slug,p.status FROM seo_reports r JOIN cms_pages p ON p.id=r.page_id ORDER BY r.score ASC,r.analyzed_at DESC LIMIT 300')->fetchAll();
        $advisories = $pdo->query("SELECT a.*,p.title page_title,p.locale,p.slug FROM content_advisories a JOIN cms_pages p ON p.id=a.page_id ORDER BY CASE a.status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END,CASE a.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END,a.last_seen_at DESC LIMIT 500")->fetchAll();
        $suggestions = $pdo->query("SELECT * FROM editorial_suggestions ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,suggested_at ASC LIMIT 100")->fetchAll();
        $cadence = (int)($pdo->query("SELECT setting_value FROM cms_settings WHERE setting_key='publishing_cadence_days'")->fetchColumn()?:3);
        $summary = ['pages'=>(int)$pdo->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn(),'analyzed'=>(int)$pdo->query('SELECT COUNT(*) FROM seo_reports')->fetchColumn(),'open'=>(int)$pdo->query("SELECT COUNT(*) FROM content_advisories WHERE status='open'")->fetchColumn(),'average'=>(int)round((float)($pdo->query('SELECT AVG(score) FROM seo_reports')->fetchColumn()?:0))];
        View::render('seo',compact('user','reports','advisories','suggestions','cadence','summary','error','message'));
    }

    public function sitemap(): void
    {
        $base = $this->publicBaseUrl();
        if ($base === null) { http_response_code(503); return; }
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        $rows = Database::connection()->query("SELECT locale,slug,is_home,updated_at FROM cms_pages WHERE status='published' AND robots_index=1 ORDER BY locale,is_home DESC,id")->fetchAll();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $seen = [];
        foreach ($rows as $row) {
            $path = !empty($row['is_home'])
                ? '/' . rawurlencode((string)$row['locale'])
                : '/' . rawurlencode((string)$row['locale']) . '/page/' . rawurlencode((string)$row['slug']);
            if (isset($seen[$path])) continue;
            $seen[$path] = true;
            $timestamp = strtotime((string)$row['updated_at']);
            $location = htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            echo ' <url><loc>' . $location . '</loc>';
            if ($timestamp !== false) echo '<lastmod>' . gmdate('c',$timestamp) . '</lastmod>';
            echo "</url>\n";
        }
        echo "</urlset>\n";
    }

    public function robots(): void
    {
        $base = $this->publicBaseUrl();
        if ($base === null) { http_response_code(503); return; }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        echo "User-agent: *\nAllow: /\nSitemap: " . $base . "/sitemap.xml\n";
    }

    private function publicBaseUrl(): ?string
    {
        $url = rtrim(trim((string)getenv('APP_URL')), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme']??'')), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        return $url;
    }
}
