<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/ContentMigrationService.php';
use VazinCMS\ContentMigrationService;

$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$wxr = '<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><item><title>آزمون</title><link>https://example.test/news/safe-post</link><category domain="category" nicename="news">اخبار</category><content:encoded><![CDATA[<script>alert(1)</script><p>متن سالم</p><img src="https://example.test/media/cover.png">]]></content:encoded><wp:post_id>42</wp:post_id><wp:post_name>safe-post</wp:post_name><wp:post_type>post</wp:post_type></item></channel></rss>';
$preview = ContentMigrationService::preview('wordpress.xml', $wxr);
$expect($preview['provider'] === 'wordpress-wxr', 'WordPress provider was not detected.');
$expect(count($preview['items']) === 1, 'WordPress item was not extracted.');
$expect($preview['items'][0]['slug'] === 'safe-post', 'WordPress slug was not retained.');
$expect(!str_contains($preview['items'][0]['body'], 'alert'), 'Active content was not removed.');
$expect($preview['items'][0]['source_path'] === '/news/safe-post', 'Legacy path was not retained.');
$expect($preview['items'][0]['terms'][0]['taxonomy'] === 'category', 'WordPress category was not retained.');
$expect($preview['items'][0]['featured_source'] === 'https://example.test/media/cover.png', 'Featured media source was not retained.');
$json = ContentMigrationService::preview('portable.json', json_encode(['provider'=>'vazincms','items'=>[['title'=>'صفحهٔ قابل انتقال','source_ref'=>'page:7','body'=>'متن','content_type'=>'page','locale'=>'fa']]], JSON_UNESCAPED_UNICODE));
$expect($json['items'][0]['content_type'] === 'page', 'Portable page type was not retained.');
$ghostExport = ['db' => [['data' => ['tags' => [['id'=>'t1','name'=>'راهنما']], 'posts' => [['id'=>'g1','title'=>'نوشته Ghost','slug'=>'ghost-post','html'=>'<p>متن Ghost</p>','feature_image'=>'https://example.test/media/ghost.png','tags'=>['t1']]]]]]];
$ghost = ContentMigrationService::preview('ghost.json', json_encode($ghostExport, JSON_UNESCAPED_UNICODE));
$expect($ghost['provider'] === 'ghost-json' && $ghost['items'][0]['source_ref'] === 'ghost:g1', 'Ghost export was not detected.');
$expect($ghost['items'][0]['terms'][0]['taxonomy'] === 'tag', 'Ghost tag was not retained.');
$drupalExport = ['data' => [['type'=>'node--article','id'=>'d1','attributes'=>['title'=>'نوشته Drupal','body'=>['value'=>'<p>متن Drupal</p>'],'path'=>['alias'=>'/news/drupal-post'],'field_image'=>['uri'=>['url'=>'https://example.test/media/drupal.png']]]]]];
$drupal = ContentMigrationService::preview('drupal.json', json_encode($drupalExport, JSON_UNESCAPED_UNICODE));
$expect($drupal['provider'] === 'drupal-jsonapi' && $drupal['items'][0]['source_path'] === '/news/drupal-post', 'Drupal JSON:API was not detected.');
$bloggerAtom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><id>tag:blogger.com,1999:blog-1.post-2</id><title>نوشته Blogger</title><link rel="alternate" href="https://example.test/2026/09/blogger-post.html"/><category term="راهنما"/><content type="html"><![CDATA[<p>متن Blogger</p><img src="https://example.test/media/blogger.png">]]></content></entry></feed>';
$blogger = ContentMigrationService::preview('blogger.xml', $bloggerAtom);
$expect($blogger['provider'] === 'blogger-atom' && $blogger['items'][0]['source_path'] === '/2026/09/blogger-post.html', 'Blogger Atom was not detected.');
$expect($blogger['items'][0]['terms'][0]['taxonomy'] === 'tag', 'Blogger labels were not retained.');
$expect($blogger['items'][0]['featured_source'] === 'https://example.test/media/blogger.png', 'Blogger featured media was not retained.');
try { ContentMigrationService::preview('unsafe.xml', '<!DOCTYPE x [ <!ENTITY t SYSTEM "file:///etc/passwd"> ]><x/>'); throw new RuntimeException('Unsafe XML was accepted.'); } catch (InvalidArgumentException) {}
echo "content-migration-contract: OK\n";
