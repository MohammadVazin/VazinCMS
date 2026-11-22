<?php
declare(strict_types=1);
/** Candidate-only, no live DB, request or production page. */
$root=dirname(__DIR__);
require $root.'/src/BlockEditor.php';
require $root.'/src/Security.php';

use VazinCMS\BlockEditor;
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('COLUMNS_PRODUCT_QA_FAIL: '.$why);
};
$doc=['schema'=>1,'blocks'=>[
    ['type'=>'heading','data'=>['text'=>'سرویس‌های ما','level'=>4]],
    ['type'=>'columns','data'=>['columns'=>[
        ['blocks'=>[
            ['type'=>'paragraph','data'=>['text'=>'ستون راست فارسی <script>alert(1)</script>']],
            ['type'=>'heading','data'=>['text'=>'عنوان راست','level'=>3]],
            ['type'=>'image','data'=>['src'=>'javascript:alert(1)','alt'=>'حفظ تصویر']]
        ]],
        ['blocks'=>[
            ['type'=>'paragraph','data'=>['text'=>'ستون چپ فارسی']]
        ]]
    ]]]
]];
$encoded=BlockEditor::encode($doc);
$decoded=BlockEditor::decode($encoded);
$assert($decoded===$doc,'block_document_roundtrip_lost_nested_data');
$rendered=BlockEditor::render($decoded);
$assert(substr_count($rendered,'class="cms-column"')===2,'two_columns_not_rendered');
$assert(str_contains($rendered,'<h3>عنوان راست</h3>'),'nested_heading_not_rendered');
$assert(str_contains($rendered,'ستون چپ فارسی'),'nested_paragraph_not_rendered');
$assert(str_contains($rendered,'&lt;script&gt;'),'paragraph_html_not_escaped');
$assert(!str_contains($rendered,'<script>')&&!str_contains($rendered,'javascript:'),
    'unsafe_html_or_url_rendered');

$view=$root.'/views/public/cms-page.php';
$siteName='QA Site';
$locale='fa';
$_SERVER['HTTP_HOST']='qa.invalid';
$renderPage=static function(array $data)use($view,$siteName,$locale):string{
    $page=$data;
    ob_start();
    try{include $view;return (string)ob_get_clean();}
    catch(Throwable $error){ob_end_clean();throw $error;}
};
$base=[
    'title'=>'آزمون دو ستون','slug'=>'qa-columns','content_type'=>'page',
    'published_at'=>null,'created_at'=>'2026-11-07 00:00:00',
    'body'=>'متن قدیمی','block_document'=>$encoded
];
$html=$renderPage($base);
$assert(str_contains($html,'class="cms-columns"'),'public_page_still_ignores_columns');
$assert(substr_count($html,'class="cms-column"')===2,'public_layout_not_two_columns');
$assert(str_contains($html,'ستون چپ فارسی')&&str_contains($html,'ستون راست فارسی'),
    'published_columns_missing_text');
$assert(!str_contains($html,'<script>alert(1)</script>'),'published_page_xss');
$assert(!str_contains($html,'متن قدیمی'),'valid_columns_were_replaced_by_legacy_body');

$legacy=$renderPage($base+[]);
$legacyPage=$base;
$legacyPage['block_document']=BlockEditor::encode(
    ['schema'=>1,'blocks'=>[['type'=>'paragraph','data'=>['text'=>'درست']]]]
);
$legacyPage['body']="متن معمولی\nخط دوم <script>unsafe</script>";
$legacy=$renderPage($legacyPage);
$assert(str_contains($legacy,'متن معمولی<br'),'legacy_line_break_changed');
$assert(str_contains($legacy,'&lt;script&gt;'),'legacy_body_not_escaped');
$assert(!str_contains($legacy,'class="cms-columns"'),'unrelated_page_switched_to_columns');

$broken=$base;$broken['block_document']='{corrupt';$broken['body']='Fallback safe <img>';
$brokenHtml=$renderPage($broken);
$assert(str_contains($brokenHtml,'Fallback safe &lt;img&gt;'),'invalid_document_no_legacy_fallback');
$assert(!str_contains($brokenHtml,'class="cms-columns"'),'invalid_json_rendered_as_columns');

$assert(str_contains(file_get_contents($root.'/views/pages.php'),'block-editor-121.js'),
    'admin_not_using_new_editor');
$assert(str_contains(file_get_contents($root.'/views/public/head.php'),'content-columns-121.css'),
    'public_stylesheet_not_loaded');

echo "EDITOR_COLUMNS_PRODUCT_QA=PASS PHP_roundtrip,nested_columns,public_two_column_render,escaped_html,legacy_fallback,invalid_json_fallback\n";
