<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\Audit;
use VazinCMS\Auth;
use VazinCMS\Database;
use VazinCMS\Security;
use VazinCMS\View;

final class TravelController
{
    private const TYPES=['page','destination','service','article'];
    private const STATUSES=['draft','published','archived'];
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    public function index(): void
    {
        $user=Auth::requireUser(['owner','admin']); $pdo=Database::connection(); $error=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf();
            try{$id=$this->save($pdo,$user);Audit::log('travel.content_created','محتوای سفر ساخته شد',(int)$user['id'],['content_id'=>$id]);header('Location: /admin/travel/'.$id);return;}
            catch(\Throwable $e){$error=$e instanceof \InvalidArgumentException?$e->getMessage():'محتوا ذخیره نشد.';}
        }
        $type=(string)($_GET['type']??'');$status=(string)($_GET['status']??'');$locale=(string)($_GET['locale']??'');$where=[];$params=[];
        if(in_array($type,self::TYPES,true)){$where[]='content_type=:type';$params['type']=$type;}if(in_array($status,self::STATUSES,true)){$where[]='status=:status';$params['status']=$status;}if(in_array($locale,self::LOCALES,true)){$where[]='locale=:locale';$params['locale']=$locale;}
        $sql='SELECT * FROM travel_contents'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY updated_at DESC,id DESC LIMIT 300';$s=$pdo->prepare($sql);$s->execute($params);$contents=$s->fetchAll();
        $stats=['all'=>(int)$pdo->query('SELECT COUNT(*) FROM travel_contents')->fetchColumn(),'published'=>(int)$pdo->query("SELECT COUNT(*) FROM travel_contents WHERE status='published'")->fetchColumn(),'destinations'=>(int)$pdo->query("SELECT COUNT(*) FROM travel_contents WHERE content_type='destination'")->fetchColumn(),'languages'=>(int)$pdo->query('SELECT COUNT(DISTINCT locale) FROM travel_contents')->fetchColumn()];
        View::render('travel',compact('user','contents','stats','error','type','status','locale'));
    }

    public function edit(int $id): void
    {
        $user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;$s=$pdo->prepare('SELECT * FROM travel_contents WHERE id=:id');$s->execute(['id'=>$id]);$content=$s->fetch();if(!$content){http_response_code(404);View::render('error',['title'=>'محتوا پیدا نشد','message'=>'این رکورد وجود ندارد.']);return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();try{$this->save($pdo,$user,$id);Audit::log('travel.content_updated','محتوای سفر به‌روزرسانی شد',(int)$user['id'],['content_id'=>$id]);header('Location: /admin/travel/'.$id.'?saved=1');return;}catch(\Throwable $e){$error=$e instanceof \InvalidArgumentException?$e->getMessage():'تغییرات ذخیره نشد.';}}
        $s->execute(['id'=>$id]);$content=$s->fetch();$saved=isset($_GET['saved']);View::render('travel-edit',compact('user','content','error','saved'));
    }

    private function save(\PDO $pdo,array $user,int $id=0): int
    {
        $title=trim((string)($_POST['title']??''));$slug=strtolower(trim((string)($_POST['slug']??'')));$type=(string)($_POST['content_type']??'page');$status=(string)($_POST['status']??'draft');$locale=(string)($_POST['locale']??'fa');
        if(mb_strlen($title)<3||!preg_match('/^[a-z0-9][a-z0-9-]{1,188}$/',$slug)||!in_array($type,self::TYPES,true)||!in_array($status,self::STATUSES,true)||!in_array($locale,self::LOCALES,true))throw new \InvalidArgumentException('عنوان، اسلاگ، نوع، زبان یا وضعیت معتبر نیست.');
        $data=['type'=>$type,'slug'=>$slug,'status'=>$status,'locale'=>$locale,'title'=>$title,'excerpt'=>trim((string)($_POST['excerpt']??'')),'body'=>trim((string)($_POST['body']??'')),'meta_title'=>trim((string)($_POST['meta_title']??'')),'meta_description'=>trim((string)($_POST['meta_description']??'')),'image'=>trim((string)($_POST['featured_image']??'')),'author'=>(int)$user['id']];
        if($data['image']!==''&&!filter_var($data['image'],FILTER_VALIDATE_URL))throw new \InvalidArgumentException('آدرس تصویر شاخص معتبر نیست.');
        if($id){$data['id']=$id;$pdo->prepare("UPDATE travel_contents SET content_type=:type,slug=:slug,status=:status,locale=:locale,title=:title,excerpt=:excerpt,body=:body,meta_title=:meta_title,meta_description=:meta_description,featured_image=:image,author_id=:author,published_at=CASE WHEN :status='published' THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END,updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute($data);return$id;}
        $pdo->prepare("INSERT INTO travel_contents(content_type,slug,status,locale,title,excerpt,body,meta_title,meta_description,featured_image,author_id,published_at) VALUES(:type,:slug,:status,:locale,:title,:excerpt,:body,:meta_title,:meta_description,:image,:author,CASE WHEN :status='published' THEN CURRENT_TIMESTAMP ELSE NULL END)")->execute($data);return(int)$pdo->lastInsertId();
    }
}
