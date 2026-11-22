<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteScopeMode.php';

use VazinCMS\SiteScopeMode;

$tables=[
    'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
    'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
    'cms_autosaves','cms_search_index','cms_media_collections',
    'cms_media_derivatives','cms_media_collection_items','cms_content_types',
    'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
    'cms_block_patterns','cms_editorial_events','publication_jobs',
    'cms_custom_field_definitions','cms_custom_field_values',
];
$check=static function(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException('SITE_SCOPE_MODE_FAIL: '.$name);
};
$fails=static function(PDO $pdo,string $expected)use($check):void{
    try {SiteScopeMode::current($pdo);} catch(RuntimeException $e){
        $check($e->getMessage()===$expected,'error: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Unexpectedly accepted: '.$expected);
};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);"
    ."INSERT INTO cms_sites VALUES(1,'default','active');");
$fails($db,'tenant_schema_missing_table');
foreach($tables as $table) {
    $db->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY)');
}
$check(SiteScopeMode::current($db)==='legacy','valid single-site legacy baseline');
$db->exec('ALTER TABLE cms_pages ADD COLUMN site_id INTEGER');
$fails($db,'tenant_schema_partially_migrated');
foreach(array_slice($tables,1) as $table) {
    $db->exec('ALTER TABLE "'.$table.'" ADD COLUMN site_id INTEGER');
}
$check(SiteScopeMode::current($db)==='scoped','fully migrated schema');
$old=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$old->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);"
    ."INSERT INTO cms_sites VALUES(1,'default','active'),(2,'beta','active');");
foreach($tables as $table)$old->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY)');
$fails($old,'tenant_legacy_multisite_denied');
echo "SITE_SCOPE_MODE_CONTRACT=PASS legacy_single_site,partial_fails_closed,full_scoped,two_site_legacy_denied\n";
