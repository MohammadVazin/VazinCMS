<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use VazinCMS\Database;

$host=strtolower((string)(parse_url((string)getenv('APP_URL'),PHP_URL_HOST)?:''));
$profile=str_contains($host,'pay.')?'pay':(str_contains($host,'visa.')?'visa':(str_contains($host,'travel.')||str_contains($host,'trip.')?'travel':'corporate'));
$pdo=Database::connection();
$current=$pdo->query("SELECT setting_value FROM cms_settings WHERE setting_key='site_profile'")->fetchColumn();
if($current===false||$current===''||$current==='corporate'){
    $statement=$pdo->prepare('INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP');
    $statement->execute(['key'=>'site_profile','value'=>$profile,'value2'=>$profile]);
}
// Customer sites are white-label. A Russian domain starts in Russian while
// all supported translations remain available; the customer's site_name is
// kept as the only public brand.
$get=$pdo->prepare("SELECT setting_value FROM cms_settings WHERE setting_key=:key");
$get->execute(['key'=>'default_locale']);$default=(string)($get->fetchColumn()?:'');
$get->execute(['key'=>'enabled_locales']);$enabled=(string)($get->fetchColumn()?:'');
$targetDefault=str_ends_with($host,'.ru')?'ru':($default!==''?$default:'en');
$upsert=$pdo->prepare('INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP');
if($default===''||($targetDefault==='ru'&&$default==='fa'))$upsert->execute(['key'=>'default_locale','value'=>$targetDefault,'value2'=>$targetDefault]);
if($enabled===''||$enabled==='["fa"]'){$locales=json_encode(['ru','en','fa','ar','tr','hy','kk','tg','zh'],JSON_UNESCAPED_UNICODE);$upsert->execute(['key'=>'enabled_locales','value'=>$locales,'value2'=>$locales]);}
echo "Site profile: {$profile}\n";
