<?php
declare(strict_types=1);

use VazinCMS\ModuleContext;
use VazinCMS\Controllers\ContentMigrationController;

return static function (ModuleContext $module): void {
    $module->any('/admin/content-migration', static fn (array $matches) => (new ContentMigrationController())->index());
};
