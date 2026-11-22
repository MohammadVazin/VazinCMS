<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\{
    Audit,Auth,BlockEditor,CacheStore,Database,ExtensionRuntime,
    MediaStorage,SearchIndex,Security,SiteContext,SiteScopedContentWriter,
    SiteScopedMediaService,View
};
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Migrated-schema admin paths; only dispatched under the global single-site gate. */
final class SiteScopedAdminContentController
{
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    private function context(): array
    {
        $user=Auth::requireUser(['owner','admin']);
        $pdo=Database::connection();
        $writer=new SiteScopedContentWriter($pdo,SiteContext::id());
        return [$user,$pdo,$writer];
    }

    private static function denyOrError(Throwable $e,string $fallback): string
    {
        if($e instanceof RuntimeException && in_array($e->getMessage(),
            ['tenant_content_unavailable','tenant_access_denied','tenant_setting_conflict',
             'tenant_redirect_conflict'],true)) {
            http_response_code($e->getMessage()==='tenant_access_denied'?403:409);
            return 'دسترسی یا مالکیت این داده برای سایت جاری تأیید نشد.';
        }
        return $e instanceof InvalidArgumentException ? $e->getMessage():$fallback;
    }

    public function pages(): void
    {
        [$user,$pdo,$writer]=$this->context();
        $error=null;$manualConflict=false;$attempted=null;$editorBaseOverride=null;$editorCsrfVerified=false;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try {
                Security::verifyCsrf();$editorCsrfVerified=true;
                $id=(int)($_POST['id']??0);
                $action=(string)($_POST['action']??'save');
                if($action==='delete'){
                    $writer->trash((int)$user['id'],$id);
                    SearchIndex::refreshPage($pdo,$id);
                    Audit::log('cms.page_trashed','محتوا به زباله‌دان منتقل شد',(int)$user['id'],['page_id'=>$id]);
                    CacheStore::invalidateTag('content');
                    header('Location: /admin/pages?trashed=1');
                    return;
                }
                if($action!=='save')throw new InvalidArgumentException('عملیات نامعتبر است.');
                $body=trim((string)($_POST['body']??''));
                $blockJson=trim((string)($_POST['block_document']??''));
                $doc=$blockJson!==''?BlockEditor::decode($blockJson):BlockEditor::legacy($body);
                $type=(string)($_POST['content_type']??'page');
                $status=(string)($_POST['status']??'draft');
                $date=trim((string)($_POST['published_at']??''));
                $published=null;
                if($id>0){
                    $old=$writer->page((int)$user['id'],$id);
                    $published=$old['published_at']??null;
                }
                if($type==='post'){
                    if($date!==''){
                        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date);
                        $errors=DateTimeImmutable::getLastErrors();
                        if(!$dt||(is_array($errors)&&($errors['warning_count']||$errors['error_count']))) {
                            throw new InvalidArgumentException('تاریخ انتشار معتبر نیست.');
                        }
                        $published=$dt->format('Y-m-d H:i:s');
                    }elseif($status==='published'&&$published===null){
                        $published=gmdate('Y-m-d H:i:s');
                    }
                }else{$published=null;}
                $fields=[
                    'slug'=>strtolower(trim((string)($_POST['slug']??''))),
                    'locale'=>(string)($_POST['locale']??'fa'),
                    'title'=>trim((string)($_POST['title']??'')),
                    'body'=>$body,'status'=>$status,
                    'home'=>isset($_POST['is_home'])?1:0,
                    'meta_title'=>mb_substr(trim((string)($_POST['meta_title']??'')),0,255),
                    'meta_description'=>mb_substr(trim((string)($_POST['meta_description']??'')),0,320),
                    'canonical_url'=>trim((string)($_POST['canonical_url']??'')),
                    'featured_image'=>trim((string)($_POST['featured_image']??'')),
                    'content_type'=>$type,'published_at'=>$published,
                    'robots_index'=>isset($_POST['robots_index'])?1:0,
                    'robots_follow'=>isset($_POST['robots_follow'])?1:0,
                    'og_title'=>mb_substr(trim((string)($_POST['og_title']??'')),0,255),
                    'og_description'=>mb_substr(trim((string)($_POST['og_description']??'')),0,500),
                    'schema_type'=>(string)($_POST['schema_type']??($type==='post'?'Article':'WebPage')),
                    'block_schema_version'=>1,'block_document'=>BlockEditor::encode($doc)
                ];
                if($fields['canonical_url']!=='' && !filter_var($fields['canonical_url'],FILTER_VALIDATE_URL)){
                    throw new InvalidArgumentException('Canonical URL معتبر نیست.');
                }
                $savedId=(new \VazinCMS\EditorPageSave($pdo,SiteContext::id()))->save(
                    (int)$user['id'],$id,$fields,(string)($_POST['base_revision']??''),
                    (string)($_POST['draft_key']??''),(string)($_POST['draft_token']??'')
                );
                SearchIndex::refreshPage($pdo,$savedId);
                Audit::log('cms.page_saved','محتوای سایت ذخیره شد',(int)$user['id'],[
                    'page_id'=>$savedId,'site_id'=>SiteContext::id()
                ]);
                CacheStore::invalidateTag('content');
                ExtensionRuntime::emit('content.saved',['page_id'=>$savedId,'site_id'=>SiteContext::id()]);
                header('Location: /admin/pages?type='.($type==='post'?'post':'page').'&saved=1');
                return;
            }catch(Throwable $e){
                if($editorCsrfVerified&&($action??'save')==='save'){
                    $attempted=\VazinCMS\EditorPageSave::attemptedForm($_POST);
                    $editorBaseOverride=is_string($_POST['base_revision']??null)?$_POST['base_revision']:'';
                }
                if(\VazinCMS\EditorPageSave::isConflict($e)){
                    http_response_code(409);$manualConflict=true;
                    $attempted=\VazinCMS\EditorPageSave::attemptedForm($_POST);
                    $error='نسخهٔ صفحه یا پیش‌نویس تغییر کرده است؛ چیزی بازنویسی نشد و تغییرات شما در فرم حفظ شده‌اند.';
                }elseif(http_response_code()===419){$error='نشست یا توکن فرم معتبر نیست؛ صفحه را دوباره باز کنید.';}
                else{$error=self::denyOrError($e,'محتوا ذخیره نشد.');}
            }
        }
        $pages=$writer->pages((int)$user['id']);
        $filter=(string)($_GET['type']??'all');
        if(in_array($filter,['page','post'],true)) {
            $pages=array_values(array_filter($pages,static fn(array $p):bool=>
                ($p['content_type']??'page')===$filter));
        }
        $edit=null;$id=(int)($_GET['edit']??0);
        if($id>0){
            try{$edit=$writer->page((int)$user['id'],$id);}
            catch(Throwable){http_response_code(404);$error='این محتوا در سایت جاری پیدا نشد.';}
        }
        if($attempted!==null)$edit=$attempted;
        View::render('pages',compact('user','pages','edit','error','manualConflict','editorBaseOverride'));
    }

    public function autosave(): void
    {
        [$user,,$writer]=$this->context();
        Security::verifyCsrf();
        $pageId=(int)($_POST['page_id']??0);
        try{
            $result=$writer->autosave(
                (int)$user['id'],$pageId,(string)($_POST['title']??''),
                (string)($_POST['body']??''),
                ['locale'=>(string)($_POST['locale']??'fa')]
            );
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE);
        }catch(Throwable){
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"error":"site_or_permission_denied"}';
        }
    }

    public function restoreTrash(): void
    {
        [$user,,$writer]=$this->context();
        Security::verifyCsrf();
        $id=(int)($_POST['id']??0);
        try{
            $writer->restoreTrash((int)$user['id'],$id);
            Audit::log('cms.page_restored','محتوای سایت بازگردانی شد',(int)$user['id'],
                ['page_id'=>$id,'site_id'=>SiteContext::id()]);
            header('Location: /admin/pages?restored=1');
        }catch(Throwable){
            http_response_code(404);
            View::render('error',['title'=>'محتوا موجود نیست',
                'message'=>'این محتوا در سایت جاری پیدا نشد.']);
        }
    }

    public function menus(): void
    {
        [$user,,$writer]=$this->context();
        $error=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf();
            try{
                $action=(string)($_POST['action']??'save');
                if($action==='delete'){
                    $writer->deleteMenu((int)$user['id'],(int)($_POST['id']??0));
                }else{
                    if($action!=='save')throw new InvalidArgumentException('عملیات نامعتبر است.');
                    $writer->saveMenu((int)$user['id'],
                        (string)($_POST['locale']??'fa'),
                        trim((string)($_POST['label']??'')),
                        trim((string)($_POST['url']??'')),
                        (int)($_POST['position']??0));
                }
                Audit::log('cms.menu_changed','منوی سایت تغییر کرد',(int)$user['id'],
                    ['site_id'=>SiteContext::id()]);
                header('Location: /admin/menus');
                return;
            }catch(Throwable $e){$error=self::denyOrError($e,'منو ذخیره نشد.');}
        }
        $items=$writer->menus((int)$user['id']);
        View::render('menus',compact('user','items','error'));
    }

    public function revisions(): void
    {
        (new ContentRevisionsController())->handle();
    }

    public function settings(): void
    {
        [$user,$pdo,$writer]=$this->context();
        $error=null;$message=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf();
            $enabled=array_values(array_intersect(self::LOCALES,(array)($_POST['enabled_locales']??[])));
            $default=(string)($_POST['default_locale']??'fa');
            $profile=(string)($_POST['site_profile']??'corporate');
            if(!$enabled||!in_array($default,$enabled,true)||
               !in_array($profile,\VazinCMS\BlueprintManager::validProfiles(),true)) {
                $error='زبان پیش‌فرض یا نوع سایت معتبر نیست.';
            }else{
                try{
                    $keys=[
                        'site_name','site_tagline','site_description','logo_url',
                        'default_locale','contact_email','contact_phone','contact_address',
                        'site_profile','primary_color','hero_title','hero_text','hero_badge',
                        'primary_cta_label','primary_cta_url','secondary_cta_label',
                        'secondary_cta_url','stats_json','features_json','steps_json',
                        'faq_json','social_telegram','social_instagram','social_youtube','footer_text'
                    ];
                    $updates=[];
                    foreach($keys as $key)$updates[$key]=trim((string)($_POST[$key]??''));
                    $updates['enabled_locales']=json_encode($enabled,JSON_UNESCAPED_UNICODE);
                    $writer->saveSettings((int)$user['id'],$updates);
                    CacheStore::invalidateTag('settings');
                    Audit::log('cms.settings_saved','تنظیمات سایت ذخیره شد',(int)$user['id'],
                        ['site_id'=>SiteContext::id()]);
                    $message='تنظیمات سایت ذخیره شد.';
                }catch(Throwable $e){$error=self::denyOrError($e,'تنظیمات ذخیره نشد.');}
            }
        }
        $repo=new \VazinCMS\SiteScopedContentRepository($pdo,SiteContext::id());
        $settings=$repo->settingsMap();
        $enabledLocales=json_decode((string)($settings['enabled_locales']??'["fa","ru","en"]'),true)?:['fa'];
        View::render('cms-settings',compact('user','settings','enabledLocales','message','error'));
    }

    public function media(): void
    {
        [$user,$pdo,$writer]=$this->context();
        $error=null;
        $storage=new SiteScopedMediaService($pdo,SiteContext::id(),$writer);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf();
            try{
                if(($_POST['action']??'')==='delete'){
                    $storage->delete((int)$user['id'],(int)($_POST['id']??0));
                }else{
                    $storage->upload((int)$user['id'],(array)($_FILES['media']??[]),
                        (string)($_POST['alt_text']??''));
                }
                CacheStore::invalidateTag('media');
                Audit::log('cms.media_changed','رسانه سایت تغییر کرد',(int)$user['id'],
                    ['site_id'=>SiteContext::id()]);
                header('Location: /admin/media');
                return;
            }catch(Throwable $e){$error=self::denyOrError($e,'رسانه ذخیره نشد.');}
        }
        $media=$writer->media((int)$user['id']);
        View::render('media',compact('user','media','error'));
    }

    public function mediaPicker(): void
    {
        [$user,,$writer]=$this->context();
        $items=[];
        foreach($writer->mediaPicker((int)$user['id']) as $row) {
            $items[]=[
                'id'=>(int)$row['id'],
                'name'=>(string)$row['file_name'],
                'mime'=>(string)$row['mime_type'],
                'width'=>$row['width']===null?null:(int)$row['width'],
                'height'=>$row['height']===null?null:(int)$row['height'],
                'alt'=>(string)$row['alt_text'],
                'url'=>'/uploads/'.rawurlencode((string)$row['stored_name'])
            ];
        }
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'items'=>$items],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
