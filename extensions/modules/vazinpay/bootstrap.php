<?php
declare(strict_types=1);
use VazinCMS\ModuleContext;
use VazinCMS\Controllers\PayController;
return static function(ModuleContext $module):void{
    $module->get('/pay',static fn(array $match)=>(new PayController())->dashboard());
    $module->any('/pay/login',static fn(array $match)=>(new PayController())->login());
    $module->get('/pay/callback',static fn(array $match)=>(new PayController())->callback());
    $module->any('/pay/logout',static fn(array $match)=>(new PayController())->logout());
    $module->get('/pay/transactions',static fn(array $match)=>(new PayController())->transactions());
};
