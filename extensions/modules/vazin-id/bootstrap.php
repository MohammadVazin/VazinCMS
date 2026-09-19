<?php
declare(strict_types=1);

use VazinCMS\ModuleContext;
use VazinCMS\Controllers\VazinIdController;

return static function (ModuleContext $module): void {
    $module->any('/admin/vazin-id', static fn(array $matches) => (new VazinIdController())->index());
};
