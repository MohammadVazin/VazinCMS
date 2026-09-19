<?php
declare(strict_types=1);
use VazinCMS\{AppointmentService,ModuleContext,TelegramAssistantMaintenance,TelegramAssistantRepository,TelegramAssistantWorker,TelegramSyncService,TravelAlertWorker};
use VazinCMS\Controllers\{TelegramAlertController,TelegramAssistantController,TelegramController};
return static function(ModuleContext $module):void{
 $module->any('/admin/telegram',static fn(array $matches)=>(new TelegramController())->index());
 $module->any('/admin/travel-alerts',static fn(array $matches)=>(new TelegramAlertController())->index());
 $module->any('/admin/telegram/assistant',static fn(array $matches)=>(new TelegramAssistantController())->admin());
 $module->regex(['GET','HEAD'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/channel$#',static fn(array $matches)=>(new TelegramController())->publicChannel($matches[1]));
 $module->regex(['POST'],'#^/telegram/webhook/([a-f0-9]{32})$#',static fn(array $matches)=>(new TelegramController())->webhook($matches[1]));
 $module->regex(['GET','HEAD'],'#^/telegram/assistant-preview/([a-f0-9]{32})$#',static fn(array $matches)=>(new TelegramAssistantController())->preview($matches[1]));
 $module->regex(['GET','HEAD'],'#^/telegram/assistant/([a-f0-9]{32})$#',static fn(array $matches)=>(new TelegramAssistantController())->customerApp($matches[1]));
 $module->regex(['GET','POST'],'#^/telegram/assistant/([a-f0-9]{32})/api/([A-Za-z0-9_/-]{1,190})$#',static fn(array $matches)=>(new TelegramAssistantController())->api($matches[1],$matches[2]));
 $module->regex(['GET','HEAD'],'#^/telegram/webapp/([a-f0-9]{32})$#',static fn(array $matches)=>(new TelegramController())->webApp($matches[1]));
 $module->regex(['POST'],'#^/telegram/webapp/([a-f0-9]{32})/session$#',static fn(array $matches)=>(new TelegramController())->webAppSession($matches[1]));
 $module->on('content.saved',static function(array $payload):void{$pageId=(int)($payload['page_id']??0);if($pageId>0)TelegramSyncService::queuePage($pageId);});
 $module->on('scheduler.tick',static function(array $payload):void{
  TelegramSyncService::processOutbox(20);
  TravelAlertWorker::process(20);
  TelegramAssistantWorker::process(20,null,40.0);
  (new AppointmentService(new TelegramAssistantRepository()))->expireHolds(200);
  TelegramAssistantMaintenance::run(1000);
 });
};
