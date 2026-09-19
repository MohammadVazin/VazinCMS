<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\{ApiAuth,ConnectorRegistry,Database,Version};

final class DeveloperApiController
{
    public function content(): void {$this->run('content',function(){[$limit,$offset]=$this->page();$pdo=Database::connection();$s=$pdo->prepare("SELECT id,slug,locale,title,status,content_type,published_at,updated_at FROM cms_pages WHERE trash_status='active' ORDER BY id DESC LIMIT $limit OFFSET $offset");$s->execute();return['items'=>$s->fetchAll(),'pagination'=>['limit'=>$limit,'offset'=>$offset]];});}
    public function forms(): void {$this->run('forms',function(){[$limit,$offset]=$this->page();$pdo=Database::connection();$s=$pdo->prepare("SELECT id,form_key,label,status,created_at,updated_at FROM cms_forms ORDER BY id DESC LIMIT $limit OFFSET $offset");$s->execute();return['items'=>$s->fetchAll(),'pagination'=>['limit'=>$limit,'offset'=>$offset]];});}
    public function courses(): void {$this->run('learn',function(){[$limit,$offset]=$this->page();$pdo=Database::connection();$s=$pdo->prepare("SELECT id,course_key,title,description,locale,status,updated_at FROM learn_courses ORDER BY id DESC LIMIT $limit OFFSET $offset");$s->execute();return['items'=>$s->fetchAll(),'pagination'=>['limit'=>$limit,'offset'=>$offset]];});}
    public function connectors(): void {$this->run('connectors',fn()=>['items'=>ConnectorRegistry::all(Database::connection())]);}
    private function run(string$scope,callable$handler): never
    {
        try{ApiAuth::authorize($scope);ApiAuth::respond(['ok'=>true,'api_version'=>'3.0','data'=>$handler(),'meta'=>['service'=>'VazinCMS','version'=>Version::current()]],200);}catch(\Throwable $e){error_log('[VazinCMS Developer API] '.$e);ApiAuth::respond(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Internal API error']],500);}
    }
    private function page(): array
    {
        $limit=max(1,min(200,(int)($_GET['limit']??50)));$offset=max(0,(int)($_GET['offset']??0));return[$limit,$offset];
    }
}
