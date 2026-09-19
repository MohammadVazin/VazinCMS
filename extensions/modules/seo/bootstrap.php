<?php
declare(strict_types=1);
use VazinCMS\{ContentAdvisoryService,EditorialPlanner,ModuleContext};
use VazinCMS\Controllers\SeoController;
return static function(ModuleContext $module):void{
 $module->any('/admin/seo',static fn(array $matches)=>(new SeoController())->index());
 $module->get('/sitemap.xml',static fn(array $matches)=>(new SeoController())->sitemap());
 $module->get('/robots.txt',static fn(array $matches)=>(new SeoController())->robots());
 $analyze=static function(array $payload):void{$pageId=(int)($payload['page_id']??0);if($pageId>0)ContentAdvisoryService::analyzePage($pageId);};
 $module->on('content.saved',$analyze);$module->on('content.imported',$analyze);
 $module->on('scheduler.tick',static fn(array $payload)=>EditorialPlanner::refresh());
};
