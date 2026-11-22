<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $path):string{
    $value=file_get_contents($path);
    if(!is_string($value))throw new RuntimeException('TEMPLATE_PUBLIC_CONTRACT_UNREADABLE:'.$path);
    return $value;
};
$assert=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('TEMPLATE_PUBLIC_CONTRACT_FAIL:'.$label);
    echo 'PASS '.$label.PHP_EOL;
};
foreach([
    'views/public/head.php',
    'extensions/themes/vazin-default/views/head.php'
] as $file){
    $source=$read($root.'/'.$file);
    $assert(str_contains($source,'SiteTemplateBuilder::renderHeader'),
        basename(dirname($file)).'_head_renders_site_owned_template');
    $assert(str_contains($source,'template-builder-127.css'),
        basename(dirname($file)).'_head_loads_builder_css');
}
foreach([
    'views/public/foot.php',
    'extensions/themes/vazin-default/views/foot.php'
] as $file){
    $source=$read($root.'/'.$file);
    $assert(str_contains($source,'SiteTemplateBuilder::renderFooter'),
        basename(dirname($file)).'_foot_renders_site_owned_template');
}
$view=$read($root.'/src/View.php');
$assert(str_contains($view,'new SiteTemplateBuilder(Database::connection(),SiteContext::id())'),
    'public_view_loads_site_scoped_template_state');
$app=$read($root.'/src/App.php');
$assert(str_contains($app,"/admin/template-builder")&&str_contains($app,'SiteTemplateBuilderController'),
    'admin_route_dispatches_template_builder');
$sidebar=$read($root.'/views/partials/admin-sidebar.php');
$assert(str_contains($sidebar,"['/admin/template-builder', 'قالب‌ساز'"),
    'owner_sidebar_exposes_template_builder');
$gate=$read($root.'/src/SiteScopedAdminSurfaceGate.php');
$assert(!str_contains($gate,"'/admin/template-builder'=>true"),
    'new_route_not_added_to_unapproved_multisite_allowlist');
$assert(is_file($root.'/public/assets/template-builder-127.js')&&
    is_file($root.'/public/assets/template-builder-127.css'),
    'builder_assets_exist');
echo "SITE_TEMPLATE_PUBLIC_INTEGRATION=PASS core_and_bundled_shells,site_state,route,assets,multisite_closed".PHP_EOL;
