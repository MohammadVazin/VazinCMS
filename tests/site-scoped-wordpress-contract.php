<?php
declare(strict_types=1);
putenv('SESSION_SECURE=false');
require dirname(__DIR__).'/src/bootstrap.php';

use VazinCMS\{SiteScopedWordPressImporter,SiteScopeMode};

$check=static function(bool $ok,string $message):void {
    if (!$ok)throw new RuntimeException('WXR_TENANT_CONTRACT_FAIL: '.$message);
};
$denied=static function(callable $fn,string $expected,string $label)use($check):void {
    try{$fn();}catch(RuntimeException $e){
        $check($e->getMessage()===$expected,$label.' returned '.$e->getMessage());
        return;
    }
    throw new RuntimeException('WXR_TENANT_CONTRACT_FAIL: unexpectedly accepted '.$label);
};

$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,status TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);
CREATE TABLE cms_pages(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 slug TEXT NOT NULL,locale TEXT NOT NULL,title TEXT,body TEXT,
 status TEXT,is_home INTEGER DEFAULT 0,author_id INTEGER,
 meta_title TEXT,meta_description TEXT,featured_image TEXT,content_type TEXT,
 robots_index INTEGER DEFAULT 0,robots_follow INTEGER DEFAULT 0,
 schema_type TEXT,block_schema_version INTEGER,block_document TEXT,
 trash_status TEXT DEFAULT 'active',editorial_state TEXT DEFAULT 'draft',
 source_provider TEXT,source_ref TEXT,published_at TEXT,
 UNIQUE(site_id,slug,locale),UNIQUE(site_id,id)
);
CREATE TABLE cms_content_types(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,
 content_key TEXT NOT NULL,label TEXT,singular_label TEXT,
 public INTEGER,hierarchical INTEGER,supports_json TEXT,
 UNIQUE(site_id,content_key)
);
CREATE TABLE cms_taxonomies(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,
 taxonomy_key TEXT NOT NULL,label TEXT,singular_label TEXT,
 hierarchical INTEGER,public INTEGER,UNIQUE(site_id,taxonomy_key)
);
CREATE TABLE cms_terms(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,
 taxonomy_id INTEGER REFERENCES cms_taxonomies(id),slug TEXT,name TEXT,
 description TEXT NOT NULL DEFAULT '',UNIQUE(taxonomy_id,slug),UNIQUE(site_id,id)
);
CREATE TABLE cms_term_relationships(
 site_id INTEGER NOT NULL,page_id INTEGER NOT NULL,term_id INTEGER NOT NULL,
 position INTEGER DEFAULT 0,PRIMARY KEY(page_id,term_id),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id),
 FOREIGN KEY(site_id,term_id) REFERENCES cms_terms(site_id,id)
);
CREATE TABLE cms_site_import_jobs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 source_type TEXT NOT NULL,
 source_fingerprint TEXT NOT NULL,
 status TEXT NOT NULL,
 created_by INTEGER NOT NULL REFERENCES users(id),
 options_json TEXT NOT NULL,stats_json TEXT NOT NULL DEFAULT '{}',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,finished_at TEXT,
 UNIQUE(site_id,source_fingerprint),UNIQUE(site_id,id)
);
CREATE TABLE cms_site_import_items(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL,job_id INTEGER NOT NULL,
 source_post_id TEXT NOT NULL,target_page_id INTEGER NOT NULL,
 original_url TEXT DEFAULT '',
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_import_jobs(site_id,id),
 FOREIGN KEY(site_id,target_page_id) REFERENCES cms_pages(site_id,id),
 UNIQUE(site_id,job_id,source_post_id)
);
INSERT INTO cms_sites VALUES(1,'default','active'),(2,'beta','active');
INSERT INTO users VALUES(1,'Owner A','a@example.test','owner','active'),
(2,'Admin B','b@example.test','admin','active'),
(3,'Author A','author@example.test','client','active'),
(4,'Disabled B','disabled@example.test','admin','suspended');
INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'admin'),
(1,3,'author'),(2,4,'admin');
SQL);
foreach([
    'cms_media','cms_menu_items','cms_settings','cms_redirects',
    'cms_autosaves','cms_page_revisions','cms_search_index','cms_media_collections',
    'cms_media_derivatives','cms_media_collection_items','cms_forms',
    'cms_form_fields','cms_form_submissions','cms_reusable_blocks',
    'cms_block_patterns','cms_editorial_events','publication_jobs',
    'cms_custom_field_definitions','cms_custom_field_values'
] as $table) {
    $db->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
}
$check(SiteScopeMode::current($db)==='scoped','proposed content schema not detected');

$wxr=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"
 xmlns:content="http://purl.org/rss/1.0/modules/content/">
 <channel><title>QA WordPress Export</title>
  <item>
   <title>Imported Alpha Story</title><link>https://source.example.test/blog/story</link>
   <wp:post_id>41</wp:post_id><wp:post_type>post</wp:post_type>
   <wp:status>publish</wp:status><wp:post_name>same-import-slug</wp:post_name>
   <content:encoded><![CDATA[<p>Safe article</p><script>alert('never run')</script>]]></content:encoded>
   <category domain="category" nicename="world">World News</category>
   <wp:postmeta><wp:meta_key>_yoast_wpseo_title</wp:meta_key>
      <wp:meta_value>SEO imported title</wp:meta_value></wp:postmeta>
  </item>
  <item>
   <title>Imported About</title><link>https://source.example.test/about</link>
   <wp:post_id>42</wp:post_id><wp:post_type>page</wp:post_type>
   <wp:status>publish</wp:status><wp:post_name>about-import</wp:post_name>
   <content:encoded><![CDATA[<p>About imported</p>]]></content:encoded>
  </item>
  <item><title>Remote attachment</title><wp:post_id>43</wp:post_id>
   <wp:post_type>attachment</wp:post_type>
   <wp:attachment_url>https://outside.example.test/private-file.jpg</wp:attachment_url>
  </item>
 </channel>
</rss>
XML;
$path=tempnam(sys_get_temp_dir(),'wxr-qa-');
$check(is_string($path) && file_put_contents($path,$wxr)>0,'temp WXR file');
try {
    $a=SiteScopedWordPressImporter::importFile($db,$path,1,1,['locale'=>'fa']);
    $check(($a['created']??-1)===2 && ($a['scanned']??-1)===3,'site A counts');
    $check(($a['attachments_skipped']??-1)===1,'remote attachment not skipped');
    $check(($a['published']??-1)===0 && ($a['redirects_created']??-1)===0,
           'unsafe publication or redirects created');
    $check(($a['terms_created']??-1)===1,'taxonomy/category not imported');
    $check(($a['review_required']??false)===true,'human review absent');
    $check(($a['replayed']??null)===false,'first import claimed replay');
    $firstId=(int)$a['job_id'];
    $query=$db->query("SELECT site_id,slug,title,body,status,robots_index,robots_follow,
                       meta_title,source_provider,source_ref FROM cms_pages ORDER BY id LIMIT 1");
    $page=$query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    $check((int)$page['site_id']===1 && $page['slug']==='same-import-slug','page ownership');
    $check($page['status']==='draft' && (int)$page['robots_index']===0,
           'published WordPress source leaked to public');
    $check($page['meta_title']==='SEO imported title','Yoast title not mapped');
    $check(!str_contains($page['body'],'<script>') &&
           str_contains($page['body'],'Safe article'),'unsafe HTML left executable');
    $check($db->query("SELECT COUNT(*) FROM cms_site_import_items")->fetchColumn()===2,
           'site A mappings missing');
    $check($db->query("SELECT COUNT(*) FROM cms_term_relationships")->fetchColumn()===1,
           'site A category mapping missing');

    $again=SiteScopedWordPressImporter::importFile($db,$path,1,1,['locale'=>'fa']);
    $check(($again['replayed']??null)===true && $again['job_id']===$firstId,
           'repeat import did not replay');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn()===2,
           'duplicate pages on retry');

    $b=SiteScopedWordPressImporter::importFile($db,$path,2,2,['locale'=>'fa']);
    $check(($b['created']??-1)===2 && $b['job_id']!==$firstId,
           'same source file blocked for second site');
    $check((int)$db->query("SELECT COUNT(*) FROM cms_pages WHERE slug='same-import-slug'")->fetchColumn()===2,
           'cross-site identical slug not preserved');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_site_import_jobs')->fetchColumn()===2,
           'site importer ledgers shared');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_site_import_items')->fetchColumn()===4,
           'cross-site item ledger not isolated');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_term_relationships')->fetchColumn()===2,
           'cross-site taxonomy mapping lost');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_taxonomies')->fetchColumn()===2,
           'same taxonomy key not separated by site');

    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,2,1,['locale'=>'fa']),
            'tenant_access_denied','cross-site actor');
    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,1,3,['locale'=>'fa']),
            'tenant_access_denied','unauthorized author');
    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,2,4,['locale'=>'fa']),
            'tenant_access_denied','inactive actor');
    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,1,1,['locale'=>'de']),
            'tenant_wxr_locale_invalid','invalid locale');

    file_put_contents($path,'<!DOCTYPE foo [<!ENTITY danger SYSTEM "file:///etc/passwd">]><rss></rss>');
    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,1,1),
            'tenant_wxr_xml_unsafe','external entity');
    $check((int)$db->query('SELECT COUNT(*) FROM cms_site_import_jobs')->fetchColumn()===2,
           'failed validation created job');

    $db->exec('DROP TABLE cms_site_import_items');
    $denied(fn()=>SiteScopedWordPressImporter::importFile($db,$path,1,1),
            'tenant_wxr_ledger_schema_unavailable','unmigrated ledger');
    $check($db->query('PRAGMA foreign_keys')->fetchColumn()===1,'foreign keys disabled');
    echo "SITE_SCOPED_WORDPRESS_WXR=PASS two_sites,draft_only,SEO,terms,attachments_skipped,replay,roles,DTD_denied\n";
} finally {
    @unlink($path);
}
