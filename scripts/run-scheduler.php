<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
// Legacy scheduler has global side effects. Reject scoped DBs BEFORE
// touching the global extension registry or dispatching any worker.
VazinCMS\SiteScopedOperationGate::denyUnscopedWrite(
    VazinCMS\Database::connection(),'legacy_scheduler'
);
$publication=VazinCMS\ExtensionManager::isActive('module','publishing')
    ? VazinCMS\PublicationService::processDue()
    : ['status'=>'module_disabled'];
$results=VazinCMS\Scheduler::runDue();
$updateFeed=VazinCMS\UpdateFeedService::refresh(VazinCMS\Database::connection());
$extensions=VazinCMS\ExtensionRuntime::emit('scheduler.tick',['ran_at'=>gmdate('c')]);
echo json_encode(['ok'=>true,'publication'=>$publication,'update_feed'=>$updateFeed,'processed'=>count($results),'results'=>$results,'extensions'=>$extensions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
