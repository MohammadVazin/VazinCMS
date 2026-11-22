<?php
declare(strict_types=1);
/**
 * Candidate-only regression: the actual active bundled default theme
 * overrides the core public head, so both must load the columns CSS.
 * Otherwise a browser can publish valid columns but render a single
 * column on desktop despite successful editor/component tests.
 */
$root=dirname(__DIR__);
$check=static function(bool $value,string $label):void{
    if(!$value)throw new RuntimeException('BUNDLED_THEME_COLUMNS_QA_FAILED:'.$label);
};
$core=file_get_contents($root.'/views/public/head.php');
$bundled=file_get_contents($root.'/extensions/themes/vazin-default/views/head.php');
$css=file_get_contents($root.'/public/assets/content-columns-121.css');
$manifest=json_decode((string)file_get_contents(
    $root.'/extensions/themes/vazin-default/vazin-extension.json'
),true,16,JSON_THROW_ON_ERROR);
$check(is_string($core)&&is_string($bundled)&&is_string($css),
    'unreadable_static_assets');
$check(($manifest['type']??null)==='theme','bundled_theme_manifest_missing');
$check(str_contains($core,'/assets/content-columns-121.css'),
    'core_public_head_does_not_load_columns');
$check(str_contains($bundled,'/assets/content-columns-121.css'),
    'active_bundled_theme_does_not_load_columns');
$check(str_contains($css,'grid-template-columns:repeat(2,minmax(0,1fr))'),
    'desktop_columns_css_missing');
$check(str_contains($css,'@media(max-width:680px)') &&
    str_contains($css,'grid-template-columns:minmax(0,1fr)'),
    'mobile_stacking_css_missing');
echo "BUNDLED_THEME_COLUMNS_ASSET=PASS core_head,bundled_default_head,desktop_two_columns,mobile_one_column\n";
