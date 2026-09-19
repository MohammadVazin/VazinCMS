<?php
declare(strict_types=1);
namespace VazinCMS;
use VazinCMS\Controllers\{AccountController,AuthController,ContentController,DeveloperApiController,ExtensionController,InstallController};
use Throwable;
final class App{
 public function run():void{
  $path='/'.trim((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'),'/');
  $this->securityHeaders($path);
  try{
   if($path==='/health'){$this->health();return;}
   SiteContext::resolve(Database::connection());
   if(!is_file(RuntimePaths::storage().'/installed.lock')&&$path!=='/install'&&$path!=='/health'){header('Location: /install');return;}
   if(str_starts_with($path,'/admin'))Access::authorizePath($path);
   if($this->dispatchCore($path))return;
   if(ExtensionRuntime::dispatch((string)($_SERVER['REQUEST_METHOD']??'GET'),$path))return;
   $redirect=RedirectManager::resolve(Database::connection(),$path);if($redirect){http_response_code((int)$redirect['status_code']);header('Location: '.(string)$redirect['target_url']);return;}
   $this->notFound();
  }catch(Throwable $e){$correlation=bin2hex(random_bytes(6));http_response_code(500);error_log('[VazinCMS '.Version::current().' correlation='.$correlation.'] '.$e);try{UiLocale::boot();$message=UiLocale::message('internal_error',['correlation'=>$correlation]);}catch(Throwable){$message='Internal error. Reference: '.$correlation;}View::render('error',['title'=>'VazinCMS','message'=>$message]);}
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

  if($path==='/api/v3/content'){(new DeveloperApiController())->content();return true;}
  if($path==='/api/v3/forms'){(new DeveloperApiController())->forms();return true;}
  if($path==='/api/v3/learn/courses'){(new DeveloperApiController())->courses();return true;}
  if($path==='/api/v3/connectors'){(new DeveloperApiController())->connectors();return true;}
  if($path==='/api/v3/openapi.json'){header('Content-Type: application/json; charset=utf-8');readfile(dirname(__DIR__).'/public/openapi-v3.json');return true;}

  if($path==='/admin'||$path==='/admin/pages'){(new ContentController())->pages();return true;}
  if($path==='/admin/pages/autosave'){(new ContentController())->autosave();return true;}
  if($path==='/admin/pages/restore-trash'){(new ContentController())->restoreTrash();return true;}
  if($path==='/admin/menus'){(new ContentController())->menus();return true;}
  if($path==='/admin/media'){(new ContentController())->media();return true;}
  if($path==='/admin/media/picker'){(new ContentController())->mediaPicker();return true;}
  if($path==='/admin/revisions'){(new ContentController())->revisions();return true;}
  if($path==='/admin/settings'){(new ContentController())->settings();return true;}
  if($path==='/admin/modules'||$path==='/admin/extensions'){(new ExtensionController())->index();return true;}
  if(preg_match('#^/module-assets/([a-z0-9][a-z0-9-]{1,78})/([A-Za-z0-9._/-]+)$#',$path,$match)===1){ExtensionAsset::serve('module',$match[1],$match[2]);return true;}
  if(preg_match('#^/theme-assets/([a-z0-9][a-z0-9-]{1,78})/([A-Za-z0-9._/-]+)$#',$path,$match)===1){ThemeManager::serveAsset($match[1],$match[2]);return true;}
  if(preg_match('#^/uploads/([a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf))$#',$path,$match)===1){(new ContentController())->publicMedia($match[1]);return true;}
  if($path==='/search'){header('Content-Type: application/json; charset=utf-8');$q=(string)($_GET['q']??'');$locale=(string)($_GET['locale']??'');$type=(string)($_GET['type']??'');echo json_encode(['ok'=>true,'items'=>SearchIndex::search(Database::connection(),$q,$locale,$type)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;}
  if($path==='/'){(new ContentController())->home();return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)$#',$path,$match)===1){(new ContentController())->home($match[1]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/my-account$#',$path,$match)===1){(new AccountController())->dashboard($match[1]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/page/([a-z0-9][a-z0-9-]{0,188})$#',$path,$match)===1){(new ContentController())->page($match[1],$match[2]);return true;}
  if(preg_match('#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/module/([a-z0-9][a-z0-9-]{1,78})$#',$path,$match)===1){(new ContentController())->module($match[1],$match[2]);return true;}
  return false;
 }
 private function securityHeaders(string $path):void{$nonce=Security::cspNonce();$isTelegramMiniApp=str_starts_with($path,'/telegram/assistant/');header('X-Content-Type-Options: nosniff');if(!$isTelegramMiniApp)header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: no-referrer');header("Permissions-Policy: camera=(), microphone=(), geolocation=()");$frameAncestors=$isTelegramMiniApp?"'self' https://web.telegram.org https://*.telegram.org":"'self'";header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; script-src 'self' 'nonce-{$nonce}' https://telegram.org; frame-src https://oauth.telegram.org; connect-src 'self'; object-src 'none'; form-action 'self'; frame-ancestors {$frameAncestors}; base-uri 'self'");}
 private function health():void{$ok=false;try{Database::connection()->query('SELECT 1');$ok=true;}catch(Throwable){}http_response_code($ok?200:503);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>$ok,'service'=>'VazinCMS','version'=>Version::current()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private function connectorHealth():void{$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\nGET\n/connector/v1/health";if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>300||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false}';return;}$this->health();}
 private function connectorDeploymentInfo():void{$path='/connector/v1/deployment-info';$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\nGET\n".$path;if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>300||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false}';return;}$pdo=Database::connection();$modules=array_map(static fn(array$row):array=>['module_key'=>$row['extension_key'],'version'=>$row['version'],'is_enabled'=>1],ExtensionManager::activeModules());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'product'=>'VazinCMS','version'=>Version::current(),'php'=>PHP_VERSION,'database'=>$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),'modules'=>$modules,'capabilities'=>['target-preview','registered-backup','rollback','extension-packages','theme-runtime','module-routes','module-hooks','telegram-sync','telegram-webapp','telegram-business-assistant','vazin-id-module','seo-advisories']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private function connectorStatus():void{$path='/connector/v2/status';$secret=(string)getenv('VAZIN_CONNECTOR_SECRET');$ts=(string)($_SERVER['HTTP_X_VAZIN_TIMESTAMP']??'');$nonce=(string)($_SERVER['HTTP_X_VAZIN_NONCE']??'');$sig=(string)($_SERVER['HTTP_X_VAZIN_SIGNATURE']??'');$payload=$ts."\n".$nonce."\nGET\n".$path;if($secret===''||!ctype_digit($ts)||abs(time()-(int)$ts)>120||!preg_match('/^[a-f0-9]{32,128}$/',$nonce)||!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"code":"unauthorized"}';return;}try{$schema=SchemaHealth::repairAuthentication();$pdo=Database::connection();$driver=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);$pdo->exec($driver==='sqlite'?"DELETE FROM connector_nonces WHERE expires_at < datetime('now')":"DELETE FROM connector_nonces WHERE expires_at < CURRENT_TIMESTAMP");$insert=$pdo->prepare($driver==='sqlite'?"INSERT INTO connector_nonces(nonce,expires_at) VALUES(:nonce,datetime('now','+5 minutes'))":"INSERT INTO connector_nonces(nonce,expires_at) VALUES(:nonce,CURRENT_TIMESTAMP + INTERVAL '5 minutes')");try{$insert->execute(['nonce'=>$nonce]);}catch(Throwable){http_response_code(409);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"code":"replayed_request"}';return;}$owner=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='owner' AND status='active'")->fetchColumn();$loginView=is_file(dirname(__DIR__).'/views/login.php');$ok=$owner>0&&$loginView;header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>$ok,'service'=>'VazinCMS','version'=>Version::current(),'connector_version'=>'2.2.0','checks'=>['database'=>true,'authentication_schema'=>$schema['ok'],'active_owner'=>$owner>0,'login_view'=>$loginView,'login_path'=>'/login'],'repaired'=>$schema['repairs'],'checked_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){$correlation=bin2hex(random_bytes(6));error_log('[VazinCMS connector correlation='.$correlation.'] '.$e);http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'code'=>'authentication_unhealthy','correlation'=>$correlation],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}}
 private function notFound():void{http_response_code(404);View::renderPublic('not-found',['locale'=>UiLocale::detect()]);}
}
