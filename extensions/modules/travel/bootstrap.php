<?php
declare(strict_types=1);
use VazinCMS\ModuleContext;
use VazinCMS\Controllers\{CatalogController,ContentController,ManualVisaCaseController,PublicTravelController,TravelController,TravelOrderController,TravelPlatformController,TravelVisaAdminController};
return static function(ModuleContext $module):void{
    $module->any('/admin/travel',static fn(array $match)=>(new TravelController())->index());
    $module->get('/admin/travel-visa-orders',static fn(array $match)=>(new TravelVisaAdminController())->index());
    $module->get('/api/v3/travel-visa/orders',static fn(array $match)=>(new TravelVisaAdminController())->api());
    $module->regex(['GET','POST'],'#^/admin/travel/(\d+)$#',static fn(array $match)=>(new TravelController())->edit((int)$match[1]));
    $module->any('/admin/travel-catalog',static fn(array $match)=>(new CatalogController())->travel());
    $module->any('/admin/travel-connectors',static fn(array $match)=>(new TravelPlatformController())->connectors());
    $module->any('/admin/agency-inquiries',static fn(array $match)=>(new TravelPlatformController())->inquiries());
    $module->regex(['GET','POST'],'#^/admin/agency-inquiries/(\d+)$#',static fn(array $match)=>(new TravelPlatformController())->inquiry((int)$match[1]));
    $module->get('/robots.txt',static fn(array $match)=>(new PublicTravelController())->robots());
    $module->get('/sitemap.xml',static fn(array $match)=>(new PublicTravelController())->sitemap());
    $module->get('/feed.xml',static fn(array $match)=>(new PublicTravelController())->feed());
    $module->regex(['GET'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/destinations$#',static fn(array $match)=>(new ContentController())->audienceDestinations($match[1]));
    $module->regex(['GET'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/(services|articles)$#',static fn(array $match)=>(new PublicTravelController())->listing($match[1],$match[2]));
    $module->regex(['GET'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/(destination|service|article)/([a-z0-9][a-z0-9-]{1,188})$#',static fn(array $match)=>(new PublicTravelController())->detail($match[1],$match[2],$match[3]));
    $module->regex(['GET','POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/agency$#',static fn(array $match)=>(new TravelPlatformController())->agency($match[1]));
    $module->regex(['GET','POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/request$#',static fn(array $match)=>(new ManualVisaCaseController())->intake($match[1]));
    $module->regex(['GET','POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/account$#',static fn(array $match)=>(new TravelOrderController())->account($match[1]));
};
