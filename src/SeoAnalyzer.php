<?php
declare(strict_types=1);

namespace VazinCMS;

final class SeoAnalyzer
{
    public static function analyze(array $page): array
    {
        $title = trim((string)($page['title'] ?? ''));
        $body = trim((string)($page['body'] ?? ''));
        $metaTitle = trim((string)($page['meta_title'] ?? ''));
        $description = trim((string)($page['meta_description'] ?? ''));
        $canonical = trim((string)($page['canonical_url'] ?? ''));
        $issues = [];
        $score = 100;
        $add = static function(string $rule, string $severity, string $category, string $title, string $message, int $penalty, array $details = []) use (&$issues, &$score): void {
            $issues[] = compact('rule','severity','category','title','message','penalty','details');
            $score -= $penalty;
        };

        $titleLength = mb_strlen($metaTitle !== '' ? $metaTitle : $title);
        if ($metaTitle === '') $add('seo.meta_title_missing','info','seo','عنوان SEO اختصاصی نیست','در نبود عنوان SEO، عنوان اصلی صفحه استفاده می‌شود؛ یک عنوان دقیق‌تر می‌تواند نرخ کلیک را بهتر کند.',8);
        if ($titleLength < 20) $add('seo.title_short','info','seo','عنوان برای نتیجهٔ جست‌وجو کوتاه است','عنوان را روشن‌تر و توصیفی‌تر کنید؛ این فقط پیشنهاد است و انتشار را متوقف نمی‌کند.',5,['length'=>$titleLength]);
        if ($titleLength > 65) $add('seo.title_long','warning','seo','عنوان SEO احتمالاً بریده می‌شود','عنوان نتیجهٔ جست‌وجو بهتر است نزدیک به ۶۰ نویسه باشد.',7,['length'=>$titleLength]);

        $descriptionLength = mb_strlen($description);
        if ($description === '') $add('seo.description_missing','warning','seo','توضیحات SEO خالی است','یک خلاصهٔ واقعی و غیراغراق‌آمیز برای نتیجهٔ جست‌وجو بنویسید.',12);
        elseif ($descriptionLength < 70) $add('seo.description_short','info','seo','توضیحات SEO کوتاه است','خلاصه را کمی کامل‌تر کنید تا موضوع صفحه روشن باشد.',4,['length'=>$descriptionLength]);
        elseif ($descriptionLength > 170) $add('seo.description_long','info','seo','توضیحات SEO طولانی است','بخش انتهایی توضیحات ممکن است در موتور جست‌وجو نمایش داده نشود.',4,['length'=>$descriptionLength]);

        $plain = trim(preg_replace('/\s+/u',' ',strip_tags($body)) ?? $body);
        $bodyLength = mb_strlen($plain);
        if ($bodyLength < 250) $add('content.too_short','warning','content','محتوا برای یک صفحهٔ مستقل کوتاه است','در صورت امکان زمینه، جزئیات، منبع یا پاسخ پرسش‌های رایج را اضافه کنید.',12,['length'=>$bodyLength]);
        if ($bodyLength > 400 && preg_match('/(?:^|\R)#{2,3}\s+|<h[23]\b/iu', $body) !== 1) {
            $add('content.structure','info','content','ساختار میانی محتوا مشخص نیست','برای خوانایی، بخش‌های طولانی را با میان‌عنوان جدا کنید.',4);
        }
        if (($page['content_type'] ?? 'page') === 'post' && trim((string)($page['featured_image'] ?? '')) === '') {
            $add('seo.featured_image_missing','info','seo','تصویر شاخص تنظیم نشده است','تصویر شاخص مناسب، نمایش در شبکه‌های اجتماعی را کامل‌تر می‌کند.',4);
        }
        if ($canonical !== '' && (!filter_var($canonical, FILTER_VALIDATE_URL) || strtolower((string)parse_url($canonical, PHP_URL_SCHEME)) !== 'https')) {
            $add('seo.canonical_invalid','warning','seo','Canonical نیاز به بازبینی دارد','نشانی canonical باید یک URL کامل HTTPS باشد.',10);
        }

        $absoluteClaims = '/(?:درمان\s*قطعی|صددرصد\s*(?:درمان|تضمین)|معجزه|بدون\s*هیچ\s*عارضه|جایگزین\s*(?:دارو|پزشک)|cure\s*all|guaranteed\s*cure|miracle\s*cure|без\s*побочных\s*эффектов)/iu';
        if (preg_match($absoluteClaims, $title . "\n" . $body) === 1) {
            $add('medical.absolute_claim','warning','medical','ادعای قطعی سلامت نیاز به بازبینی مدیر دارد','عبارت قطعی یا تضمینی شناسایی شد. منبع، محدودهٔ ادعا و توضیح مناسب را بررسی کنید؛ سامانه محتوا را حذف یا مسدود نمی‌کند.',15);
        }
        $healthTerms = '/(?:درمان|بیماری|دارو|سم\s*زدایی|سرطان|دیابت|فشار\s*خون|پزشک|medical|treatment|disease|medicine|лечени|болезн|лекарств)/iu';
        $sourceTerms = '/(?:منبع|مطالعه|پژوهش|doi|pubmed|پزشک|متخصص|source|study|research|исследован|источник)/iu';
        if (preg_match($healthTerms, $body) === 1 && preg_match($sourceTerms, $body) !== 1) {
            $add('medical.source_context','info','medical','زمینه یا منبع پزشکی دیده نشد','اگر متن توصیهٔ سلامت ارائه می‌کند، منبع یا حدود مسئولیت را برای تصمیم آگاهانهٔ مخاطب اضافه کنید.',6);
        }
        if (preg_match('/(?:همین\s*الان\s*بخر|آخرین\s*فرصت|تضمین\s*سود|بدون\s*ریسک|guaranteed\s*profit|risk[- ]free)/iu', $body) === 1) {
            $add('content.high_pressure_claim','warning','content','عبارت تبلیغاتی پرفشار شناسایی شد','مدیر بهتر است فوریت یا تضمین مطرح‌شده را با شرایط واقعی کسب‌وکار تطبیق دهد.',8);
        }

        return [
            'score'=>max(0,min(100,$score)),
            'content_hash'=>hash('sha256', json_encode([$title,$body,$metaTitle,$description,$canonical,$page['featured_image']??''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'issues'=>$issues,
        ];
    }
}
