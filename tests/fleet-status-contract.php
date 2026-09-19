<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use VazinCMS\FleetStatusService;use VazinCMS\Version;
$version=Version::current();$good=static fn(string $host):string=>json_encode(['ok'=>true,'service'=>'VazinCMS','version'=>$version],JSON_THROW_ON_ERROR);$summary=FleetStatusService::summary($good);
if(count($summary['sites'])!==6||array_filter($summary['sites'],static fn(array $site):bool=>$site['status']!=='healthy'))throw new RuntimeException('Healthy fleet was not accepted.');
$bad=FleetStatusService::summary(static fn(string $host):string=>json_encode(['ok'=>true,'service'=>'VazinCMS','version'=>'0.0.0'],JSON_THROW_ON_ERROR));if(!array_filter($bad['sites'],static fn(array $site):bool=>$site['status']==='attention'))throw new RuntimeException('Outdated fleet was accepted.');echo "Fleet status contract: OK\n";
