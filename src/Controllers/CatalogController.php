<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\{Audit,Auth,Database,Security,View};

final class CatalogController
{
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    public function visa():void
    {
        $user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;$rules=[];
        try {
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                Security::verifyCsrf();$action=(string)($_POST['action']??'save_rule');
                if($action==='delete_rule')$pdo->prepare('DELETE FROM visa_rules WHERE id=:id')->execute(['id'=>(int)($_POST['id']??0)]);
                elseif(in_array($action,['delete_product','save_product'],true))throw new \InvalidArgumentException('کاتالوگ فروش مستقیم در این tenant غیرفعال است؛ مسیر عمومی فقط پروندهٔ دستی eVisa می‌سازد.');
                else $this->saveRule($pdo);
                Audit::log('visa.catalog_changed','اطلاعات و قوانین پروندهٔ ویزا تغییر کرد',(int)$user['id']);
                header('Location: /admin/visa-catalog?saved=1');return;
            }
            $rules=$pdo->query('SELECT * FROM visa_rules ORDER BY locale,nationality_code,destination_code,visa_type')->fetchAll();
        } catch(\Throwable $e){$error=$e instanceof \InvalidArgumentException?$e->getMessage():'ابتدا افزونه ویزا نسخه ۴ را نصب کنید.';}
        View::render('visa-catalog',compact('user','rules','error'));
    }

    private function saveRule(\PDO $pdo):void
    {
        $d=['n'=>strtoupper(substr((string)($_POST['nationality_code']??''),0,2)),'to'=>strtoupper(substr((string)($_POST['destination_code']??''),0,2)),'locale'=>(string)($_POST['locale']??'fa'),'type'=>trim((string)($_POST['visa_type']??'')),'req'=>trim((string)($_POST['requirements']??'')),'docs'=>trim((string)($_POST['documents']??'')),'time'=>trim((string)($_POST['processing_time']??'')),'notes'=>trim((string)($_POST['notes']??''))];
        if(!preg_match('/^[A-Z]{2}$/',$d['n'])||!preg_match('/^[A-Z]{2}$/',$d['to'])||$d['type']==='')throw new \InvalidArgumentException('ملیت، مقصد و نوع ویزا الزامی است.');
        $sql='INSERT INTO visa_rules(nationality_code,destination_code,locale,visa_type,requirements,documents,processing_time,notes) VALUES(:n,:to,:locale,:type,:req,:docs,:time,:notes) ON CONFLICT(nationality_code,destination_code,locale,visa_type) DO UPDATE SET requirements=:req2,documents=:docs2,processing_time=:time2,notes=:notes2,updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute($d+['req2'=>$d['req'],'docs2'=>$d['docs'],'time2'=>$d['time'],'notes2'=>$d['notes']]);
    }

    public function travel():void
    {
        $user=Auth::requireUser(['owner','admin']);$pdo=Database::connection();$error=null;
        try{if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){Security::verifyCsrf();if(($_POST['action']??'save')==='delete'){$pdo->prepare('DELETE FROM travel_destinations WHERE id=:id')->execute(['id'=>(int)($_POST['id']??0)]);}else{$d=['audience'=>strtoupper(substr((string)($_POST['audience_code']??''),0,2)),'locale'=>(string)($_POST['locale']??'fa'),'country'=>trim((string)($_POST['country']??'')),'city'=>trim((string)($_POST['city']??'')),'summary'=>trim((string)($_POST['summary']??'')),'entry'=>trim((string)($_POST['entry_notes']??'')),'budget'=>trim((string)($_POST['budget_notes']??'')),'tip'=>trim((string)($_POST['local_tip']??'')),'priority'=>(int)($_POST['priority']??100)];if(!preg_match('/^[A-Z]{2}$/',$d['audience'])||$d['country']===''||$d['city']==='')throw new \InvalidArgumentException('مخاطب، کشور و شهر الزامی است.');$pdo->prepare('INSERT INTO travel_destinations(audience_code,locale,country,city,summary,entry_notes,budget_notes,local_tip,priority,is_enabled) VALUES(:audience,:locale,:country,:city,:summary,:entry,:budget,:tip,:priority,1) ON CONFLICT(audience_code,locale,country,city) DO UPDATE SET summary=:summary2,entry_notes=:entry2,budget_notes=:budget2,local_tip=:tip2,priority=:priority2,is_enabled=1')->execute($d+['summary2'=>$d['summary'],'entry2'=>$d['entry'],'budget2'=>$d['budget'],'tip2'=>$d['tip'],'priority2'=>$d['priority']]);}Audit::log('travel.catalog_changed','راهنمای مقصد تغییر کرد',(int)$user['id']);header('Location: /admin/travel-catalog?saved=1');return;}$items=$pdo->query('SELECT * FROM travel_destinations ORDER BY locale,audience_code,priority,city')->fetchAll();}catch(\Throwable $e){$items=[];$error=$e instanceof \InvalidArgumentException?$e->getMessage():'ابتدا افزونه سفر نسخه ۳ را نصب کنید.';}View::render('travel-catalog',compact('user','items','error'));
    }
}
