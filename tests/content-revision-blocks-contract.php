<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'VazinCMS\\')){
        $path=$root.'/src/'.str_replace('\\','/',substr($name,9)).'.php';if(is_file($path))require $path;
    }
});
use VazinCMS\{ContentRevision,EditorAutosave,BlockEditor,SiteScopeMode};
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{
    if(!$ok)throw new RuntimeException('REVISION_BLOCK_QA_FAIL:'.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$refuse=static function(callable $action,string $code)use($check):void{
    try{$action();}catch(Throwable $e){$check($e->getMessage()===$code,$code);return;}
    throw new RuntimeException('REVISION_UNEXPECTED_ALLOW:'.$code);
};
$fixture=static function(bool $scoped):PDO{
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $site=$scoped?'site_id INTEGER NOT NULL,':'';
    $db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT);
        CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
        CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);
        INSERT INTO cms_sites VALUES(1,'active');
        INSERT INTO users VALUES(1,'owner','active'),(2,'admin','active'),(3,'support','active'),(4,'owner','suspended'),(5,'owner','active'),(6,'client','active');
        INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(1,2,'admin'),(1,3,'support'),(1,4,'owner'),(2,5,'owner'),(1,6,'author');");
    if($scoped)$db->exec("INSERT INTO cms_sites VALUES(2,'active')");
    $db->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,'.$site.
        "title TEXT,body TEXT,meta_title TEXT,meta_description TEXT,block_document TEXT,block_schema_version INTEGER DEFAULT 1,
        slug TEXT,locale TEXT,status TEXT,trash_status TEXT DEFAULT 'active',is_home INTEGER DEFAULT 0,author_id INTEGER,content_type TEXT,
        canonical_url TEXT,featured_image TEXT,og_title TEXT,og_description TEXT,robots_index INTEGER,robots_follow INTEGER,published_at TEXT,schema_type TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE TABLE cms_page_revisions(id INTEGER PRIMARY KEY AUTOINCREMENT,'.$site.
        "page_id INTEGER,title TEXT,body TEXT,meta_title TEXT,meta_description TEXT,created_by INTEGER,revision_kind TEXT,content_hash TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE TABLE cms_autosaves(id INTEGER PRIMARY KEY,'.$site.
        'page_id INTEGER,actor_id INTEGER,content_hash TEXT,title TEXT,body TEXT,metadata_json TEXT,saved_at TEXT,UNIQUE(page_id,actor_id))');
    $tables=(new ReflectionClass(SiteScopeMode::class))->getReflectionConstant('TABLES')->getValue();
    foreach($tables as $t){if(!in_array($t,['cms_pages','cms_page_revisions','cms_autosaves'],true))$db->exec('CREATE TABLE '.$t.'(id INTEGER PRIMARY KEY'.($scoped?',site_id INTEGER':'').')');}
    return $db;
};
$docA=['schema'=>1,'blocks'=>[['type'=>'columns','data'=>['columns'=>[
    ['blocks'=>[['type'=>'heading','data'=>['text'=>'نسخه الف','level'=>3]]]],
    ['blocks'=>[['type'=>'image','data'=>['src'=>'/uploads/original.webp','alt'=>'تصویر فارسی','custom'=>['nested'=>true]]],['type'=>'paragraph','data'=>['text'=>'متن <script>plain</script>']]]]
]]]]];
$docB=$docA;$docB['blocks'][0]['data']['columns'][0]['blocks'][0]['data']['text']='نسخه ب';
foreach([false,true] as $scoped){
    $db=$fixture($scoped);$mode=$scoped?'scoped':'legacy';
    $a=BlockEditor::encode($docA);$b=BlockEditor::encode($docB);
    $q=$db->prepare('INSERT INTO cms_pages(id,'.($scoped?'site_id,':'').
        "title,body,meta_title,meta_description,block_document,slug,locale,status,author_id,content_type,canonical_url,featured_image,og_title,robots_index,published_at) VALUES(10,".($scoped?'1,':'').
        "'Title A','Body A','SEO A','Description A',:doc,'same-slug','fa','published',1,'page','https://qa.invalid/kept','/uploads/kept.webp','Keep OG',0,'2026-11-01 12:00:00')");$q->execute(['doc'=>$a]);$q->closeCursor();
    $service=new ContentRevision($db,1);$old=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();
    ContentRevision::record($db,10,1,'manual',$old,$scoped?1:null);
    $id=(int)$db->lastInsertId();
    $metadata=json_decode((string)$db->query('SELECT metadata_json FROM cms_page_revisions WHERE id='.$id)->fetchColumn(),true);
    $check($metadata['block_document']===$a&&$metadata['revision_schema']===1,$mode.'_full_nested_snapshot_saved');
    $db->prepare("UPDATE cms_pages SET title='Title B',body='Body B',meta_title='SEO B',meta_description='Description B',block_document=:b WHERE id=10")->execute(['b'=>$b]);
    $current=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();$base=EditorAutosave::baseRevision($current);
    $listed=$service->listing(1,10);
    $check(count($listed)===1&&$listed[0]['snapshot_state']==='complete'&&$listed[0]['base_revision']===$base,$mode.'_history_is_page_scoped_and_version_guarded');
    $restore=$service->restore(1,$id,$base);
    $row=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();
    $check($restore===10&&$row['title']==='Title A'&&$row['body']==='Body A'&&$row['meta_title']==='SEO A',$mode.'_title_body_seo_restored');
    $check(json_decode($row['block_document'],true)===$docA&&$row['block_schema_version']===1,$mode.'_exact_nested_columns_and_image_metadata_restored');
    $check($row['status']==='published'&&$row['slug']==='same-slug'&&$row['locale']==='fa'&&$row['author_id']==1&&$row['canonical_url']===$old['canonical_url']&&$row['featured_image']===$old['featured_image']&&$row['og_title']===$old['og_title']&&$row['published_at']===$old['published_at'],$mode.'_publication_ownership_and_other_metadata_unchanged');
    $backup=$db->query("SELECT * FROM cms_page_revisions WHERE page_id=10 AND revision_kind='restore' ORDER BY id DESC LIMIT 1")->fetch();
    $backupPayload=ContentRevision::payload($backup);
    $check($backupPayload['title']==='Title B'&&$backupPayload['block_document']===$b,$mode.'_complete_pre_restore_backup');
    $rows=(int)$db->query('SELECT COUNT(*) FROM cms_page_revisions')->fetchColumn();
    $refuse(fn()=>$service->restore(1,$id,$base),'revision_page_changed');
    $check($db->query('SELECT * FROM cms_pages WHERE id=10')->fetch()===$row&&(int)$db->query('SELECT COUNT(*) FROM cms_page_revisions')->fetchColumn()===$rows,$mode.'_stale_restore_has_zero_mutation');
    $refuse(fn()=>$service->restore(3,$id,EditorAutosave::baseRevision($row)),'revision_access_denied');
    $refuse(fn()=>$service->restore(4,$id,EditorAutosave::baseRevision($row)),'revision_access_denied');
    $refuse(fn()=>$service->restore(1,9999,EditorAutosave::baseRevision($row)),'revision_unavailable');
    if($scoped){
        $other=new ContentRevision($db,2);
        $check($other->listing(5)===[],'tenant_B_sees_no_A_revisions');
        $refuse(fn()=>$other->restore(5,$id,EditorAutosave::baseRevision($row)),'revision_unavailable');
        $refuse(fn()=>$service->restore(5,$id,EditorAutosave::baseRevision($row)),'revision_access_denied');
        $refuse(fn()=>$service->restore(6,$id,EditorAutosave::baseRevision($row)),'revision_unavailable');
    }
    $service->restore(1,(int)$backup['id'],EditorAutosave::baseRevision($row));
    $restoredB=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();
    $check($restoredB['title']==='Title B'&&$restoredB['block_document']===$b,$mode.'_restore_operation_can_itself_be_reversed');
    $db->exec("UPDATE cms_page_revisions SET metadata_json='{broken' WHERE id=".$id);
    $refuse(fn()=>$service->restore(1,$id,EditorAutosave::baseRevision($restoredB)),'revision_snapshot_invalid');
    $check($db->query('SELECT * FROM cms_pages WHERE id=10')->fetch()===$restoredB,$mode.'_corrupt_revision_no_page_mutation');
    $check($service->listing(1,10)[count($service->listing(1,10))-1]['snapshot_state']==='invalid',$mode.'_corrupt_history_item_disabled');
    $legacySql='INSERT INTO cms_page_revisions('.($scoped?'site_id,':'').'page_id,title,body,meta_title,meta_description,created_by,revision_kind,content_hash,metadata_json) VALUES('.($scoped?'1,':'')."10,'Legacy title','Legacy text','Legacy SEO','Legacy description',1,'manual','','{}')";
    $db->exec($legacySql);$legacyId=(int)$db->lastInsertId();
    $check($service->listing(1,10)[0]['snapshot_state']==='legacy',$mode.'_old_text_only_history_honestly_labelled');
    $service->restore(1,$legacyId,EditorAutosave::baseRevision($restoredB));
    $legacy=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();
    $check($legacy['block_document']===BlockEditor::encode(BlockEditor::legacy('Legacy text')),$mode.'_old_revision_rebuilds_matching_blocks_not_stale_new_layout');
    $db->exec("UPDATE cms_pages SET trash_status='trash' WHERE id=10");
    $refuse(fn()=>$service->restore(1,$legacyId,EditorAutosave::baseRevision($legacy)),'revision_unavailable');
    $check($service->listing(1)===[],$mode.'_trashed_page_history_not_exposed');
}
$sample=['title'=>'A','body'=>'B','meta_title'=>'','meta_description'=>'','content_hash'=>'','metadata_json'=>json_encode(['revision_schema'=>99])];
$refuse(fn()=>ContentRevision::payload($sample),'revision_snapshot_invalid');
$sample['metadata_json']='{}';$sample['content_hash']=str_repeat('f',64);
$refuse(fn()=>ContentRevision::payload($sample),'revision_snapshot_invalid');
echo 'CONTENT_REVISION_BLOCKS=PASS checks='.$count.' full_document,integrity,tenant_isolation,stale_guard,pre_restore_backup,legacy_fallback'.PHP_EOL;
