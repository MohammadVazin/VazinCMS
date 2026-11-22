<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'VazinCMS\\')){
        $file=$root.'/src/'.substr($name,9).'.php';if(is_file($file))require $file;
    }
});
use VazinCMS\{EditorAutosave,BlockEditor,SiteScopeMode};
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{
    if(!$ok)throw new RuntimeException('AUTOSAVE_QA_FAIL:'.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$refuse=static function(callable $fn,string $code)use($check):void{
    try{$fn();}catch(Throwable $error){$check($error->getMessage()===$code,$code);return;}
    throw new RuntimeException('AUTOSAVE_UNEXPECTED_ALLOW:'.$code);
};
$fixture=static function(bool $scoped):PDO{
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $siteColumn=$scoped?'site_id INTEGER NOT NULL,':'';
    $db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT);
      CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
      CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);
      INSERT INTO cms_sites VALUES(1,'active');
      INSERT INTO users VALUES(1,'owner','active'),(2,'admin','active'),(3,'support','active'),(4,'owner','suspended'),(5,'owner','active');
      INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(1,2,'admin'),(2,5,'owner');");
    if($scoped)$db->exec("INSERT INTO cms_sites VALUES(2,'active')");
    $db->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,'.$siteColumn.
        "title TEXT,body TEXT,block_document TEXT,block_schema_version INTEGER DEFAULT 1,
        slug TEXT,locale TEXT,status TEXT,trash_status TEXT DEFAULT 'active',is_home INTEGER DEFAULT 0,
        author_id INTEGER,content_type TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE TABLE cms_autosaves(id INTEGER PRIMARY KEY,'.$siteColumn.
        'page_id INTEGER,actor_id INTEGER,content_hash TEXT,title TEXT,body TEXT,metadata_json TEXT,saved_at TEXT,UNIQUE(page_id,actor_id))');
    $tables=(new ReflectionClass(SiteScopeMode::class))->getReflectionConstant('TABLES')->getValue();
    foreach($tables as $table){if(!in_array($table,['cms_pages','cms_autosaves'],true))$db->exec('CREATE TABLE '.$table.'(id INTEGER PRIMARY KEY'.($scoped?',site_id INTEGER':'').')');}
    $doc=BlockEditor::encode(['schema'=>1,'blocks'=>[['type'=>'paragraph','data'=>['text'=>'Published original']]]]);
    $insert=$db->prepare('INSERT INTO cms_pages(id,'.($scoped?'site_id,':'').
        "title,body,block_document,slug,locale,status,author_id,content_type) VALUES(10,".($scoped?'1,':'').
        "'Original','Published original',:doc,'original','fa','published',1,'page')");
    $insert->execute(['doc'=>$doc]);
    return $db;
};
$doc=['schema'=>1,'blocks'=>[['type'=>'columns','data'=>['columns'=>[
    ['blocks'=>[['type'=>'heading','data'=>['text'=>'عنوان راست','level'=>3]],
       ['type'=>'image','data'=>['src'=>'/uploads/test.webp','alt'=>'حفظ تصویر','custom'=>['x'=>1]]]]],
    ['blocks'=>[['type'=>'paragraph','data'=>['text'=>'متن چپ <script>not executed</script>']]]]
]]]]];
$draft=EditorAutosave::document('عنوان موقت','متن موقت',BlockEditor::encode($doc));
foreach([false,true] as $scoped){
    $db=$fixture($scoped);$service=new EditorAutosave($db,1);
    $page=$db->query('SELECT * FROM cms_pages WHERE id=10')->fetch();
    $base=EditorAutosave::baseRevision($page);
    $mode=$scoped?'scoped':'legacy';
    $empty=$service->load(1,10,$base);
    $check($empty['autosave_token']===''&&$empty['recovery']===null,$mode.'_no_autosave_initially');
    $saved=$service->save(1,10,$base,'',$draft);
    $check($saved['saved']&&strlen($saved['autosave_token'])===64,$mode.'_save_full_document');
    $check($db->query('SELECT * FROM cms_pages WHERE id=10')->fetch()===$page,$mode.'_published_page_unchanged');
    $copy=$service->load(1,10,$base);
    $check($copy['recovery']===$draft&&$copy['recovery_state']==='available',$mode.'_exact_title_columns_image_metadata_roundtrip');
    $check($service->load(2,10,$base)['recovery']===null,$mode.'_actor_cannot_read_another_draft');
    $refuse(fn()=>$service->save(1,10,$base,'',$draft),'autosave_copy_changed');
    $refuse(fn()=>$service->load(4,10,$base),'autosave_access_denied');
    $refuse(fn()=>$service->load(1,999,$base),$scoped?'tenant_content_unavailable':'autosave_access_denied');
    $refuse(fn()=>$service->load(3,10,$base),$scoped?'tenant_access_denied':'autosave_access_denied');
    $next=$service->save(1,10,$base,$saved['autosave_token'],$draft);
    $check($next['autosave_token']!==$saved['autosave_token']&&(int)$db->query('SELECT COUNT(*) FROM cms_autosaves')->fetchColumn()===1,$mode.'_one_row_rotating_token');
    $service->save(2,10,$base,'',EditorAutosave::document('Other owner','Other body',BlockEditor::encode($doc)));
    $check($service->load(1,10,$base)['recovery']['title']==='عنوان موقت'&&(int)$db->query('SELECT COUNT(*) FROM cms_autosaves')->fetchColumn()===2,$mode.'_separate_actor_copies');
    if($scoped){
        $other=new EditorAutosave($db,2);
        $refuse(fn()=>$other->load(5,10,$base),'tenant_content_unavailable');
        $refuse(fn()=>$other->save(1,10,$base,'',$draft),'tenant_access_denied');
        $check((int)$db->query('SELECT COUNT(*) FROM cms_autosaves WHERE site_id=2')->fetchColumn()===0,'other_tenant_not_mutated');
    }
    $db->exec("UPDATE cms_pages SET title='Changed by another editor' WHERE id=10");
    $refuse(fn()=>$service->save(1,10,$base,$next['autosave_token'],$draft),'autosave_page_changed');
    $refuse(fn()=>$service->load(1,10,$base),'autosave_page_changed');
    $newBase=EditorAutosave::baseRevision($db->query('SELECT * FROM cms_pages WHERE id=10')->fetch());
    $check($service->load(1,10,$newBase)['recovery']===null,$mode.'_stale_copy_not_offered');
    $oldToken=$service->load(1,10,$newBase)['autosave_token'];
    $fresh=$service->save(1,10,$newBase,$oldToken,$draft);
    $row=$db->query('SELECT metadata_json FROM cms_autosaves WHERE actor_id=1')->fetchColumn();
    $meta=json_decode($row,true);$meta['saved_epoch']=time()-604801;
    $db->prepare('UPDATE cms_autosaves SET metadata_json=:meta WHERE actor_id=1')->execute(['meta'=>json_encode($meta)]);
    $check($service->load(1,10,$newBase)['recovery']===null,$mode.'_expired_copy_not_offered');
    $db->exec("UPDATE cms_pages SET trash_status='trash' WHERE id=10");
    $refuse(fn()=>$service->load(1,10,$newBase),$scoped?'tenant_content_unavailable':'autosave_access_denied');
}
$refuse(fn()=>EditorAutosave::document('x','x','{broken'),'autosave_document_invalid');
$refuse(fn()=>EditorAutosave::document(str_repeat('a',191),'',''),'autosave_document_too_large');
$refuse(fn()=>EditorAutosave::document('x',str_repeat('x',1048577),''),'autosave_document_too_large');
$check(!array_key_exists('status',$draft)&&!array_key_exists('slug',$draft),'recovery_payload_never_contains_publication_or_metadata_controls');
echo 'EDITOR_AUTOSAVE_RECOVERY=PASS checks='.$count.' actor_and_tenant_isolation,full_block_roundtrip,conflict_guard,no_page_mutation'.PHP_EOL;
