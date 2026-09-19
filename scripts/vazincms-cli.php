<?php
declare(strict_types=1);
chdir(dirname(__DIR__));require 'src/bootstrap.php';
use VazinCMS\{ConnectorRegistry,Database,Version};
$cmd=$argv[1]??'help';
if($cmd==='health'){echo json_encode(['ok'=>true,'version'=>Version::current(),'database'=>(string)Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME)]).PHP_EOL;exit(0);}
if($cmd==='migrations'){foreach(Database::connection()->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN) as$v)echo $v.PHP_EOL;exit(0);}
if($cmd==='connectors'){foreach(ConnectorRegistry::all(Database::connection()) as$c)echo $c['connector_key']."\t".$c['status'].PHP_EOL;exit(0);}
if($cmd==='forms'){foreach(Database::connection()->query('SELECT form_key,status FROM cms_forms ORDER BY form_key')->fetchAll() as$f)echo $f['form_key']."\t".$f['status'].PHP_EOL;exit(0);}
if($cmd==='content'){foreach(Database::connection()->query("SELECT id,locale,content_type,status,title FROM cms_pages WHERE trash_status='active' ORDER BY id DESC LIMIT 100")->fetchAll() as$p)echo implode("\t",[$p['id'],$p['locale'],$p['content_type'],$p['status'],$p['title']]).PHP_EOL;exit(0);}
fwrite(STDERR,"Usage: vazincms <health|migrations|connectors|forms|content>\n");exit($cmd==='help'?0:2);
