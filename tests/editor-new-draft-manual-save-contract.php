<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'VazinCMS\\')){
        $path=$root.'/src/'.str_replace('\\','/',substr($name,9)).'.php';if(is_file($path))require $path;
    }
});
use VazinCMS\{ContentRevision,EditorAutosave,EditorNewDraft,EditorPageSave,BlockEditor,SiteScopeMode};
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{
    if(!$ok)throw new RuntimeException('NEW_DRAFT_MANUAL_QA_FAIL:'.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$refuse=static function(callable $action,string $code)use($check):void{
    try{$action();}catch(Throwable $e){$check($e->getMessage()===$code,$code);return;}
    throw new RuntimeException('NEW_DRAFT_MANUAL_UNEXPECTED_ALLOW:'.$code);
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
$doc=['schema'=>1,'blocks'=>[['type'=>'columns','data'=>['columns'=>[
 ['blocks'=>[['type'=>'heading','data'=>['text'=>'عنوان تازه','level'=>3]]]],
 ['blocks'=>[['type'=>'image','data'=>['src'=>'/uploads/picture.webp','alt'=>'حفظ تصویر','custom'=>['keep'=>true]]],['type'=>'paragraph','data'=>['text'=>'متن تازه']]]]
]]]]];
$draft=['title'=>'ن','body'=>'متن تازه','block_document'=>BlockEditor::encode($doc),'slug'=>'نشانی هنوز کامل نیست','locale'=>'fa','content_type'=>'page'];
foreach([false,true] as $scoped){
 $mode=$scoped?'scoped':'legacy';$db=$fixture($scoped);
 $service=new EditorNewDraft($db,1);$saver=new EditorPageSave($db,1);$key=str_repeat('a',32);
 $empty=$service->load(1,$key);
 $check($empty['recovery']===null&&$empty['autosave_token']==='',$mode.'_new_key_has_no_page_or_copy');
 $saved=$service->save(1,$key,'',$draft);
 $check($saved['saved']&&strlen($saved['autosave_token'])===64,$mode.'_pre_first_save_server_receipt');
 $check((int)$db->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn()===0,$mode.'_autosave_does_not_create_page');
 $check((int)$db->query('SELECT COUNT(*) FROM cms_page_revisions')->fetchColumn()===0,$mode.'_autosave_does_not_fake_history');
 $check($db->query('SELECT page_id FROM cms_autosaves LIMIT 1')->fetchColumn()===null,$mode.'_existing_nullable_storage_used');
 $load=$service->load(1,$key);
 $check($load['recovery']===$draft&&$load['recovery_state']==='available',$mode.'_partial_title_slug_and_nested_blocks_recoverable');
 $check($service->recent(1)[0]['key']===$key,$mode.'_recent_private_unsaved_copy_discoverable');
 $check($service->load(2,$key)['recovery']===null,$mode.'_other_actor_same_key_cannot_read');
 $refuse(fn()=>$service->save(1,$key,'',$draft),'new_draft_changed');
 $refuse(fn()=>$service->load(4,$key),'new_draft_access_denied');
 $refuse(fn()=>$service->load(3,$key),'new_draft_access_denied');
 $refuse(fn()=>$service->load(1,'../invalid'),'new_draft_key_invalid');
 $changed=$draft;$changed['title']='عنوان کامل پیش از ذخیره';
 $next=$service->save(1,$key,$saved['autosave_token'],$changed);
 $refuse(fn()=>$service->save(1,$key,$saved['autosave_token'],$draft),'new_draft_changed');
 $check($service->load(1,$key)['recovery']['title']===$changed['title'],$mode.'_stale_autosave_keeps_latest_copy');
 $fields=$changed+['status'=>'draft','schema_type'=>'WebPage','home'=>0,'meta_title'=>'SEO saved'];$fields['slug']='first-draft';
 $refuse(fn()=>$saver->save(1,0,$fields,'',$key,$saved['autosave_token']),'new_draft_changed');
 $check((int)$db->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn()===0,$mode.'_stale_first_manual_save_no_page_created');
 $id=$saver->save(1,0,$fields,'',$key,$next['autosave_token']);
 $page=$db->query('SELECT * FROM cms_pages WHERE id='.$id)->fetch();
 $check($id>0&&$page['status']==='draft'&&$page['slug']==='first-draft'&&$page['block_document']===$draft['block_document'],$mode.'_first_manual_save_creates_exact_draft_once');
 $check($service->recent(1)===[],$mode.'_consumed_copy_not_reoffered');
 $refuse(fn()=>$service->load(1,$key),'new_draft_already_saved');
 $refuse(fn()=>$saver->save(1,0,$fields,'',$key,$next['autosave_token']),'new_draft_already_saved');
 $refuse(fn()=>$service->save(1,$key,$next['autosave_token'],$draft),'new_draft_already_saved');
 $check((int)$db->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn()===1,$mode.'_double_submit_cannot_duplicate_page');
 $base=EditorAutosave::baseRevision($page);
 $new=$fields;$new['title']='نسخهٔ ذخیره‌شده جدید';$new['status']='published';
 $new['body']='متن نسخه جدید';$new['block_document']=BlockEditor::encode(['schema'=>1,'blocks'=>[['type'=>'paragraph','data'=>['text'=>'متن نسخه جدید']]]]);
 $saver->save(1,$id,$new,$base);
 $latest=$db->query('SELECT * FROM cms_pages WHERE id='.$id)->fetch();
 $revisions=(int)$db->query('SELECT COUNT(*) FROM cms_page_revisions')->fetchColumn();
 $check($latest['title']===$new['title']&&$latest['status']==='published'&&$revisions===1,$mode.'_fresh_manual_save_succeeds_with_history');
 $stale=$fields;$stale['title']='این تغییر قدیمی رد شود';$stale['home']=1;
 $refuse(fn()=>$saver->save(1,$id,$stale,$base),'manual_save_page_changed');
 $check($db->query('SELECT * FROM cms_pages WHERE id='.$id)->fetch()===$latest&&
  (int)$db->query('SELECT COUNT(*) FROM cms_page_revisions')->fetchColumn()===$revisions,$mode.'_stale_manual_post_has_no_page_or_revision_side_effect');
 $refuse(fn()=>$saver->save(1,$id,$stale,''),'manual_save_page_changed');
 $freshBase=EditorAutosave::baseRevision($latest);
 $refuse(fn()=>$saver->save(4,$id,$stale,$freshBase),'manual_save_access_denied');
 $refuse(fn()=>$saver->save(3,$id,$stale,$freshBase),'manual_save_access_denied');
 $refuse(fn()=>$saver->save(1,999,$stale,$freshBase),'manual_save_content_unavailable');
 $refuse(fn()=>$saver->save(1,0,$fields,'','',''),'new_draft_key_required');
 $direct=$fields;$direct['slug']='fast-first-save';
 $fast=$saver->save(1,0,$direct,'',str_repeat('b',32),'');
 $check($fast>$id,$mode.'_manual_save_before_autosave_arrives_is_safe');
 $refuse(fn()=>$service->save(1,str_repeat('b',32),'',$draft),'new_draft_already_saved');
 if($scoped){
  $other=new EditorNewDraft($db,2);$otherSaver=new EditorPageSave($db,2);
  $check($other->load(5,$key)['recovery']===null,'other_tenant_cannot_load_actor_A_copy');
  $otherSaved=$other->save(5,$key,'',$draft);
  $check($other->load(5,$key)['autosave_token']===$otherSaved['autosave_token'],'same_draft_key_scoped_per_tenant_and_actor');
  $refuse(fn()=>$otherSaver->save(5,$id,$fields,$freshBase),'manual_save_content_unavailable');
  $refuse(fn()=>$saver->save(6,$id,$fields,$freshBase),'manual_save_content_unavailable');
  $refuse(fn()=>$service->load(5,$key),'new_draft_access_denied');
  $refuse(fn()=>$other->load(1,$key),'new_draft_access_denied');
 }
 $db->exec("UPDATE cms_pages SET trash_status='trash' WHERE id=".$id);
 $refuse(fn()=>$saver->save(1,$id,$fields,$freshBase),'manual_save_content_unavailable');
}
$db=$fixture(false);$service=new EditorNewDraft($db,1);
for($i=0;$i<20;$i++)$service->save(1,str_pad(dechex($i),32,'0',STR_PAD_LEFT),'',$draft);
$refuse(fn()=>$service->save(1,str_repeat('f',32),'',$draft),'new_draft_limit_reached');
$check((int)$db->query('SELECT COUNT(*) FROM cms_autosaves')->fetchColumn()===20,'active_new_draft_limit_no_extra_insert');
$check(!array_key_exists('status',EditorNewDraft::document($draft+['status'=>'published','is_home'=>1,'actor_id'=>99])),
 'draft_payload_cannot_change_publication_or_actor');
echo 'EDITOR_NEW_DRAFT_AND_MANUAL_SAVE=PASS checks='.$count.' pre_first_save_recovery,actor_tenant_isolation,atomic_consume,stale_manual_guard,no_silent_overwrite'.PHP_EOL;
