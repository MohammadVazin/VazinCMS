<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/SiteScopedIntegrationSurfaceGate.php';
use VazinCMS\SiteScopedIntegrationSurfaceGate;
$check=static function(bool $ok,string $msg):void{
    if(!$ok)throw new RuntimeException('SCOPED_INTEGRATION_SURFACE_FAIL: '.$msg);
};
foreach(['/api/v3/content','/api/v3/forms'] as $path){
    $check(SiteScopedIntegrationSurfaceGate::isIntegrationPath($path),
           'recognized API route not classified');
    $check(SiteScopedIntegrationSurfaceGate::allowed($path),
           'audited site API route not allowed');
}
$blocked=[
    '/api','/api/openapi.json','/api/v3/openapi.json',
    '/api/v3/learn/courses','/api/v3/connectors',
    '/api/v3/travel-visa/orders','/api/v4/content',
    '/api/v3/content/detail','/api/v3/forms/1',
    '/connector/v1/health','/connector/v1/deployment-info',
    '/connector/v2/status','/connector/private',
    '/webhooks/v1/travel-visa/tenant-qa','/webhooks/v2/unknown'
];
foreach($blocked as $path){
    $check(SiteScopedIntegrationSurfaceGate::isIntegrationPath($path),
           'global route not recognized: '.$path);
    $check(!SiteScopedIntegrationSurfaceGate::allowed($path),
           'global route erroneously allowed: '.$path);
}
foreach(['/','/health','/login','/admin','/docs',
         '/uploads/a.jpg','/module-assets/seo/main.css'] as $path){
    $check(!SiteScopedIntegrationSurfaceGate::isIntegrationPath($path),
           'legitimate nonintegration route misclassified');
}
$source=file_get_contents($root.'/src/App.php');
$gate=strpos((string)$source,'SiteScopedIntegrationSurfaceGate::isIntegrationPath($path)');
$dispatch=strpos((string)$source,'if($this->dispatchCore($path))');
$installed=strpos((string)$source,"RuntimePaths::storage().'/installed.lock'");
$check($installed!==false&&$gate!==false&&$dispatch!==false
        && $installed<$gate && $gate<$dispatch,
       'global route gate not after install check/before dispatch');
echo "SITE_INTEGRATION_SURFACE_CONTRACT=PASS two_site_api_allowlisted,15_global_routes_denied,pre_dispatch\n";
