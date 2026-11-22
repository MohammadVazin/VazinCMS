<?php
declare(strict_types=1);
/**
 * Phase16 native Vazin UI policy + RTL/font/accessibility source contract.
 * Browser-based 6-viewport acceptance is a separate QA receipt.
 */
$root=dirname(__DIR__);
$policy=json_decode(file_get_contents($root.'/.vazin-ui.json'),true,
    512,JSON_THROW_ON_ERROR);
$must=static function(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('VZ_UI_A11Y_FAIL: '.$label);
};
$must(($policy['schema']??'')==='vazin-ui-contract/v1',
      'official contract schema');
$must(($policy['locale']??'')==='fa' &&
      ($policy['direction']??'')==='rtl','RTL locale contract');
$must(($policy['font']['family']??'')==='Vazirmatn' &&
      ($policy['font']['token']??'')==='--v-ui-font',
      'font and style token mismatch');
$font=$root.'/'.ltrim((string)$policy['font']['asset'],'/');
$must(is_file($font),'Vazirmatn asset missing');
$must(hash_equals((string)$policy['font']['sha256'],
      (string)hash_file('sha256',$font)),'brand font sha drift');
$must(is_file($root.'/'.ltrim((string)$policy['font']['license'],'/')),
      'brand font license missing');
$must(($policy['viewports']??[])===['390x844','1440x1000'],
      'viewports not from official contract');
$must(($policy['exceptions']??null)===[],'unapproved UI exceptions');
$must(count($policy['entrypoints']??[])===3,'QA page inventory changed');

foreach($policy['entrypoints'] as $path){
    $filename=$root.'/'.ltrim($path,'/');
    $must(is_file($filename),'QA page missing '.$path);
    $text=file_get_contents($filename);
    $must(preg_match('/<html[^>]*lang="fa"[^>]*dir="rtl"/i',$text)===1,
          'missing document RTL '.$path);
    $must(str_contains($text,'name="viewport"') &&
          str_contains($text,'content="noindex,nofollow"'),
          'mobile viewport/noindex missing '.$path);
    $must(preg_match_all('/<h1\b/i',$text)===1,
          'page heading not unique '.$path);
    $must(str_contains($text,'Vazirmatn.woff2'),
          'font not preloaded '.$path);
    $must(str_contains($text,'<main'),'landmark main missing '.$path);
}
$focus=false;
$responsive=false;
foreach($policy['stylesheets'] as $path){
    $file=$root.'/'.ltrim($path,'/');
    $must(is_file($file),'referenced CSS missing '.$path);
    $css=file_get_contents($file);
    if(str_contains($css,':focus-visible'))$focus=true;
    if(str_contains($css,'@media'))$responsive=true;
}
$must($focus,'keyboard focus style missing');
$must($responsive,'responsive media rules missing');
echo "VZ_UI_ACCESSIBILITY_CONTRACT=PASS official_v1_font_sha,rtl,mobile,3_pages,focus,media_queries\n";
