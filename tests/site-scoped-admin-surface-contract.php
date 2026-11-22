<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/SiteScopedAdminSurfaceGate.php';
use VazinCMS\SiteScopedAdminSurfaceGate;

$check=static function(bool $good,string $what):void{
    if(!$good)throw new RuntimeException('SCOPED_ADMIN_SURFACE_FAIL: '.$what);
};
$safe=[
    '/admin','/admin/pages','/admin/pages/autosave',
    '/admin/pages/restore-trash','/admin/menus','/admin/media',
    '/admin/media/picker','/admin/revisions','/admin/settings',
    '/admin/seo','/admin/content-migration','/admin/extensions','/admin/modules'
];
foreach($safe as $path){
    $check(SiteScopedAdminSurfaceGate::allowed($path),'audited route blocked '.$path);
    SiteScopedAdminSurfaceGate::assertPath($path);
}
$blocked=[
    '/admin/finance','/admin/orders','/admin/users','/admin/tickets',
    '/admin/travel-visa-orders','/admin/telegram','/admin/travel-alerts',
    '/admin/seo/custom','/admin/../finance','/admin/media/private',
    '/admin/modules/unknown','/admin/reporting','/admin/plugins/install',
    '/administrator'
];
foreach($blocked as $path){
    $check(!SiteScopedAdminSurfaceGate::allowed($path),'unreviewed route accepted '.$path);
    try{
        SiteScopedAdminSurfaceGate::assertPath($path);
        throw new RuntimeException('unreviewed route accepted '.$path);
    }catch(RuntimeException $e){
        $check($e->getMessage()==='tenant_admin_route_unreviewed',
            'unexpected denial code '.$path);
    }
}
$app=file_get_contents($root.'/src/App.php');
$start=strpos((string)$app,'SiteScopedAdminSurfaceGate::allowed($path)');
$dispatch=strpos((string)$app,'if($this->dispatchCore($path))');
$check($start!==false&&$dispatch!==false&&$start<$dispatch,
    'surface gate not before core dispatch');
$account=file_get_contents($root.'/src/Controllers/AccountController.php');
$closed=strpos((string)$account,"SiteScopedRuntimePolicy::scopedMode(\$pdo)");
$read=strpos((string)$account,'SELECT id,order_number,product_name');
$check($closed!==false&&$read!==false&&$closed<$read,
    'global client order query is not behind site-scope block');
echo "SITE_SCOPED_ADMIN_SURFACE=PASS audited_13,blocked_14,account_global_denied_before_read\n";
