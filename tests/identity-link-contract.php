<?php
declare(strict_types=1);

$root=dirname(__DIR__);putenv('SESSION_SECURE=false');require$root.'/src/bootstrap.php';
function expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function expectFailure(callable $operation,string $message,string $contains=''):void{try{$operation();}catch(RuntimeException$error){if($contains!==''&&!str_contains($error->getMessage(),$contains))throw new RuntimeException($message.' (wrong UX)');return;}throw new RuntimeException($message);}
function database():PDO{$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL,status TEXT NOT NULL)");$pdo->exec("CREATE TABLE user_identities(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,provider TEXT NOT NULL,provider_subject TEXT NOT NULL,email TEXT,email_verified INTEGER NOT NULL DEFAULT 0,linked_at TEXT,last_login_at TEXT,display_name TEXT,display_name_source TEXT,preferred_username TEXT,picture_url TEXT,locale TEXT,theme TEXT,profile_updated_at INTEGER,profile_source TEXT,preferences_shared INTEGER,profile_revision INTEGER CHECK(profile_revision IS NULL OR (typeof(profile_revision)='integer' AND profile_revision BETWEEN 1 AND 9007199254740991)),UNIQUE(provider,provider_subject),UNIQUE(user_id,provider))");foreach([['Owner','owner@example.test','owner'],['Admin','admin@example.test','admin'],['Support','support@example.test','support'],['Client','client@example.test','client']]as[$name,$email,$role]){$statement=$pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,?,'active')");$statement->execute([$name,$email,'hash',$role]);}return$pdo;}
function profile(string $sub,string $email,mixed $verified=true):array{return['sub'=>$sub,'email'=>$email,'email_verified'=>$verified,'name'=>'Identity User'];}

$pdo=database();
foreach(['owner@example.test','admin@example.test','support@example.test','client@example.test']as$email)expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-COLLISION-'.md5($email),$email)),"Email collision auto-merged $email");
expect((int)$pdo->query('SELECT count(*) FROM user_identities')->fetchColumn()===0,'Collision created an identity link');
$owner=VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-OWNER','owner@example.test'),1);expect((int)$owner['id']===1,'Explicit link did not target authenticated user');
$same=VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-OWNER','changed@example.test'));expect((int)$same['id']===1,'Known subject did not resolve by subject');
expect((string)$pdo->query("SELECT email FROM user_identities WHERE provider_subject='VZ-OWNER'")->fetchColumn()==='changed@example.test','Verified metadata did not refresh');
expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-OWNER','unverified@example.test',false)),'Known subject accepted unverified email','ایمیل تأییدشده');
expect((string)$pdo->query("SELECT email FROM user_identities WHERE provider_subject='VZ-OWNER'")->fetchColumn()==='changed@example.test','Unverified metadata changed an existing subject');
expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-OWNER','client@example.test'),4),'Subject was relinked');
expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-SECOND','owner@example.test'),1),'Second identity attached');
foreach([
 ['sub'=>['VZ-BAD'],'email'=>'new@example.test','email_verified'=>true,'name'=>'User'],
 ['sub'=>' VZ-BAD','email'=>'new@example.test','email_verified'=>true,'name'=>'User'],
 ['sub'=>'VZ-BAD-EMAIL','email'=>['new@example.test'],'email_verified'=>true,'name'=>'User'],
 ['sub'=>'VZ-BAD-EMAIL-SPACE','email'=>' new@example.test','email_verified'=>true,'name'=>'User'],
 ['sub'=>'VZ-BAD-VERIFIED','email'=>'new@example.test','email_verified'=>'true','name'=>'User'],
 ['sub'=>'VZ-BAD-NAME','email'=>'new@example.test','email_verified'=>true,'name'=>['User']],
 ['sub'=>'VZ-BAD-CONTROL','email'=>'new@example.test','email_verified'=>true,'name'=>"User\u{0085}Admin"],
 ['sub'=>'VZ-BAD-NAME-SHORT','email'=>'new@example.test','email_verified'=>true,'name'=>'X'],
 ['sub'=>'VZ-BAD-NAME-LONG','email'=>'new@example.test','email_verified'=>true,'name'=>str_repeat('Ж',161)],
]as$badProfile)expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,$badProfile),'Malformed UserInfo was accepted');
foreach(['名名',str_repeat('Ж',160),str_repeat('🙂',160)]as$index=>$validName){$profileName=profile('VZ-NAME-'.$index,'profile-name'.$index.'@example.test');$profileName['name']=$validName;$profileName[VazinCMS\IdentityProfile::SHARED_CLAIM]=false;$profileName[VazinCMS\IdentityProfile::REVISION_CLAIM]=1;$created=VazinCMS\VazinIdentityLink::resolve($pdo,$profileName);$identityName=$pdo->query("SELECT display_name,display_name_source,preferences_shared,profile_revision FROM user_identities WHERE provider_subject='VZ-NAME-".$index."'")->fetch();expect($created['role']==='client'&&$identityName['display_name']===$validName&&$identityName['display_name_source']==='vazin_id'&&(int)$identityName['preferences_shared']===0&&(int)$identityName['profile_revision']===1,'Producer-compatible name boundary failed');}

$new=VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-NEW','new@example.test'));expect($new['role']==='client','Provision elevated the new user');

$shared=profile('VZ-ADMIN','admin@example.test')+[
 'preferred_username'=>'admin.user','picture'=>'https://images.example.test/avatar.png?size=64','locale'=>'ar',
 VazinCMS\IdentityProfile::THEME_CLAIM=>'dark','updated_at'=>time(),
 VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1,'phone_number'=>'+000000000','ledger_balance'=>'SENSITIVE-SENTINEL'
];
$clean=VazinCMS\IdentityProfile::normalize($shared);
expect(!isset($clean['phone_number'])&&!isset($clean['ledger_balance']),'Sensitive or unknown UserInfo leaked into allowlist');
$admin=VazinCMS\VazinIdentityLink::resolve($pdo,$shared,2);expect($admin['role']==='admin','Profile sync changed authorization');
$identity=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
expect($identity['display_name']==='Identity User'&&$identity['preferred_username']==='admin.user','Shared profile was not stored');
expect($identity['locale']==='ar'&&$identity['theme']==='dark'&&$identity['profile_source']==='vazin_id'&&(int)$identity['preferences_shared']===1,'Shared preference provenance missing');
expect((string)$pdo->query('SELECT name FROM users WHERE id=2')->fetchColumn()==='Admin','Identity display metadata overwrote local name');

$clearedOptional=profile('VZ-ADMIN','admin@example.test')+[VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>2];
VazinCMS\VazinIdentityLink::resolve($pdo,$clearedOptional);
$identity=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
foreach(['preferred_username','picture_url','locale','theme','profile_updated_at']as$field)expect($identity[$field]===null,'Shared=true omission retained stale projection: '.$field);
expect($identity['display_name']==='Identity User'&&$identity['profile_source']==='vazin_id','Shared=true lost required display provenance');

$identityFalse=array_replace(profile('VZ-ADMIN','admin@example.test'),['name'=>'Standard Name',VazinCMS\IdentityProfile::SHARED_CLAIM=>false,VazinCMS\IdentityProfile::REVISION_CLAIM=>3]);
VazinCMS\VazinIdentityLink::resolve($pdo,$identityFalse);$identity=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
expect($identity['display_name']==='Standard Name'&&$identity['display_name_source']==='vazin_id','Sharing=false suppressed standard name');
foreach(['preferred_username','picture_url','locale','theme','profile_updated_at']as$field)expect($identity[$field]===null,'Sharing=false retained sourced optional: '.$field);
expect($identity['profile_source']===null&&(int)$identity['preferences_shared']===0,'Sharing=false did not clear optional provenance');

$pdo->exec("UPDATE user_identities SET display_name='Local Name',display_name_source='local',preferred_username='local.user',picture_url='https://local.example.test/avatar.png',locale='en',theme='light',profile_source='local' WHERE provider_subject='VZ-ADMIN'");
$localBefore=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
$identityTrue=profile('VZ-ADMIN','admin@example.test')+['preferred_username'=>'identity.user','locale'=>'ru',VazinCMS\IdentityProfile::THEME_CLAIM=>'dark',VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>4];
VazinCMS\VazinIdentityLink::resolve($pdo,$identityTrue);
$localAfterTrue=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
foreach(['display_name','display_name_source','preferred_username','picture_url','locale','theme','profile_source']as$field)expect($localAfterTrue[$field]===$localBefore[$field],'Vazin ID true overwrote local override: '.$field);

$disabled=profile('VZ-ADMIN','admin@example.test')+[
 'preferred_username'=>'replacement','locale'=>'ru',VazinCMS\IdentityProfile::THEME_CLAIM=>'light',
 VazinCMS\IdentityProfile::SHARED_CLAIM=>false,VazinCMS\IdentityProfile::REVISION_CLAIM=>5
];
VazinCMS\VazinIdentityLink::resolve($pdo,$disabled);
$identity=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
foreach(['display_name','display_name_source','preferred_username','picture_url','locale','theme','profile_source']as$field)expect($identity[$field]===$localBefore[$field],'Sharing=false changed local override: '.$field);
expect((int)$identity['preferences_shared']===0,'Sharing=false provenance not recorded');
expect((string)$pdo->query('SELECT role FROM users WHERE id=2')->fetchColumn()==='admin','Sharing=false changed authorization');

$missing=profile('VZ-ADMIN','admin@example.test')+['preferred_username'=>'must-not-sync','locale'=>'fa'];
VazinCMS\VazinIdentityLink::resolve($pdo,$missing);
$identity=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-ADMIN'")->fetch();
expect($identity['preferred_username']==='local.user'&&$identity['locale']==='en','Missing sharing claim did not preserve prior projection');
expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,profile('VZ-ADMIN','admin@example.test')+[VazinCMS\IdentityProfile::SHARED_CLAIM=>'true']),'Non-boolean sharing claim was accepted','اشتراک');

// The producer consent revision is the sole ordering watermark. A delayed
// callback or an equal-revision true response must not reopen a tombstone.
$revisionShared=profile('VZ-REVISION','revision@example.test')+[
 'name'=>'Revision 100','preferred_username'=>'revision.user','picture'=>'https://images.example.test/r.png','locale'=>'en',
 VazinCMS\IdentityProfile::THEME_CLAIM=>'dark',VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>100
];
VazinCMS\VazinIdentityLink::resolve($pdo,$revisionShared);
$revisionFalse=array_replace($revisionShared,['name'=>'Revision Revoked',VazinCMS\IdentityProfile::SHARED_CLAIM=>false,VazinCMS\IdentityProfile::REVISION_CLAIM=>200]);
VazinCMS\VazinIdentityLink::resolve($pdo,$revisionFalse);
$revisionAfterFalse=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-REVISION'")->fetch();
expect($revisionAfterFalse['display_name']==='Revision Revoked'&&(int)$revisionAfterFalse['profile_revision']===200&&(int)$revisionAfterFalse['preferences_shared']===0,'Newer revocation did not advance the tombstone');
foreach(['preferred_username','picture_url','locale','theme','profile_updated_at']as$field)expect($revisionAfterFalse[$field]===null,'Newer revocation retained Vazin-ID projection: '.$field);
foreach([
 array_replace($revisionShared,['name'=>'Revision Stale',VazinCMS\IdentityProfile::REVISION_CLAIM=>100]),
 array_replace($revisionShared,['name'=>'Revision Equal',VazinCMS\IdentityProfile::REVISION_CLAIM=>200]),
]as$replay){VazinCMS\VazinIdentityLink::resolve($pdo,$replay);$afterReplay=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-REVISION'")->fetch();foreach(['display_name','display_name_source','preferred_username','picture_url','locale','theme','profile_updated_at','profile_source','preferences_shared','profile_revision']as$field)expect($afterReplay[$field]===$revisionAfterFalse[$field],'Stale/equal true reopened a revoked profile: '.$field);}
$revisionFresh=array_replace($revisionShared,['name'=>'Revision 201','preferred_username'=>'revision.fresh','locale'=>'ru',VazinCMS\IdentityProfile::THEME_CLAIM=>'light',VazinCMS\IdentityProfile::REVISION_CLAIM=>201]);
VazinCMS\VazinIdentityLink::resolve($pdo,$revisionFresh);$revisionAfterFresh=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-REVISION'")->fetch();
expect($revisionAfterFresh['display_name']==='Revision 201'&&$revisionAfterFresh['preferred_username']==='revision.fresh'&&$revisionAfterFresh['locale']==='ru'&&$revisionAfterFresh['theme']==='light'&&(int)$revisionAfterFresh['profile_revision']===201&&(int)$revisionAfterFresh['preferences_shared']===1,'Newer shared revision did not reopen the projection');
$legacyFalse=array_replace($revisionFresh,['name'=>'Ignored Legacy Revocation',VazinCMS\IdentityProfile::SHARED_CLAIM=>false]);unset($legacyFalse[VazinCMS\IdentityProfile::REVISION_CLAIM]);
VazinCMS\VazinIdentityLink::resolve($pdo,$legacyFalse);$legacyTombstone=$pdo->query("SELECT * FROM user_identities WHERE provider_subject='VZ-REVISION'")->fetch();
expect((int)$legacyTombstone['profile_revision']===201&&(int)$legacyTombstone['preferences_shared']===0&&$legacyTombstone['display_name']==='Revision 201','Legacy false did not retain the durable revision/name tombstone');
foreach(['preferred_username','picture_url','locale','theme','profile_updated_at']as$field)expect($legacyTombstone[$field]===null,'Legacy false retained Vazin-ID projection: '.$field);
foreach([0,-1,9007199254740992,'201',true]as$badRevision){$bad=profile('VZ-BAD-REVISION','bad-revision@example.test')+[VazinCMS\IdentityProfile::SHARED_CLAIM=>false,VazinCMS\IdentityProfile::REVISION_CLAIM=>$badRevision];expectFailure(static fn()=>VazinCMS\IdentityProfile::normalize($bad),'Invalid profile revision was accepted','نسخه پروفایل');}
$falseIgnoresMalformed=profile('VZ-PRIVACY-FAILSAFE','privacy@example.test')+['preferred_username'=>['bad'],'picture'=>'http://bad.test/a','locale'=>'de',VazinCMS\IdentityProfile::THEME_CLAIM=>'bad',VazinCMS\IdentityProfile::SHARED_CLAIM=>false,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];
expect(VazinCMS\IdentityProfile::normalize($falseIgnoresMalformed)['preferences_shared']===false,'Privacy false parsed malformed optional claims');
$legacyTrueIgnoresMalformed=profile('VZ-LEGACY-TRUE','legacy-true@example.test')+['preferred_username'=>['bad'],'picture'=>'http://bad.test/a','locale'=>'de',VazinCMS\IdentityProfile::THEME_CLAIM=>'bad','updated_at'=>'bad',VazinCMS\IdentityProfile::SHARED_CLAIM=>true];
$legacyTrueNormalized=VazinCMS\IdentityProfile::normalize($legacyTrueIgnoresMalformed);
expect($legacyTrueNormalized['preferences_shared']===true&&!array_key_exists('preferred_username',$legacyTrueNormalized)&&!array_key_exists('picture',$legacyTrueNormalized)&&!array_key_exists('updated_at',$legacyTrueNormalized),'Legacy true without revision parsed malformed optional claims');

$invalidOptional=profile('VZ-SUPPORT','support@example.test')+[
 'preferred_username'=>['bad'],'picture'=>'http://example.test/a.png','locale'=>'de',
 VazinCMS\IdentityProfile::THEME_CLAIM=>'midnight','updated_at'=>'1780000000',VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1
];
expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,$invalidOptional,3),'Invalid optional claim was accepted');
expect((int)$pdo->query("SELECT count(*) FROM user_identities WHERE provider_subject='VZ-SUPPORT'")->fetchColumn()===0,'Invalid optional claim created a projection');
$future=profile('VZ-SUPPORT','support@example.test')+['updated_at'=>time()+86400,VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];
expect(VazinCMS\IdentityProfile::normalize($future)['updated_at']===$future['updated_at'],'Positive future updated_at from exact producer was rejected');
foreach([0,-1,VazinCMS\IdentityProfile::MAX_PROFILE_REVISION+1,'1',true]as$badUpdatedAt){$badUpdated=profile('VZ-BAD-UPDATED-'.md5(serialize($badUpdatedAt)),'bad-updated@example.test')+['updated_at'=>$badUpdatedAt,VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];expectFailure(static fn()=>VazinCMS\IdentityProfile::normalize($badUpdated),'Invalid updated_at was accepted','زمان');}
foreach(['AdminUser','admin-user']as$badUsername){$bad=profile('VZ-SUPPORT','support@example.test')+['preferred_username'=>$badUsername,VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];expectFailure(static fn()=>VazinCMS\VazinIdentityLink::resolve($pdo,$bad,3),'Non-canonical username was accepted');}
foreach(['https://images.example.test:8443/avatar.png','https://192.0.2.10/avatar.png','https://[2001:db8::10]:8443/avatar.png','https://images.example.test./avatar.png','https://999.999.999.999/avatar.png']as$index=>$picture){$portPicture=profile('VZ-PORT-AVATAR-'.$index,'port-avatar'.$index.'@example.test')+['picture'=>$picture,VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];expect(VazinCMS\IdentityProfile::normalize($portPicture)['picture']===$picture,'Valid exact-producer avatar was rejected: '.$picture);}
foreach(['http://images.example.test/a.png','https://user@images.example.test/a.png','https://images.example.test/a.png#fragment','https://a_b.example.test/a.png','https://a..b.example.test/a.png','https://[fe80::1%25eth0]/a.png','https://images.example.test:0/a.png','https://images.example.test:99999/a.png','https://images.example.test:bad/a.png']as$picture){$bad=profile('VZ-BAD-PICTURE-'.md5($picture),'bad-picture@example.test')+['picture'=>$picture,VazinCMS\IdentityProfile::SHARED_CLAIM=>true,VazinCMS\IdentityProfile::REVISION_CLAIM=>1];expectFailure(static fn()=>VazinCMS\IdentityProfile::normalize($bad),'Invalid avatar URL was accepted: '.$picture);}

$_GET=[];$_COOKIE=['vazincms_locale'=>'en'];
expect(VazinCMS\UiLocale::detect(null,'ar')==='en','Local explicit locale did not override Vazin ID preference');
$_COOKIE=[];
foreach(['fa'=>'rtl','ar'=>'rtl','en'=>'ltr','ru'=>'ltr']as$locale=>$direction){VazinCMS\UiLocale::boot($locale);expect(VazinCMS\UiLocale::locale()===$locale&&VazinCMS\UiLocale::direction()===$direction,'Locale/direction render contract failed: '.$locale);}
$sentinel='نام';$brandSentinel='فایل';VazinCMS\UiLocale::boot('en');
$isolate=new ReflectionMethod(VazinCMS\LocalizedTemplate::class,'isolateEchoes');
$flag=true;$source='<button>منو</button><a>صفحه‌ها</a><strong><?=VazinCMS\\Security::e($sentinel)?></strong><span><?=VazinCMS\\Security::e($brandSentinel)?></span><em><?=$flag?\'فعال\':\'غیرفعال\'?></em>';
ob_start();eval('?>'.$isolate->invoke(null,$source));$localizedHead=VazinCMS\UiLocale::html((string)ob_get_clean());
expect(str_contains($localizedHead,'<strong>'.$sentinel.'</strong>'),'Dynamic identity name was corrupted by UI translation');
expect(str_contains($localizedHead,'>فایل</span>'),'Dynamic brand name was corrupted by UI translation');
expect(str_contains($localizedHead,'>Pages</a>')&&str_contains($localizedHead,'>Menu</button>'),'Static CMS chrome was not translated independently');
expect(str_contains($localizedHead,'>Active</em>'),'Static ternary label was hidden by dynamic-slot protection');
$head=(string)file_get_contents($root.'/views/partials/head.php');
foreach(['identity_theme','identity_display_name','identity_picture_url','referrerpolicy="no-referrer"','localStorage.getItem']as$needle)expect(str_contains($head,$needle),'Identity UI rendering contract missing: '.$needle);

// Public POST/error copy is selected before rendering, while submitted values
// remain byte-identical dynamic data. Exercise the real validation method and
// complete public request template for all four canonical locales.
$travelController=new VazinCMS\Controllers\TravelOrderController();$validated=new ReflectionMethod($travelController,'validated');
foreach(['fa'=>'rtl','en'=>'ltr','ru'=>'ltr','ar'=>'rtl']as$locale=>$direction){
 VazinCMS\UiLocale::boot($locale);$_POST=['service_type'=>'invalid','full_name'=>'x','phone'=>'1','email'=>'invalid','notes'=>'نام'];$errors=[];
 $validatedData=$validated->invokeArgs($travelController,[&$errors]);
 $expected=[VazinCMS\UiLocale::message('visa_service_invalid'),VazinCMS\UiLocale::message('visa_name_invalid'),VazinCMS\UiLocale::message('visa_phone_invalid'),VazinCMS\UiLocale::message('visa_email_invalid')];
 expect($errors===$expected,'Localized public POST validation mismatch: '.$locale);
 expect($validatedData['notes']==='نام','Submitted dynamic Persian data was translated: '.$locale);
 $scope=['locale'=>$locale,'settings'=>['enabled_locales'=>'["fa","en","ru","ar"]'],'siteName'=>'نام','profile'=>'travel','menu'=>[],'meta'=>['title'=>VazinCMS\UiLocale::message('visa_request_meta'),'description'=>VazinCMS\UiLocale::message('visa_meta_description')],'title'=>'','products'=>[],'selected'=>'','errors'=>$errors,'appVersion'=>VazinCMS\Version::current()];
 $html=VazinCMS\LocalizedTemplate::render([$root.'/views/public/head.php',$root.'/views/public/request.php',$root.'/views/public/foot.php'],$scope);$html=VazinCMS\UiLocale::html($html);
 expect(str_contains($html,'lang="'.$locale.'"')&&str_contains($html,'dir="'.$direction.'"'),'Public locale/dir is dishonest: '.$locale);
 expect(substr_count($html,'>نام<')>=2,'Dynamic brand sentinel was translated: '.$locale);
 foreach($expected as$message)expect(str_contains($html,VazinCMS\Security::e($message)),'Localized POST error was not rendered: '.$locale);
 if(in_array($locale,['en','ru'],true))expect(preg_match('/[\x{0600}-\x{06FF}]/u',str_replace(['نام','فارسی','العربية'],'',$html))!==1,'Persian static public copy leaked: '.$locale);

 $common=['locale'=>$locale,'settings'=>['enabled_locales'=>'["fa","en","ru","ar"]'],'siteName'=>'نام','profile'=>'travel','menu'=>[],'meta'=>[],'appVersion'=>VazinCMS\Version::current()];
 $pay=VazinCMS\LocalizedTemplate::render([$root.'/views/public/head.php',$root.'/views/public/pay-error.php',$root.'/views/public/foot.php'],$common+['title'=>VazinCMS\UiLocale::message('pay_login_failed_title'),'message'=>VazinCMS\UiLocale::message('pay_login_failed')]);$pay=VazinCMS\UiLocale::html($pay);
 expect(str_contains($pay,VazinCMS\UiLocale::message('pay_login_failed')),'Pay error locale mismatch: '.$locale);
 VazinCMS\UiLocale::boot($locale);$account=VazinCMS\LocalizedTemplate::render([$root.'/views/public/head.php',$root.'/views/public/account.php',$root.'/views/public/foot.php'],$common+['error'=>VazinCMS\UiLocale::message('visa_tracking_invalid')]);$account=VazinCMS\UiLocale::html($account);
 expect(str_contains($account,VazinCMS\UiLocale::message('visa_tracking_invalid')),'Tracking error locale mismatch: '.$locale);
 VazinCMS\UiLocale::boot($locale);$eventMessage=VazinCMS\UiLocale::message('document_uploaded');$order=['public_id'=>'VZUNIT','full_name'=>'نام','product_title'=>'Dynamic Product','service_type'=>'visa','status'=>'documents_required','amount'=>100,'currency'=>'RUB','payment_status'=>'unpaid'];
  $status=VazinCMS\LocalizedTemplate::render([$root.'/views/public/head.php',$root.'/views/public/order-status.php',$root.'/views/public/foot.php'],$common+['order'=>$order,'accessCode'=>'DYNAMICCODE','payUrl'=>'','payError'=>VazinCMS\UiLocale::message('visa_gateway_invalid'),'uploadError'=>VazinCMS\UiLocale::message('upload_file_invalid'),'caseEvents'=>[['created_at'=>'2026-01-01','new_status'=>null,'display_message'=>$eventMessage]],'alertOptIn'=>['status'=>'pending','url'=>'https://example.test/telegram-opt-in'],'caseDocuments'=>[]]);$status=VazinCMS\UiLocale::html($status);
 expect(str_contains($status,$eventMessage)&&str_contains($status,VazinCMS\UiLocale::message('upload_file_invalid')),'Visa status/upload locale mismatch: '.$locale);
 expect(substr_count($status,'نام')>=3,'Dynamic applicant/brand sentinel changed on status page: '.$locale);
 if(in_array($locale,['en','ru'],true))foreach([$pay,$account,$status]as$page)expect(preg_match('/[\x{0600}-\x{06FF}]/u',str_replace(['نام','فارسی','العربية'],'',$page))!==1,'Persian public error/status copy leaked: '.$locale);
}
$paySource=(string)file_get_contents($root.'/src/Controllers/PayController.php');
expect(!str_contains($paySource,"'locale'=>'fa'")&&!str_contains($paySource,"\$result['data']['error']"),'Pay public error still forces Persian or exposes upstream text');
$visaSource=(string)file_get_contents($root.'/src/Controllers/VisaCaseController.php');
expect(!str_contains($visaSource,"visa_upload_error']")&&!str_contains($visaSource,'=$e->getMessage()'),'Visa upload still stores a raw exception for public rendering');

// Exercise an actual SQLite unique violation through the same conflict mapper.
$unique=new ReflectionMethod(VazinCMS\VazinIdentityLink::class,'uniqueGuard');
expectFailure(static fn()=>$unique->invoke(null,static fn()=>$pdo->exec("INSERT INTO users(name,email,password_hash,role,status) VALUES('Race','new@example.test','x','client','active')")),'Unique race exposed raw PDO error','هم‌زمان');
expect((int)$pdo->query("SELECT count(*) FROM users WHERE email='new@example.test'")->fetchColumn()===1,'Unique race left partial data');

$valid=['/admin','/admin/pages?edit=1'];foreach($valid as$value)expect(VazinCMS\LocalReturn::path($value)===$value,'Valid local return rejected');
$invalid=['//evil.test','/\\evil.test','/%252e%252e/admin','/a/../admin','/a/./admin','/auth/vazin-id/callback','/login','/logout','/install','/admin/login',"/admin\0x",'https://evil.test/admin'];foreach($invalid as$value)expect(VazinCMS\LocalReturn::path($value)==='/admin','Unsafe return accepted: '.json_encode($value));

final class DeadPdo extends PDO{public function __construct(){}public function inTransaction():bool{throw new PDOException('connection lost');}}
$rollback=new ReflectionMethod(VazinCMS\Controllers\AuthController::class,'rollbackBestEffort');$rollback->invoke(new VazinCMS\Controllers\AuthController(),new DeadPdo());

$controller=(string)file_get_contents($root.'/src/Controllers/AuthController.php');$client=(string)file_get_contents($root.'/src/VazinIdClient.php');
foreach(["REQUEST_METHOD']??'GET')!=='POST'",'Security::verifyCsrf()','VazinIdentityLink::resolve','rollbackBestEffort']as$needle)expect(str_contains($controller,$needle),'Callback/link guard missing: '.$needle);
expect(str_contains($client,"'link_user_id'=>\$linkUserId"),'Link owner is not bound to state');
$nonce=VazinCMS\Security::cspNonce();expect(preg_match('/\A[A-Za-z0-9_-]{24}\z/D',$nonce)===1&&hash_equals($nonce,VazinCMS\Security::cspNonce()),'CSP nonce is not stable/canonical');
$appSource=(string)file_get_contents($root.'/src/App.php');expect(!str_contains($appSource,"script-src 'self' 'unsafe-inline'")&&str_contains($appSource,"'nonce-{\$nonce}'"),'CSP source contains an unsafe inline-script policy');
$viewIterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/views',FilesystemIterator::SKIP_DOTS));
foreach($viewIterator as$file){if($file->getExtension()!=='php')continue;$source=(string)file_get_contents($file->getPathname());expect(preg_match('/\son(?:click|input|change|submit)=/i',$source)!==1,'Inline event handler remains: '.$file->getFilename());foreach(preg_split('/<script\b/i',$source)as$index=>$part){if($index===0)continue;$tag=strstr($part,'>',true);expect(is_string($tag)&&(str_contains($tag,'src=')||str_contains($tag,'nonce=')),'Inline script lacks CSP nonce: '.$file->getFilename());}}
echo"VazinCMS identity-link contract: OK\n";
