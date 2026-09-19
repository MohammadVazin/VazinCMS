<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$publication=VazinCMS\ExtensionManager::isActive('module','publishing')
    ? VazinCMS\PublicationService::processDue()
    : ['status'=>'module_disabled'];
$results=VazinCMS\Scheduler::runDue();
$extensions=VazinCMS\ExtensionRuntime::emit('scheduler.tick',['ran_at'=>gmdate('c')]);
echo json_encode(['ok'=>true,'publication'=>$publication,'processed'=>count($results),'results'=>$results,'extensions'=>$extensions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
