<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteScopeMode.php';
require dirname(__DIR__).'/src/SiteScopedOperationGate.php';
use VazinCMS\{SiteScopeMode,SiteScopedOperationGate};

$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('OPERATION_GATE_FAIL: '.$why);
};
$denied=static function(callable $f,string $expected)use($assert):void{
    try{$f();}catch(RuntimeException $e){
        $assert($e->getMessage()===$expected,
            'unexpected code '.$e->getMessage().', expected '.$expected);
        return;
    }
    throw new RuntimeException('OPERATION_GATE_MISSING: '.$expected);
};
$tables=[
    'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
    'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
    'cms_autosaves','cms_search_index','cms_media_collections',
    'cms_media_derivatives','cms_media_collection_items','cms_content_types',
    'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
    'cms_block_patterns','cms_editorial_events','publication_jobs',
    'cms_custom_field_definitions','cms_custom_field_values',
];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);');
$db->exec("INSERT INTO cms_sites VALUES(1,'default','active');");
foreach($tables as $table)$db->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY)');
$ops=['legacy_scheduler','extension_registry','seo_analysis','editorial_planner',
      'editorial_transition','scheduled_publish','external_publication',
      'telegram_sync','telegram_outbound','media_archive'];
foreach($ops as $op)SiteScopedOperationGate::denyUnscopedWrite($db,$op);
$assert(SiteScopeMode::current($db)==='legacy','one-site legacy');
$db->exec('ALTER TABLE cms_pages ADD COLUMN site_id INTEGER');
$denied(fn()=>SiteScopedOperationGate::denyUnscopedWrite($db,'scheduled_publish'),
    'tenant_schema_partially_migrated');
foreach(array_slice($tables,1) as $table)
    $db->exec('ALTER TABLE "'.$table.'" ADD COLUMN site_id INTEGER');
$assert(SiteScopeMode::current($db)==='scoped','scoped mode');
foreach($ops as $op)
    $denied(fn()=>SiteScopedOperationGate::denyUnscopedWrite($db,$op),
        'tenant_'.$op.'_requires_site_isolation');
$denied(fn()=>SiteScopedOperationGate::denyUnscopedWrite($db,'unknown_operation'),
    'tenant_unrecognized_operation');

$root=dirname(__DIR__).'/src/';
$expect=[
    'ExtensionManager.php'=>['extension_registry'],
    'EditorialPlanner.php'=>['editorial_planner'],
    'ContentAdvisoryService.php'=>['seo_analysis'],
    'EditorialWorkflow.php'=>['editorial_transition'],
    'Scheduler.php'=>['scheduled_publish','legacy_scheduler'],
    'PublicationService.php'=>['external_publication'],
    'TelegramSyncService.php'=>['telegram_sync','telegram_outbound'],
    'MediaArchiveImporter.php'=>['media_archive']
];
foreach($expect as $file=>$operations) {
    $source=file_get_contents($root.$file);
    $assert(is_string($source),$file.' unreadable');
    foreach($operations as $operation) {
        $assert(str_contains($source,"'".$operation."'"),
                $file.' not wired to '.$operation);
    }
}
$runner=file_get_contents(dirname(__DIR__).'/scripts/run-scheduler.php');
$guard=strpos((string)$runner,"'legacy_scheduler'");
$firstGlobal=strpos((string)$runner,'ExtensionManager::isActive(');
$assert($guard!==false && $firstGlobal!==false && $guard<$firstGlobal,
    'global scheduler script must fail before extension loading or side effects');
echo "SITE_SCOPED_OPERATION_GATES=PASS legacy,partial_schema,10_unsafe_operations,source_contracts\n";
