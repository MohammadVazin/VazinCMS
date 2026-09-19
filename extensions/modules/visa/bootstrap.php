<?php
declare(strict_types=1);
use VazinCMS\ModuleContext;
use VazinCMS\Controllers\{CatalogController,ContentController,ManualVisaCaseController,TravelOrderController,VisaApplicationController,VisaCaseController};
return static function(ModuleContext $module):void{
    $module->any('/admin/visa-orders',static fn(array $match)=>(new VisaCaseController())->index());
    $module->regex(['GET','POST'],'#^/admin/visa-orders/(\d+)$#',static fn(array $match)=>(new VisaCaseController())->show((int)$match[1]));
    $module->any('/admin/visa-catalog',static fn(array $match)=>(new CatalogController())->visa());
    $module->regex(['GET','POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/visa(?:/intake)?$#',static fn(array $match)=>(new ManualVisaCaseController())->intake($match[1]));
    $module->regex(['GET'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/visa-selector$#',static fn(array $match)=>(new ContentController())->visaSelector($match[1]));
    $module->regex(['GET','POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/order/([A-Z0-9]{10,32})/application$#',static fn(array $match)=>(new VisaApplicationController())->form($match[1],$match[2]));
    $module->regex(['POST'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/order/([A-Z0-9]{10,32})/documents$#',static fn(array $match)=>(new VisaCaseController())->upload($match[1],$match[2]));
    $module->regex(['GET'],'#^/(fa|ar|en|ru|tr|hy|kk|tg|zh)/order/([A-Z0-9]{10,32})$#',static fn(array $match)=>(new TravelOrderController())->order($match[1],$match[2]));
    $module->regex(['GET'],'#^/visa-document/(\d+)/download$#',static fn(array $match)=>(new VisaCaseController())->download((int)$match[1]));
    $module->any('/payment/vazinpay/callback',static fn(array $match)=>(new TravelOrderController())->paymentCallback());
};
