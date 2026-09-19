<?php
declare(strict_types=1);
use VazinCMS\ModuleContext;
use VazinCMS\Controllers\PublishingController;
return static function(ModuleContext $module):void{
    $module->any('/admin/publishing',static fn(array $match)=>(new PublishingController())->index());
    $module->any('/admin/social-destinations',static fn(array $match)=>(new PublishingController())->destinations());
};
