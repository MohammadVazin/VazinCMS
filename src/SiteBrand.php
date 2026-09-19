<?php
declare(strict_types=1);
namespace VazinCMS;
final class SiteBrand{public static function name():string{try{$s=Database::connection()->prepare("SELECT setting_value FROM cms_settings WHERE setting_key='site_name'");$s->execute();$name=trim((string)$s->fetchColumn());if($name!=='')return$name;}catch(\Throwable){}return(string)(parse_url((string)getenv('APP_URL'),PHP_URL_HOST)?:'Website');}}
