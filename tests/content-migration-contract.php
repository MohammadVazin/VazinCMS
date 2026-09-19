<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/ContentMigrationService.php';
use VazinCMS\ContentMigrationService;

$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$wxr = '<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><item><title>آزمون</title><link>https://example.test/news/safe-post</link><category domain="category" nicename="news">اخبار</category><content:encoded><![CDATA[<script>alert(1)</script><p>متن سالم</p>]]></content:encoded><wp:post_id>42</wp:post_id><wp:post_name>safe-post</wp:post_name><wp:post_type>post</wp:post_type></item></channel></rss>';
$preview = ContentMigrationService::preview('wordpress.xml', $wxr);
$expect($preview['provider'] === 'wordpress-wxr', 'WordPress provider was not detected.');
$expect(count($preview['items']) === 1, 'WordPress item was not extracted.');
$expect($preview['items'][0]['slug'] === 'safe-post', 'WordPress slug was not retained.');
$expect(!str_contains($preview['items'][0]['body'], 'alert'), 'Active content was not removed.');
$expect($preview['items'][0]['source_path'] === '/news/safe-post', 'Legacy path was not retained.');
$expect($preview['items'][0]['terms'][0]['taxonomy'] === 'category', 'WordPress category was not retained.');
$json = ContentMigrationService::preview('portable.json', json_encode(['provider'=>'vazincms','items'=>[['title'=>'صفحهٔ قابل انتقال','source_ref'=>'page:7','body'=>'متن','content_type'=>'page','locale'=>'fa']]], JSON_UNESCAPED_UNICODE));
$expect($json['items'][0]['content_type'] === 'page', 'Portable page type was not retained.');
try { ContentMigrationService::preview('unsafe.xml', '<!DOCTYPE x [ <!ENTITY t SYSTEM "file:///etc/passwd"> ]><x/>'); throw new RuntimeException('Unsafe XML was accepted.'); } catch (InvalidArgumentException) {}
echo "content-migration-contract: OK\n";
