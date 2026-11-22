<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\{Auth,Database,EditorAutosave,EditorNewDraft,Security,SiteContext};
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** JSON protocol on the existing authenticated autosave endpoint. */
final class EditorAutosaveController
{
    public function handle(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);header('Allow: POST');echo '{"ok":false,"error":"method_denied"}';return;
        }
        $user=Auth::requireUser(['owner','admin']);
        try{Security::verifyCsrf();}catch(RuntimeException){
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"error":"invalid_csrf"}';return;
        }
        header('Content-Type: application/json; charset=utf-8');
        try{
            foreach(['action','base_revision','autosave_token','draft_key','title','body','block_document','slug','locale','content_type'] as $key){
                if(isset($_POST[$key])&&!is_string($_POST[$key]))throw new InvalidArgumentException('autosave_payload_invalid');
            }
            if(($_POST['editor_protocol']??'')==='new-page-v1'){
                $drafts=new EditorNewDraft(Database::connection(),SiteContext::id());
                $key=(string)($_POST['draft_key']??'');$actor=(int)$user['id'];
                $result=match((string)($_POST['action']??'')){
                    'load'=>$drafts->load($actor,$key),
                    'save'=>$drafts->save($actor,$key,(string)($_POST['autosave_token']??''),$_POST),
                    default=>throw new InvalidArgumentException('autosave_action_invalid')
                };
                echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
            }
            $id=filter_var($_POST['page_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false||$id===null)throw new InvalidArgumentException('autosave_page_id_required');
            $service=new EditorAutosave(Database::connection(),SiteContext::id());
            $base=(string)($_POST['base_revision']??'');
            $action=(string)($_POST['action']??'');
            $result=match($action){
                'load'=>$service->load((int)$user['id'],$id,$base),
                'save'=>$service->save((int)$user['id'],$id,$base,(string)($_POST['autosave_token']??''),[
                    'title'=>(string)($_POST['title']??''),'body'=>(string)($_POST['body']??''),
                    'block_document'=>(string)($_POST['block_document']??'')
                ]),
                default=>throw new InvalidArgumentException('autosave_action_invalid')
            };
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(Throwable $error){
            $code=$error->getMessage();
            if($error instanceof InvalidArgumentException){$status=422;$public='invalid_document';}
            elseif(in_array($code,['autosave_page_changed','autosave_copy_changed','new_draft_changed','new_draft_already_saved'],true)){$status=409;$public=$code;}
            elseif(in_array($code,['autosave_access_denied','new_draft_access_denied','tenant_access_denied','tenant_content_unavailable'],true)){$status=403;$public='content_unavailable';}
            elseif($code==='new_draft_limit_reached'){$status=429;$public=$code;}
            else{$status=503;$public='autosave_unavailable';}
            http_response_code($status);
            echo json_encode(['ok'=>false,'error'=>$public],JSON_THROW_ON_ERROR);
        }
    }
}
