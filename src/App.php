<?php
declare(strict_types=1);
namespace VazinCMS;
use VazinCMS\Controllers\{AccountController,AdminWorkspaceController,AuthController,BlueprintAdminController,ContentController,DeveloperApiController,DocumentationController,ExtensionController,HeadlessApiController,InstallController};
use Throwable;
final class App{
 public function run():void{
  $path='/'.trim((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'),'/');
  $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
  $this->securityHeaders($path);
  try{
   if($path==='/health'){$this->health();return;}
   $isolation=SiteIsolationGate::evaluate(Database::connection());
   if($isolation!=='ok'){SiteIsolationGate::reject($isolation,$path);return;}
   if(in_array($path,['/auth/vazin-id/start','/auth/vazin-id/callback'],true) && !VazinIdSettings::configured()){
     $this->identityUnavailable('identity_provider_unavailable',503);return;
   }
   SiteContext::resolve(Database::connection());
   if (str_starts_with($path,'/admin') && SiteScopeMode::active(Database::connection())) {
     $siteUser=Auth::user();
     if (is_array($siteUser)) {
       try {
         SiteScopedSessionGuard::assertAdminAccess(
           Database::connection(),SiteContext::id(),(int)$siteUser['id']
         );
         SiteScopedAdminSessionBinding::assertActive(
           Database::connection(),SiteContext::id(),(int)$siteUser['id'],
           session_id(),(string)($_SERVER['HTTP_HOST']??''),
           (string)($_SESSION['auth_provider']??''),
           (int)($_SESSION['session_version']??0)
         );
       } catch(\Throwable) {
         http_response_code(403);
         View::render('error',[
           'title'=>'دسترسی سایت تأیید نشد',
           'message'=>'حساب فعلی عضویت معتبر در سایت و دامنه تأییدشده ندارد.'
         ]);
         return;
       }
     }
   }
   if(str_starts_with($path,'/admin')
      &&SiteScopedRuntimePolicy::scopedMode(Database::connection())
      &&!SiteScopedAdminSurfaceGate::allowed($path)) {
     http_response_code(409);
     header('Cache-Control: no-store');
     View::render('error',[
       'title'=>'این بخش هنوز برای سایت مستقل آماده نیست',
       'message'=>'برای جلوگیری از نمایش اطلاعات مشترک، این بخش تا تکمیل جداسازی سایت‌ها غیرفعال است.'
     ]);
     return;
   }
   if(!is_file(RuntimePaths::storage().'/installed.lock')&&$path!=='/install'&&$path!=='/health'){header('Location: /install');return;}
   if(SiteScopedIntegrationSurfaceGate::isIntegrationPath($path)
      &&SiteScopedRuntimePolicy::scopedMode(Database::connection())
      &&!SiteScopedIntegrationSurfaceGate::allowed($path)){
     http_response_code(409);
     header('Cache-Control: no-store');
     header('Content-Type: application/json; charset=utf-8');
     echo '{"ok":false,"error":{"code":"tenant_integration_not_ready"}}';
     return;
   }
   $cacheable=PageCacheService::enabled()&&PageCacheService::isCacheableRequest($method,$path);
   $host=(string)($_SERVER['HTTP_HOST']??'localhost');
   if($cacheable){
     $hit=PageCacheService::get(SiteContext::id(),$host,$path,$_GET);
     if($hit!==null){
       header('X-Vazin-Cache: HIT');
       header('ETag: '.$hit['etag']);
       header('Content-Type: '.$hit['content_type']);
       if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$hit['etag']){http_response_code(304);return;}
       if($method!=='HEAD')echo $hit['body'];
       return;
     }
     header('X-Vazin-Cache: MISS');
   }
   if(str_starts_with($path,'/admin'))Access::authorizePath($path);
   if($method==='GET'&&!str_starts_with($path,'/admin')&&!str_starts_with($path,'/api')&&!str_starts_with($path,'/module-assets')&&!str_starts_with($path,'/theme-assets')&&!str_starts_with($path,'/uploads')){
     ExtensionRuntime::emit('page.viewed',['path'=>$path,'referrer'=>(string)($_SERVER['HTTP_REFERER']??''),'user_agent'=>(string)($_SERVER['HTTP_USER_AGENT']??''),'ip'=>(string)($_SERVER['REMOTE_ADDR']??'')]);
   }
   if($this->dispatchCore($path))return;
   if(ExtensionRuntime::dispatch($method,$path))return;
   $redirect=RedirectManager::resolve(Database::connection(),$path);if($redirect){http_response_code((int)$redirect['status_code']);header('Location: '.(string)$redirect['target_url']);return;}
   $this->notFound();
  }catch(Throwable $e){
    if(in_array($path,['/auth/vazin-id/start','/auth/vazin-id/callback'],true)){
      $invalid=($path==='/auth/vazin-id/callback' && $e instanceof \InvalidArgumentException);
      $this->identityUnavailable($invalid?'invalid_oauth_callback':'identity_provider_unavailable',$invalid?400:503);return;
    }
    $correlation=bin2hex(random_bytes(6));http_response_code(500);error_log('[VazinCMS '.Version::current().' correlation='.$correlation.'] '.$e);try{UiLocale::boot();$message=UiLocale::message('internal_error',['correlation'=>$correlation]);}catch(Throwable){$message='Internal error. Reference: '.$correlation;}View::render('error',['title'=>'VazinCMS','message'=>$message]);
  }
 }
 private function dispatchCore(string $path):bool{
  if($path==='/install'){(new InstallController())->handle();return true;}
  if($path==='/login'){(new AuthController())->login();return true;}
  if($path==='/auth/vazin-id/start'){(new AuthController())->vazinIdStart();return true;}
  if($path==='/auth/vazin-id/callback'){(new AuthController())->vazinIdCallback();return true;}
  if($path==='/logout'){(new AuthController())->logout();return true;}
  if($path==='/health'){$this->health();return true;}
  if($path==='/connector/v1/health'){$this->connectorHealth();return true;}
  if($path==='/connector/v1/deployment-info'){$this->connectorDeploymentInfo();return true;}
  if($path==='/connector/v2/status'){$this->connectorStatus();return true;}

  if($path==='/api/v1/status'){(new HeadlessApiController())->status();return true;}
  if($path==='/api/v1/types'){(new HeadlessApiController())->types();return true;}
  if(preg_match('#^/api/v1/entries/([a-z0-9][a-z0-9-]{0,62})$#',$path,$match)===1){(new HeadlessApiController())->entries($match[1]);return true;}

  if($path==='/api/v3/content'){(new DeveloperApiController())->content();return true;}
  if($path==='/api/v3/forms'){(new DeveloperApiController())->forms();return true;}
  if($path==='/api/v3/learn/courses'){(new DeveloperApiController())->courses();return true;}
  if($path==='/api/v3/connectors'){(new DeveloperApiController())->connectors();return true;}
  if($path==='/api/v3/openapi.json'){header('Content-Type: application/json; charset=utf-8');readfile(dirname(__DIR__).'/public/openapi-v3.json');return true;}

  if($path==='/documentation'){header('Location: /docs',true,301);return true;}
  if($path==='/api'){header('Location: /developers',true,301);return true;}
  if($path==='/api/openapi.json'){(new DocumentationController())->openApi();return true;}
  if(in_array($path,['/docs','/download','/developers','/extensions','/themes','/changelog'],true)){
   (new DocumentationController())->page(ltrim($path,'/'));
   return true;
  }

  if($path==='/admin'){(new AdminWorkspaceController())->index();return true;}
  if($path==='/admin/pages'){(new ContentController())->pages();return true;}
  if($path==='/admin/pages/autosave'){(new ContentController())->autosave();return true;}
  if($path==='/admin/pages/restore-trash'){(new ContentController())->restoreTrash();return true;}
  if($path==='/admin/content-types'){(new BlueprintAdminController())->contentTypes();return true;}
  if($path==='/admin/blueprints'){(new BlueprintAdminController())->blueprints();return true;}
  if($path==='/admin/menus'){(new ContentController())->menus();return true;}
  if($path==='/admin/media'){(new ContentController())->media();return true;}
  if($path==='/admin/media/picker'){(new ContentController())->mediaPicker();return true;}
  if($path==='/admin/revisions'){(new ContentController())->revisions();return true;}
  if($path==='/admin/layouts'){(new \VazinCMS\Controllers\SiteCanvasController())->handle();return true;}
  if($path==='/admin/template-builder'){(new \VazinCMS\Controllers\SiteTemplateBuilderController())->handle();return true;}
  if($path==='/admin/design'){(new \VazinCMS\Controllers\SiteDesignController())->handle();return true;}
  if($path==='/admin/settings'){(new ContentController())->settings();return true;}
  if($path==='/admin/modules'||$path==='/admin/extensions'){(new ExtensionController())->index();return true;}
  if(preg_match('#^/module-assets/([a-z0-9][a-z0-9-]{1,78})/([A-Za-z0-9._/-]+)$#',$path,$match)===1){ExtensionAsset::serve('module',$match[1],$match[2]);return true;}
  if(preg_match('#^/theme-assets/([a-z0-9][a-z0-9-]{1,78})/([A-Za-z0-9._/-]+)$#',$path,$match)===1){ThemeManager::serveAsset($match[1],$match[2]);return true;}
  if(preg_match('#^/uploads/([a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf))$#',$path,$match)===1){(new ContentController())->publicMedia($match[1]);return true;}
  if($path==='/search'){header('Content-Type: application/json; charset=utf-8');$q=(string)($_GET['q']??'');$locale=(string)($_GET['locale']??'');$type=(string)($_GET['type']??'');echo json_encode(['ok'=>true,'items'=>SearchIndex::search(Database::connection(),$q,$locale,$type)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;}
  if($path==='/'){(new ContentController())->home();return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)$#',$path,$match)===1){(new ContentController())->home($match[1]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/my-account$#',$path,$match)===1){(new AccountController())->dashboard($match[1]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/archive$#',$path,$match)===1){(new ContentController())->archive($match[1]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/page/([a-z0-9][a-z0-9-]{0,188})$#',$path,$match)===1){(new ContentController())->page($match[1],$match[2]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/module/([a-z0-9][a-z0-9-]{1,78})$#',$path,$match)===1){(new ContentController())->module($match[1],$match[2]);return true;}
  return false;
 }
 private function identityUnavailable(string $code='identity_provider_unavailable',int $status=503):void{
    $code=in_array($code,['identity_provider_unavailable','invalid_oauth_callback'],true)?$code:'identity_provider_unavailable';
    $status=$status===400?400:503;
    $correlation=bin2hex(random_bytes(6));
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Correlation-ID: '.$correlation);
    error_log('[VazinCMS auth correlation='.$correlation.'] code='.$code);
    $locale=UiLocale::detect();
    $copies=[
      'en'=>['title'=>'VazinCMS sign-in','invalid'=>'Authorization request invalid or expired.','unavailable'=>'Identity provider temporarily unavailable.','back'=>'Return to the home page'],
      'fa'=>['title'=>'ورود به VazinCMS','invalid'=>'درخواست ورود نامعتبر یا منقضی شده است.','unavailable'=>'سامانهٔ ورود موقتاً در دسترس نیست.','back'=>'بازگشت به صفحهٔ اصلی'],
      'ar'=>['title'=>'تسجيل الدخول إلى VazinCMS','invalid'=>'طلب تسجيل الدخول غير صالح أو منتهي الصلاحية.','unavailable'=>'خدمة تسجيل الدخول غير متاحة مؤقتًا.','back'=>'العودة إلى الصفحة الرئيسية'],
      'ru'=>['title'=>'Вход в VazinCMS','invalid'=>'Запрос авторизации недействителен или просрочен.','unavailable'=>'Сервис входа временно недоступен.','back'=>'Вернуться на главную'],
    ];
    if(!isset($copies[$locale]))$locale='en';
    $copy=$copies[$locale];
    $message=$copy[$status===400?'invalid':'unavailable'];
    if(str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json')){
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'code'=>$code,'message'=>$message,'correlation'=>$correlation],
                       JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      return;
    }
    header('Content-Type: text/html; charset=utf-8');
    try {
      View::render('error',['title'=>$copy['title'],'message'=>$message]);
      return;
    } catch(Throwable $renderError) {
      error_log('[VazinCMS auth correlation='.$correlation.'] fallback=no_database_view');
    }
    $font='/assets/Vazirmatn.woff2';
    $codeSafe=htmlspecialchars($code,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $messageSafe=htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $titleSafe=htmlspecialchars($copy['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $backSafe=htmlspecialchars($copy['back'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $direction=in_array($locale,['fa','ar'],true)?'rtl':'ltr';
    echo '<!doctype html><html lang="'.$locale.'" dir="'.$direction.'"><head><meta charset="utf-8">'
       .'<meta name="viewport" content="width=device-width, initial-scale=1">'
       .'<meta name="color-scheme" content="light dark">'
       .'<link rel="preload" href="'.$font.'" as="font" type="font/woff2" crossorigin>'
       .'<title>'.$titleSafe.'</title><style>'
       .'@font-face{font-family:Vazirmatn;src:url('.$font.') format("woff2");font-style:normal;font-weight:100 900;font-display:swap}'
       .':root{--v-ui-font:"Vazirmatn","Segoe UI",Tahoma,Arial,sans-serif;--bg:#f5f7fb;--surface:#fff;--text:#172033;--muted:#52627a;--border:#dce3ee;--primary:#2457c5}'
       .'html,body{margin:0;min-height:100%;font-family:var(--v-ui-font);letter-spacing:normal}button,input,select,textarea{font:inherit}'
       .'body{background:var(--bg);color:var(--text);display:grid;place-items:center;min-height:100svh;padding:16px;box-sizing:border-box}'
       .'main{box-sizing:border-box;width:min(100%,520px);background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:32px;box-shadow:0 12px 32px #17203315}'
       .'h1{font-size:1.5rem;margin-block:0 16px}p{line-height:1.9;margin-block:0 16px;color:var(--muted)}'
       .'a{color:var(--primary);text-decoration:underline;text-underline-offset:4px}'
       .'a:focus-visible{outline:3px solid var(--primary);outline-offset:4px;border-radius:8px}'
       .'code{direction:ltr;unicode-bidi:isolate;font-family:ui-monospace,Consolas,monospace}'
       .'@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}'
       .'</style></head><body><main><h1>'.$titleSafe.'</h1><p>'.$messageSafe.'</p>'
       .'<p><code dir="ltr">'.$codeSafe.'</code></p>'
       .'<a href="/">'.$backSafe.'</a></main></body></html>';
  }
 private function securityHeaders(string $path):void{$nonce=Security::cspNonce();$isTelegramMiniApp=str_starts_with($path,'/telegram/assistant/');header('X-Content-Type-Options: nosniff');if(!$isTelegramMiniApp)header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: no-referrer');header("Permissions-Policy: camera=(), microphone=(), geolocation=()");$frameAncestors=$isTelegramMiniApp?"'self' https://web.telegram.org https://*.telegram.org":"'self'";header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; script-src 'self' 'nonce-{$nonce}' https://telegram.org; frame-src https://oauth.telegram.org; connect-src 'self'; object-src 'none'; form-action 'self'; frame-ancestors {$frameAncestors}; base-uri 'self'");}
 private function health():void{$ok=false;try{Database::connection()->query('SELECT 1');$ok=true;}catch(Throwable){}http_response_code($ok?200:503);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>$ok,'service'=>'VazinCMS','version'=>Version::current()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private function connectorHealth():void{$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\nGET\n/connector/v1/health";if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>300||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false}';return;}$this->health();}
 private function connectorDeploymentInfo():void{$path='/connector/v1/deployment-info';$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\nGET\n".$path;if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>300||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false}';return;}$pdo=Database::connection();$modules=array_map(static fn(array$row):array=>['module_key'=>$row['extension_key'],'version'=>$row['version'],'is_enabled'=>1],ExtensionManager::activeModules());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'product'=>'VazinCMS','version'=>Version::current(),'php'=>PHP_VERSION,'database'=>$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),'modules'=>$modules,'capabilities'=>['microkernel-v2','module-schema-v2','isolated-module-db','dynamic-content-engine','site-blueprints','page-cache','headless-api-v1','target-preview','registered-backup','rollback','extension-packages','theme-runtime','module-routes','module-hooks','seo-advisories']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private function connectorStatus():void{$path='/connector/v2/status';$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$nonce=(string)($_SERVER['HTTP_X_VAZIN_NONCE']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\n".$nonce."\nGET\n".$path;if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>120||!preg_match('/^[a-f0-9]{32,128}$/',$nonce)||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"code":"unauthorized"}';return;}try{$schema=SchemaHealth::repairAuthentication();$pdo=Database::connection();$driver=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);$pdo->exec($driver==='sqlite'?"DELETE FROM connector_nonces WHERE expires_at < datetime('now')":"DELETE FROM connector_nonces WHERE expires_at < CURRENT_TIMESTAMP");$insert=$pdo->prepare($driver==='sqlite'?"INSERT INTO connector_nonces(nonce,expires_at) VALUES(:nonce,datetime('now','+5 minutes'))":"INSERT INTO connector_nonces(nonce,expires_at) VALUES(:nonce,CURRENT_TIMESTAMP + INTERVAL '5 minutes')");try{$insert->execute(['nonce'=>$nonce]);}catch(Throwable){http_response_code(409);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"code":"replayed_request"}';return;}$owner=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='owner' AND status='active'")->fetchColumn();$loginView=is_file(dirname(__DIR__).'/views/login.php');$ok=$owner>0&&$loginView;header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>$ok,'service'=>'VazinCMS','version'=>Version::current(),'connector_version'=>'2.3.0','checks'=>['database'=>true,'authentication_schema'=>$schema['ok'],'active_owner'=>$owner>0,'login_view'=>$loginView,'login_path'=>'/login'],'repaired'=>$schema['repairs'],'checked_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){$correlation=bin2hex(random_bytes(6));error_log('[VazinCMS connector correlation='.$correlation.'] '.$e);http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'code'=>'authentication_unhealthy','correlation'=>$correlation],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}}
 private function notFound():void{http_response_code(404);View::renderPublic('not-found',['locale'=>UiLocale::detect()]);}
}
