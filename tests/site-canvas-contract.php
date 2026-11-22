<?php
declare(strict_types=1);
foreach(['DatabaseTransaction','SiteTemplateBuilder','SiteCanvas','SiteCanvasRenderer','BlockEditor','Security','SiteLocale'] as $f)require dirname(__DIR__).'/src/'.$f.'.php';
use VazinCMS\{SiteCanvas,SiteCanvasRenderer,SiteTemplateBuilder};
$count=0;$check=static function(bool $ok,string $name)use(&$count){if(!$ok)throw new RuntimeException('CANVAS_QA_FAIL:'.$name);$count++;echo 'PASS '.$name.PHP_EOL;};
$denied=static function(callable $fn,string $code)use($check){try{$fn();}catch(RuntimeException $e){$check($e->getMessage()===$code,$code);return;}throw new RuntimeException('UNEXPECTED_ACCEPT:'.$code);};
$paragraph=static fn($text)=>[['id'=>'n-20000001','type'=>'text','text'=>$text]];
foreach([false,true] as $scoped){
 $p=$scoped?'scoped_':'legacy_';
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO cms_sites VALUES(1,'active')".($scoped?",(2,'active')":'').';');
 $db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);INSERT INTO users VALUES(10,'owner','active'),(20,'editor','active'),(30,'owner','disabled'),(40,'owner','active')");
 $db->exec("CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);INSERT INTO cms_site_memberships VALUES(1,10,'owner'),(1,20,'editor'),(1,30,'owner'),(2,40,'owner')");
 $db->exec('CREATE TABLE cms_settings('.($scoped?'site_id INTEGER,':'').'setting_key TEXT,setting_value TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY('.($scoped?'site_id,':'').'setting_key))');
 $db->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,'.($scoped?'site_id INTEGER,':'')."slug TEXT,locale TEXT,title TEXT,body TEXT,content_type TEXT,status TEXT,trash_status TEXT DEFAULT 'active')");
 $insert=$db->prepare('INSERT INTO cms_pages('.($scoped?'site_id,':'').'slug,locale,title,body,content_type,status,trash_status) VALUES('.($scoped?'1,':'').':slug,:loc,:title,:body,:type,:status,:trash)');
 for($i=1;$i<=14;$i++)$insert->execute(['slug'=>'post-'.$i,'loc'=>'fa','title'=>'نوشته '.$i,'body'=>'متن '.$i,'type'=>'post','status'=>'published','trash'=>'active']);
 foreach([['hidden','draft','active','post','fa'],['deleted','published','trash','post','fa'],['english','published','active','post','en'],['page','published','active','page','fa']] as [$slug,$status,$trash,$type,$loc])$insert->execute(compact('slug','status','trash','type','loc')+['title'=>'SECRET '.$slug,'body'=>'private']);
 if($scoped)$db->exec("INSERT INTO cms_pages(site_id,slug,locale,title,body,content_type,status) VALUES(2,'tenant-two','fa','B SECRET','hidden','post','published')");
 $service=new SiteCanvas($db,1);$state=$service->read(10);
 $check($state['history']===[]&&$service->publicLayout('corporate','page')===null,$p.'reads_do_not_enable_layout');
 $check((int)$db->query('SELECT COUNT(*) FROM cms_settings')->fetchColumn()===0,$p.'no_read_mutation');
 foreach([20,30,999] as $actor)$denied(fn()=>$service->read($actor),'template_access_denied');
 $a=$service->archive('fa',1);$b=$service->archive('fa',2);
 $check(count($a['items'])===12&&count($b['items'])===2&&$a['total']===14,$p.'archive_pagination_published_only');
 $check(!str_contains(json_encode($a),'SECRET')&&!str_contains(json_encode($b),'SECRET'),$p.'archive_excludes_draft_trashed_locale_page_other_site');
 $denied(fn()=>$service->archive('fa',0),'canvas_archive_invalid');
 $state=$service->saveShared(10,$state['version'],'s-30000001','همگام',$paragraph('نسخه اول'));
 $check(count($state['history'])===1,$p.'shared_first_save_history');
 $tree=SiteCanvas::defaults('page');$tree[0]['children'][]=['id'=>'n-30000003','type'=>'section','layout'=>'grid2','tone'=>'muted','gap'=>'wide','children'=>[['id'=>'n-30000004','type'=>'shared','ref'=>'s-30000001']]];
 $state=$service->save(10,$state['version'],'corporate','page',$tree);
 $state=$service->save(10,$state['version'],'editorial','page',$tree);
 $archive=SiteCanvas::defaults('archive');$archive[]=['id'=>'n-30000005','type'=>'shared','ref'=>'s-30000001'];
 $state=$service->save(10,$state['version'],'corporate','archive',$archive);
 $catalog=new SiteTemplateBuilder($db,1);$cat=$catalog->read(10);
 $cat=$catalog->duplicate(10,'corporate','temporary',$cat['version']);$custom=array_values(array_diff(array_keys($cat['templates']),['corporate','editorial','minimal']))[0];
 $state=$service->save(10,$state['version'],$custom,'page',$tree);
 // A per-page override takes precedence for that page only; all other pages inherit the template layout.
 $pageTree=$tree;$pageTree[0]['children'][]=['id'=>'n-30000008','type'=>'text','text'=>'صفحه اختصاصی A'];
 $state=$service->savePage(10,$state['version'],1,$pageTree);
 $check($service->publicLayout('corporate','page',1)['source']==='page'&&
     $service->publicLayout('corporate','page',2)['source']==='template',$p.'per_page_layout_override_and_template_fallback');
 $pageHtml=SiteCanvasRenderer::render($service->publicLayout('corporate','page',1),['page'=>['title'=>'A','body'=>'A']]);
 $fallbackHtml=SiteCanvasRenderer::render($service->publicLayout('corporate','page',2),['page'=>['title'=>'B','body'=>'B']]);
 $check(str_contains($pageHtml,'صفحه اختصاصی A')&&str_contains($pageHtml,'data-canvas-source="page"')&&str_contains($pageHtml,'data-canvas-page-id="1"')&&
     !str_contains($fallbackHtml,'صفحه اختصاصی A')&&str_contains($fallbackHtml,'data-canvas-source="template"'),$p.'per_page_layout_public_render_isolated');
 $headerTree=[['id'=>'n-31000001','type'=>'section','layout'=>'stack','tone'=>'surface','gap'=>'normal','children'=>[['id'=>'n-31000002','type'=>'shared','ref'=>'s-30000001']]]];
 $footerTree=[['id'=>'n-31000003','type'=>'section','layout'=>'grid2','tone'=>'muted','gap'=>'wide','children'=>[['id'=>'n-31000004','type'=>'shared','ref'=>'s-30000001']]]];
 $state=$service->save(10,$state['version'],'corporate','header',$headerTree);
 $state=$service->save(10,$state['version'],'corporate','footer',$footerTree);
 $headerHtml=SiteCanvasRenderer::renderShell($service->publicLayout('corporate','header'),'header');
 $footerHtml=SiteCanvasRenderer::renderShell($service->publicLayout('corporate','footer'),'footer');
 $check(str_contains($headerHtml,'vz-canvas-header')&&str_contains($headerHtml,'نسخه اول')&&
     str_contains($footerHtml,'vz-canvas-footer')&&str_contains($footerHtml,'نسخه اول'),$p.'same_shared_library_renders_in_header_and_footer');
 $catalog->deleteCustom(10,$custom,$cat['version']);
 $state=$service->saveShared(10,$state['version'],'s-30000001','همگام',$paragraph('نسخه اول'));
 $check(isset($state['snapshot']['layouts'][$custom]),$p.'orphan_layout_preserved_without_blocking_other_updates');
 $denied(fn()=>$service->save(10,$state['version'],$custom,'page',$tree),'canvas_template_invalid');
 $check(str_contains(SiteCanvasRenderer::render($service->publicLayout('corporate','page'),['page'=>['title'=>'Title','body'=>'Body']]),'class="public-card vz-canvas-public"'),$p.'existing_global_design_wrapper_preserved');
 $oldVersion=$state['version'];$state=$service->saveShared(10,$oldVersion,'s-30000001','همگام',$paragraph('نسخه دوم <script>alert(1)</script>'));
 $historyId=$state['history'][0]['id'];
 foreach([['corporate','page'],['editorial','page'],['corporate','archive']] as [$key,$kind]){
  $layout=$service->publicLayout($key,$kind);
  $html=SiteCanvasRenderer::render($layout,['page'=>['title'=>'عنوان','body'=>'متن صفحه'],'archive'=>$a]);
  $check(str_contains($html,'نسخه دوم')&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),$p.'sync_safe_render_'.$key.'_'.$kind);
 }
 $check(str_contains(SiteCanvasRenderer::renderShell($service->publicLayout('corporate','header'),'header'),'نسخه دوم')&&
     str_contains(SiteCanvasRenderer::renderShell($service->publicLayout('corporate','footer'),'footer'),'نسخه دوم'),$p.'one_shared_component_update_reaches_header_footer_and_body');

 $check(str_contains(SiteCanvasRenderer::render($service->publicLayout('corporate','page'),['page'=>['title'=>'عنوان','body'=>'محتوا']]),'layout-grid2'),$p.'nested_section_public');
 $denied(fn()=>$service->save(10,$oldVersion,'corporate','page',$tree),'canvas_changed');
 $check($state['version']===$service->read(10)['version'],$p.'stale_attempt_zero_mutation');
 $state=$service->restore(10,$state['version'],$historyId);
 $check($state['snapshot']['shared']['s-30000001']['nodes'][0]['text']==='نسخه اول',$p.'restore_arbitrary_history_shared_and_layouts');
 $check(isset($state['snapshot']['pageLayouts']['1'])&&isset($state['snapshot']['layouts']['corporate']['header'])&&isset($state['snapshot']['layouts']['corporate']['footer'])&&
     str_contains(SiteCanvasRenderer::renderShell($service->publicLayout('corporate','header'),'header'),'نسخه اول')&&
     str_contains(SiteCanvasRenderer::renderShell($service->publicLayout('corporate','footer'),'footer'),'نسخه اول'),$p.'single_history_restore_restores_page_header_footer_and_shared_library');
 $state=$service->restore(10,$state['version'],$state['history'][0]['id']);
 $check(str_contains($state['snapshot']['shared']['s-30000001']['nodes'][0]['text'],'نسخه دوم'),$p.'restore_is_itself_reversible');
 $state=$service->resetPage(10,$state['version'],1);
 $check($service->publicLayout('corporate','page',1)['source']==='template',$p.'reset_per_page_override_falls_back_to_template');
 $state=$service->restore(10,$state['version'],$state['history'][0]['id']);
 $check($service->publicLayout('corporate','page',1)['source']==='page',$p.'history_restores_per_page_override_after_reset');
 $bad=$tree;$bad[0]['children'][]=['id'=>'n-30000006','type'=>'shared','ref'=>'s-ffffffff'];
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_reference_invalid');
 $bad=$tree;$bad[0]['children'][0]['id']=$bad[0]['id'];
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_node_invalid');
 $bad=$tree;array_shift($bad[0]['children']);
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_required_slot');
 $bad=$tree;$bad[0]['children'][]=['id'=>'n-30000007','type'=>'archive_list'];
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_slot_invalid');
 $bad=$tree;$bad[0]['tone']='red;display:none';
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_style_invalid');
 $bad=$tree;$bad[0]['onclick']='alert(1)';
 $denied(fn()=>$service->save(10,$state['version'],'corporate','page',$bad),'canvas_fields_invalid');
 $deep=$paragraph('deep');for($i=0;$i<6;$i++)$deep=[['id'=>'n-4000000'.$i,'type'=>'section','layout'=>'stack','tone'=>'surface','gap'=>'normal','children'=>$deep]];
 $denied(fn()=>$service->saveShared(10,$state['version'],'s-40000001','deep',$deep),'canvas_tree_invalid');
 $denied(fn()=>$service->saveShared(10,$state['version'],'s-40000001','cycle',[['id'=>'n-40000001','type'=>'shared','ref'=>'s-40000001']]),'canvas_reference_invalid');
 $denied(fn()=>$service->restore(10,$state['version'],str_repeat('f',32)),'canvas_history_unavailable');
 foreach(['javascript:alert(1)','//evil.test','/%2f%2fevil','/%255cfoo','/../foo','/%0d%0afoo'] as $url)$denied(fn()=>SiteCanvas::href($url),'canvas_link_invalid');
 for($i=0;$i<23;$i++)$state=$service->saveShared(10,$state['version'],'s-30000001','همگام',$paragraph('bounded '.$i));
 $check(count($state['history'])===20,$p.'history_bound_20');
 $state=$service->reset(10,$state['version'],'corporate','page');
 $check($service->publicLayout('corporate','page')===null&&$service->publicLayout('editorial','page')!==null,$p.'reset_only_selected_template_body');
 if($scoped){
  $other=new SiteCanvas($db,2);$otherState=$other->read(40);
  $check($otherState['snapshot']['shared']===[]&&$otherState['snapshot']['pageLayouts']===[],$p.'other_site_no_shared_components_or_page_layouts');
  $tenantTwoId=(int)$db->query("SELECT id FROM cms_pages WHERE slug='tenant-two'")->fetchColumn();
  $check($other->publicLayout('corporate','page',$tenantTwoId)===null,$p.'per_page_layout_not_visible_across_sites');
  $check($other->publicLayout('corporate','header')===null&&$other->publicLayout('corporate','footer')===null,$p.'shared_header_footer_layouts_not_visible_across_sites');
  $denied(fn()=>$other->read(10),'template_access_denied');
  $o=$other->read(40);$denied(fn()=>$other->restore(40,$o['version'],$state['history'][0]['id']),'canvas_history_unavailable');
  $check($other->archive('fa')['total']===1,$p.'second_site_archive_scoped');
  $o=$other->saveShared(40,$o['version'],'s-30000001','B',$paragraph('B only'));
  $check(str_contains($service->read(10)['snapshot']['shared']['s-30000001']['nodes'][0]['text'],'bounded'),$p.'same_shared_id_does_not_cross_site');
 }
}
$check(SiteCanvas::href('/')==='/','root_link_valid');
echo 'SITE_CANVAS_CONTRACT=PASS checks='.$count.' nested_layouts,shared_sync,history,site_isolation,published_archive,safe_renderer'.PHP_EOL;
