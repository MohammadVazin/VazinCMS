<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use VazinCMS\{Access,Audit,Auth,BlockEditor,CacheStore,ContentModel,Database,ExtensionRuntime,MediaPipeline,MediaStorage,RuntimePaths,SearchIndex,Security,UiLocale,View};
final class ContentController{
 private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];
 public function pages():void{$user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
   Security::verifyCsrf();
   try {
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
     if ($id < 1) throw new \InvalidArgumentException('محتوا معتبر نیست.');
     $check=$pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');$check->execute(['id'=>$id]);$page=$check->fetch();if(!$page||!ContentModel::canEdit($user,$page))throw new \RuntimeException('دسترسی ویرایش این محتوا وجود ندارد.');
     ContentModel::trash($pdo,$id,(int)$user['id']);
     Audit::log('cms.page_trashed','محتوای CMS به زباله‌دان منتقل شد',(int)$user['id'],['page_id'=>$id]);
     SearchIndex::refreshPage($pdo,$id); CacheStore::invalidateTag('content'); CacheStore::invalidateTag('page:'.$id);
     ExtensionRuntime::emit('content.trashed',['page_id'=>$id]);
     header('Location: /admin/pages?trashed=1');
     return;
    }

    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    $locale = (string)($_POST['locale'] ?? 'fa');
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $blockJson = trim((string)($_POST['block_document'] ?? ''));
    $blockDoc = $blockJson!=='' ? BlockEditor::decode($blockJson) : BlockEditor::legacy($body);
    $blockJson = BlockEditor::encode($blockDoc);
    $status = (string)($_POST['status'] ?? 'draft');
    $contentType = (string)($_POST['content_type'] ?? 'page');
    $home = isset($_POST['is_home']) ? 1 : 0;
    $metaTitle = mb_substr(trim((string)($_POST['meta_title'] ?? '')),0,255);
    $metaDescription = mb_substr(trim((string)($_POST['meta_description'] ?? '')),0,320);
    $canonical = trim((string)($_POST['canonical_url'] ?? ''));
    $featured = trim((string)($_POST['featured_image'] ?? ''));
    $ogTitle = mb_substr(trim((string)($_POST['og_title'] ?? '')),0,255);
    $ogDescription = mb_substr(trim((string)($_POST['og_description'] ?? '')),0,500);
    $schemaType = (string)($_POST['schema_type'] ?? ($contentType === 'post' ? 'Article' : 'WebPage'));
    $robotsIndex = isset($_POST['robots_index']) ? 1 : 0;
    $robotsFollow = isset($_POST['robots_follow']) ? 1 : 0;
    $publishedInput = trim((string)($_POST['published_at'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,188}$/',$slug)
     || !in_array($locale,self::LOCALES,true)
     || mb_strlen($title) < 2
     || !in_array($status,['draft','published'],true)
     || !self::validContentType($pdo,$contentType)
     || !in_array($schemaType,['Article','NewsArticle','BlogPosting','WebPage'],true)) {
     throw new \InvalidArgumentException('اطلاعات محتوا معتبر نیست.');
    }
    if ($canonical !== '' && !filter_var($canonical,FILTER_VALIDATE_URL)) {
     throw new \InvalidArgumentException('نشانی canonical معتبر نیست.');
    }

    $existingPublished = null;
    if ($id > 0) {
     $existingQuery = $pdo->prepare('SELECT published_at FROM cms_pages WHERE id=:id');
     $existingQuery->execute(['id'=>$id]);
     $existing = $existingQuery->fetch();
     if (!is_array($existing)) throw new \InvalidArgumentException('محتوا پیدا نشد.');
     $existingPublished = $existing['published_at'] ?: null;
    }
    $publishedAt = null;
    if ($contentType === 'post') {
     $publishedAt = $existingPublished;
     if ($publishedInput !== '') {
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$publishedInput);
      $dateErrors = \DateTimeImmutable::getLastErrors();
      if (!$parsed || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
       throw new \InvalidArgumentException('تاریخ انتشار معتبر نیست.');
      }
      $publishedAt = $parsed->format('Y-m-d H:i:s');
     } elseif ($status === 'published' && $publishedAt === null) {
      $publishedAt = gmdate('Y-m-d H:i:s');
     }
     $home = 0;
    }

    $data = [
     'slug'=>$slug,'locale'=>$locale,'title'=>$title,'body'=>$body,'status'=>$status,'home'=>$home,
     'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'canonical_url'=>$canonical,'featured_image'=>$featured,
     'content_type'=>$contentType,'published_at'=>$publishedAt,'robots_index'=>$robotsIndex,'robots_follow'=>$robotsFollow,
     'og_title'=>$ogTitle,'og_description'=>$ogDescription,'schema_type'=>$schemaType,'block_schema_version'=>1,'block_document'=>$blockJson,
    ];
    $pdo->beginTransaction();
    if ($home) $pdo->prepare('UPDATE cms_pages SET is_home=0 WHERE locale=:locale')->execute(['locale'=>$locale]);
    if ($id > 0) {
     $old = $pdo->prepare('SELECT title,body,meta_title,meta_description FROM cms_pages WHERE id=:id');
     $old->execute(['id'=>$id]);
     if ($previous = $old->fetch()) {
      $pdo->prepare('INSERT INTO cms_page_revisions(page_id,title,body,meta_title,meta_description,created_by) VALUES(:page_id,:title,:body,:meta_title,:meta_description,:created_by)')
       ->execute($previous+['page_id'=>$id,'created_by'=>$user['id']]);
     }
     $data['id'] = $id;
     $pdo->prepare('UPDATE cms_pages SET slug=:slug,locale=:locale,title=:title,body=:body,status=:status,is_home=:home,meta_title=:meta_title,meta_description=:meta_description,canonical_url=:canonical_url,featured_image=:featured_image,content_type=:content_type,published_at=:published_at,robots_index=:robots_index,robots_follow=:robots_follow,og_title=:og_title,og_description=:og_description,schema_type=:schema_type,block_schema_version=:block_schema_version,block_document=:block_document,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
      ->execute($data);
    } else {
     $data['uid'] = $user['id'];
     $pdo->prepare('INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,meta_title,meta_description,canonical_url,featured_image,content_type,published_at,robots_index,robots_follow,og_title,og_description,schema_type,block_schema_version,block_document) VALUES(:slug,:locale,:title,:body,:status,:home,:uid,:meta_title,:meta_description,:canonical_url,:featured_image,:content_type,:published_at,:robots_index,:robots_follow,:og_title,:og_description,:schema_type,:block_schema_version,:block_document)')
      ->execute($data);
     $id = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
    Audit::log('cms.page_saved','محتوای CMS ذخیره شد',(int)$user['id'],['page_id'=>$id,'slug'=>$slug,'locale'=>$locale,'content_type'=>$contentType]);
    SearchIndex::refreshPage($pdo,$id); CacheStore::invalidateTag('content'); CacheStore::invalidateTag('page:'.$id);
    ExtensionRuntime::emit('content.saved',['page_id'=>$id,'actor_id'=>(int)$user['id']]);
    header('Location: /admin/pages?saved=1');
    return;
   } catch (\Throwable $errorObject) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $errorObject instanceof \InvalidArgumentException ? $errorObject->getMessage() : 'محتوا ذخیره نشد.';
   }
  }
  $pages=$pdo->query("SELECT * FROM cms_pages WHERE trash_status='active' ORDER BY locale,is_home DESC,id DESC")->fetchAll();$edit=null;$editId=(int)($_GET['edit']??0);if($editId){$s=$pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');$s->execute(['id'=>$editId]);$edit=$s->fetch()?:null;}View::render('pages',compact('user','pages','edit','error'));}
 public function autosave():void{$user=Access::require('content');Security::verifyCsrf();$pdo=Database::connection();$pageId=(int)($_POST['page_id']??0);if($pageId<1)throw new \InvalidArgumentException('page_id required');$q=$pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');$q->execute(['id'=>$pageId]);$page=$q->fetch();if(!$page||!ContentModel::canEdit($user,$page)){http_response_code(403);return;}header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+ContentModel::autosave($pdo,$pageId,(int)$user['id'],(string)($_POST['title']??''),(string)($_POST['body']??''),['locale'=>(string)($_POST['locale']??'')]),JSON_UNESCAPED_UNICODE);}
 public function restoreTrash():void{$user=Access::require('content');Security::verifyCsrf();$pdo=Database::connection();$id=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');$q->execute(['id'=>$id]);$page=$q->fetch();if(!$page||!ContentModel::canEdit($user,$page)){http_response_code(403);return;}ContentModel::restore($pdo,$id);Audit::log('cms.page_restored','محتوای CMS از زباله‌دان بازیابی شد',(int)$user['id'],['page_id'=>$id]);header('Location: /admin/pages?restored=1');}
 private static function validContentType($pdo,string $key):bool{$q=$pdo->prepare('SELECT 1 FROM cms_content_types WHERE content_key=:key');$q->execute(['key'=>$key]);return(bool)$q->fetchColumn();}

 public function menus():void{$user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();try{$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);if($action==='delete'){$pdo->prepare('DELETE FROM cms_menu_items WHERE id=:id')->execute(['id'=>$id]);}else{$label=trim((string)($_POST['label']??''));$url=trim((string)($_POST['url']??''));$locale=(string)($_POST['locale']??'fa');$position=max(0,(int)($_POST['position']??0));if(mb_strlen($label)<1||!in_array($locale,self::LOCALES,true)||!preg_match('#^(?:/|https://)#',$url))throw new \InvalidArgumentException('عنوان یا نشانی منو معتبر نیست.');$pdo->prepare('INSERT INTO cms_menu_items(locale,label,url,position,is_enabled) VALUES(:locale,:label,:url,:position,1)')->execute(compact('locale','label','url','position'));}Audit::log('cms.menu_changed','منوی سایت تغییر کرد',(int)$user['id']);header('Location: /admin/menus');return;}catch(\Throwable $e){$error=$e instanceof \InvalidArgumentException?$e->getMessage():'منو ذخیره نشد.';}}$items=$pdo->query('SELECT * FROM cms_menu_items ORDER BY locale,position,id')->fetchAll();View::render('menus',compact('user','items','error'));}
 public function media():void{$user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();try{if(($_POST['action']??'')==='delete'){$id=(int)($_POST['id']??0);$s=$pdo->prepare('SELECT stored_name FROM cms_media WHERE id=:id');$s->execute(['id'=>$id]);$name=$s->fetchColumn();if($name){$pdo->prepare('DELETE FROM cms_media WHERE id=:id')->execute(['id'=>$id]);$file=RuntimePaths::uploads().'/'.$name;if(is_file($file))@unlink($file);}}else{$file=$_FILES['media']??null;if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('فایل برای بارگذاری انتخاب نشده است.');if((int)$file['size']>10*1024*1024)throw new \InvalidArgumentException('حداکثر حجم فایل ۱۰ مگابایت است.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','application/pdf'=>'pdf'];if(!isset($allowed[$mime]))throw new \InvalidArgumentException('فقط JPG، PNG، WebP، GIF و PDF مجاز است.');$inspect=MediaPipeline::inspect($file['tmp_name'],$mime);$duplicate=MediaPipeline::duplicate($pdo,$inspect['sha256']);if($duplicate!==null)throw new \InvalidArgumentException('این فایل قبلاً در کتابخانه وجود دارد.');$stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$dir=RuntimePaths::uploads();if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored))throw new \RuntimeException('فایل ذخیره نشد.');$alt=mb_substr(trim((string)($_POST['alt_text']??'')),0,255);$pdo->prepare("INSERT INTO cms_media(file_name,stored_name,mime_type,file_size,alt_text,uploaded_by,width,height,sha256,storage_driver,storage_key,metadata_json) VALUES(:file_name,:stored_name,:mime_type,:file_size,:alt_text,:uploaded_by,:width,:height,:sha256,:storage_driver,:storage_key,:metadata)")->execute(['file_name'=>basename((string)$file['name']),'stored_name'=>$stored,'mime_type'=>$mime,'file_size'=>$file['size'],'alt_text'=>$alt,'uploaded_by'=>$user['id'],'width'=>$inspect['width'],'height'=>$inspect['height'],'sha256'=>$inspect['sha256'],'storage_driver'=>'local','storage_key'=>$stored,'metadata'=>'{}']);$mediaId=(int)$pdo->lastInsertId();foreach(MediaPipeline::derivatives($dir.'/'.$stored,$mime,$stored) as $d){$pdo->prepare('INSERT INTO cms_media_derivatives(media_id,variant,stored_name,mime_type,width,height,file_size,sha256) VALUES(:media,:variant,:stored,:mime,:width,:height,:size,:sha)')->execute(['media'=>$mediaId,'variant'=>$d['variant'],'stored'=>$d['stored_name'],'mime'=>$d['mime_type'],'width'=>$d['width'],'height'=>$d['height'],'size'=>$d['file_size'],'sha'=>$d['sha256']]);}}CacheStore::invalidateTag('media'); Audit::log('cms.media_changed','کتابخانه رسانه تغییر کرد',(int)$user['id']);header('Location: /admin/media');return;}catch(\Throwable $e){$error=$e instanceof \InvalidArgumentException?$e->getMessage():'رسانه ذخیره نشد.';}}$media=$pdo->query('SELECT * FROM cms_media ORDER BY id DESC')->fetchAll();View::render('media',compact('user','media','error'));}
 public function mediaPicker():void{Access::require('content');$pdo=Database::connection();$rows=$pdo->query('SELECT m.*, (SELECT stored_name FROM cms_media_derivatives d WHERE d.media_id=m.id ORDER BY width DESC LIMIT 1) AS derivative_name FROM cms_media m ORDER BY m.id DESC LIMIT 200')->fetchAll();$items=[];foreach($rows as$r)$items[]=['id'=>(int)$r['id'],'name'=>$r['file_name'],'mime'=>$r['mime_type'],'width'=>$r['width']!==null?(int)$r['width']:null,'height'=>$r['height']!==null?(int)$r['height']:null,'alt'=>$r['alt_text'],'url'=>MediaStorage::publicUrl((string)$r['stored_name'])];header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}

 public function publicMedia(string$name):void{if(!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf)$/',$name)){http_response_code(404);return;}$s=Database::connection()->prepare('SELECT mime_type,file_size FROM cms_media WHERE stored_name=:name LIMIT 1');$s->execute(['name'=>$name]);$row=$s->fetch();$allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf'];$path=RuntimePaths::uploads().'/'.$name;if(!$row||!in_array((string)$row['mime_type'],$allowed,true)||!is_file($path)){http_response_code(404);return;}header('X-Content-Type-Options: nosniff');header('Content-Type: '.(string)$row['mime_type']);header('Content-Length: '.(string)filesize($path));header('Cache-Control: public, max-age=31536000, immutable');readfile($path);}
 public function revisions():void{$user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();$id=(int)($_POST['revision_id']??0);$s=$pdo->prepare('SELECT * FROM cms_page_revisions WHERE id=:id');$s->execute(['id'=>$id]);if($r=$s->fetch()){$pdo->beginTransaction();try{$current=$pdo->prepare('SELECT title,body,meta_title,meta_description FROM cms_pages WHERE id=:id');$current->execute(['id'=>$r['page_id']]);if($c=$current->fetch())$pdo->prepare('INSERT INTO cms_page_revisions(page_id,title,body,meta_title,meta_description,created_by) VALUES(:page_id,:title,:body,:meta_title,:meta_description,:created_by)')->execute($c+['page_id'=>$r['page_id'],'created_by'=>$user['id']]);$pdo->prepare('UPDATE cms_pages SET title=:title,body=:body,meta_title=:meta_title,meta_description=:meta_description,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['title'=>$r['title'],'body'=>$r['body'],'meta_title'=>$r['meta_title'],'meta_description'=>$r['meta_description'],'id'=>$r['page_id']]);$pdo->commit();}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}}header('Location: /admin/revisions?restored=1');return;}$revisions=$pdo->query('SELECT r.*,p.title AS page_title,p.locale FROM cms_page_revisions r JOIN cms_pages p ON p.id=r.page_id ORDER BY r.id DESC LIMIT 100')->fetchAll();View::render('revisions',compact('user','revisions'));}
  public function settings():void{$user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$message=null;$error=null;if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();$enabled=array_values(array_intersect(self::LOCALES,(array)($_POST['enabled_locales']??[])));$default=(string)($_POST['default_locale']??'fa');$profile=(string)($_POST['site_profile']??'corporate');if(!$enabled||!in_array($default,$enabled,true))$error='حداقل یک زبان فعال کنید و زبان پیش‌فرض را از میان زبان‌های فعال انتخاب کنید.';elseif(!in_array($profile,['corporate','travel','visa','pay'],true))$error='پروفایل نمایش سایت معتبر نیست.';else{foreach(['site_name','site_tagline','site_description','logo_url','default_locale','contact_email','contact_phone','contact_address','site_profile','primary_color','hero_title','hero_text','hero_badge','primary_cta_label','primary_cta_url','secondary_cta_label','secondary_cta_url','stats_json','features_json','steps_json','faq_json','social_telegram','social_instagram','social_youtube','footer_text'] as $key){$value=trim((string)($_POST[$key]??''));$s=$pdo->prepare('INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP');$s->execute(['key'=>$key,'value'=>$value,'value2'=>$value]);}$value=json_encode($enabled,JSON_UNESCAPED_UNICODE);$s=$pdo->prepare('INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP');$s->execute(['key'=>'enabled_locales','value'=>$value,'value2'=>$value]);CacheStore::invalidateTag('settings'); CacheStore::invalidateTag('content'); Audit::log('cms.settings_saved','هویت و محتوای سایت ذخیره شد',(int)$user['id'],['enabled_locales'=>$enabled,'site_profile'=>$profile]);$message='تنظیمات سایت ذخیره شد.';}}$settings=[];foreach($pdo->query('SELECT setting_key,setting_value FROM cms_settings')->fetchAll() as $row)$settings[$row['setting_key']]=$row['setting_value'];$enabledLocales=json_decode((string)($settings['enabled_locales']??'["fa","ru","en"]'),true)?:['fa'];View::render('cms-settings',compact('user','settings','enabledLocales','message','error'));}
 public function modules():void{Auth::requireUser(['owner']);header('Location: /admin/extensions');}
 public function home(?string $locale=null):void{$pdo=Database::connection();$settings=$this->settingsMap();$default=$settings['default_locale']??'en';$enabled=json_decode((string)($settings['enabled_locales']??'[]'),true);if(!is_array($enabled)||!$enabled)$enabled=['fa','ru','en'];$locale=$locale?:UiLocale::detect();if(!in_array($locale,$enabled,true)){$locale=in_array($default,$enabled,true)?$default:(string)$enabled[0];}$s=$pdo->prepare("SELECT * FROM cms_pages WHERE locale=:locale AND status='published' AND is_home=1 LIMIT 1");$s->execute(['locale'=>$locale]);$page=$s->fetch();if(!$page){$s=$pdo->prepare("SELECT * FROM cms_pages WHERE locale=:locale AND status='published' AND content_type='page' ORDER BY id LIMIT 1");$s->execute(['locale'=>$locale]);$page=$s->fetch();}$siteName=trim((string)($settings['site_name']??''));if($siteName==='')$siteName=(string)($_SERVER['HTTP_HOST']??'Website');$profile=(string)($settings['site_profile']??'corporate');$view=match($profile){'travel'=>'travel-platform-home','visa'=>'visa-platform-home',default=>'site-home'};View::renderPublic($view,['locale'=>$locale,'page'=>$page,'settings'=>$settings,'profile'=>$profile,'meta'=>$this->pageMeta($page),'menu'=>$this->menu($locale),'siteName'=>$siteName]);}
 public function page(string $locale,string $slug):void{$s=Database::connection()->prepare("SELECT * FROM cms_pages WHERE locale=:locale AND slug=:slug AND status='published' LIMIT 1");$s->execute(['locale'=>$locale,'slug'=>$slug]);$page=$s->fetch();if(!$page){http_response_code(404);}View::renderPublic('cms-page',['locale'=>$locale,'page'=>$page,'meta'=>$this->pageMeta($page),'menu'=>$this->menu($locale),'siteName'=>$this->setting('site_name','VazinCMS')]);}
 public function module(string $locale,string $key):void{$s=Database::connection()->prepare('SELECT * FROM cms_modules WHERE module_key=:key AND is_enabled=1');$s->execute(['key'=>$key]);$module=$s->fetch();if(!$module){http_response_code(404);View::renderPublic('not-found',['locale'=>$locale]);return;}View::renderPublic('module',['locale'=>$locale,'module'=>$module,'menu'=>$this->menu($locale),'siteName'=>$this->setting('site_name','VazinCMS')]);}
 public function visaSelector(string $locale):void{$pdo=Database::connection();$nationality=strtoupper(substr((string)($_GET['nationality']??($locale==='ru'?'RU':'IR')),0,2));$destination=strtoupper(substr((string)($_GET['destination']??''),0,2));$nationalities=[];$destinations=[];$results=[];try{$nationalities=$pdo->query('SELECT code,name_fa,name_ru FROM visa_nationalities WHERE is_enabled=1 ORDER BY priority,code')->fetchAll();$destinations=$pdo->query('SELECT code,name_fa,name_ru FROM visa_destinations WHERE is_enabled=1 ORDER BY priority,code')->fetchAll();$sql='SELECT r.*,n.name_fa AS nationality_fa,n.name_ru AS nationality_ru,d.name_fa AS destination_fa,d.name_ru AS destination_ru FROM visa_rules r JOIN visa_nationalities n ON n.code=r.nationality_code JOIN visa_destinations d ON d.code=r.destination_code WHERE r.nationality_code=:nationality AND r.locale=:locale';$args=['nationality'=>$nationality,'locale'=>$locale];if($destination!==''){$sql.=' AND r.destination_code=:destination';$args['destination']=$destination;}$sql.=' ORDER BY d.priority,r.visa_type';$s=$pdo->prepare($sql);$s->execute($args);$results=$s->fetchAll();}catch(\Throwable $e){error_log('[VazinCMS visa selector] '.$e->getMessage());}View::renderPublic('visa-selector',compact('locale','nationality','destination','nationalities','destinations','results')+['menu'=>$this->menu($locale),'siteName'=>$this->setting('site_name','VazinCMS'),'meta'=>['title'=>$locale==='ru'?'Визы по гражданству':'شرایط ویزا براساس ملیت','description'=>'']]);}
 public function audienceDestinations(string $locale):void{$pdo=Database::connection();$audience=strtoupper(substr((string)($_GET['nationality']??($locale==='ru'?'RU':'IR')),0,2));$items=[];try{$s=$pdo->prepare('SELECT * FROM travel_destinations WHERE audience_code=:audience AND locale=:locale AND is_enabled=1 ORDER BY priority,city');$s->execute(['audience'=>$audience,'locale'=>$locale]);$items=$s->fetchAll();}catch(\Throwable $e){error_log('[VazinCMS destinations] '.$e->getMessage());}View::renderPublic('audience-destinations',compact('locale','audience','items')+['menu'=>$this->menu($locale),'siteName'=>$this->setting('site_name','VazinCMS'),'meta'=>['title'=>$locale==='ru'?'Направления для путешествий':'راهنمای مقصدهای سفر','description'=>'']]);}
 private function menu(string $locale):array{$s=Database::connection()->prepare('SELECT label,url FROM cms_menu_items WHERE locale=:locale AND is_enabled=1 ORDER BY position,id');$s->execute(['locale'=>$locale]);$items=$s->fetchAll();$seenLabels=[];$seenUrls=[];$unique=[];foreach($items as$item){$label=mb_strtolower(trim((string)$item['label']));$url=rtrim((string)$item['url'],'/')?:'/';if(isset($seenLabels[$label])||isset($seenUrls[$url]))continue;$seenLabels[$label]=true;$seenUrls[$url]=true;$unique[]=$item;}return$unique;}
 private function setting(string $key,string $default):string{$s=Database::connection()->prepare('SELECT setting_value FROM cms_settings WHERE setting_key=:key');$s->execute(['key'=>$key]);return(string)($s->fetchColumn()?:$default);}
 private function settingsMap():array{$map=[];foreach(Database::connection()->query('SELECT setting_key,setting_value FROM cms_settings')->fetchAll() as $row)$map[$row['setting_key']]=$row['setting_value'];if(!isset($map['default_locale'])||$map['default_locale']==='')$map['default_locale']='en';if(!isset($map['enabled_locales'])||$map['enabled_locales']==='')$map['enabled_locales']=json_encode(['en','fa','ar','ru','zh'],JSON_UNESCAPED_UNICODE);return$map;}
 private function pageMeta(array|false|null $page):array{if(!$page)return['title'=>'','description'=>'','canonical'=>''];$description=trim((string)($page['meta_description']??''));if($description==='')$description=mb_substr(trim(preg_replace('/\s+/u',' ',strip_tags((string)$page['body']))??''),0,180);$base=rtrim((string)getenv('APP_URL'),'/');$canonical=trim((string)($page['canonical_url']??''));if($canonical===''&&$base!=='')$canonical=$base.(!empty($page['is_home'])?'/'.$page['locale']:'/'.$page['locale'].'/page/'.$page['slug']);$image=trim((string)($page['featured_image']??''));if($image!==''&&str_starts_with($image,'/')&&$base!=='')$image=$base.$image;$isPost=($page['content_type']??'page')==='post';return['title'=>$page['meta_title']?:$page['title'],'description'=>$description,'canonical'=>$canonical,'robots'=>(!empty($page['robots_index'])?'index':'noindex').','.(!empty($page['robots_follow'])?'follow':'nofollow').',max-image-preview:large','og_title'=>$page['og_title']?:$page['title'],'og_description'=>$page['og_description']?:$description,'image'=>$image,'type'=>$isPost?'article':'website','published_at'=>$page['published_at']??null,'updated_at'=>$page['updated_at']??null,'schema_type'=>$page['schema_type']?:($isPost?'Article':'WebPage')];}
}
