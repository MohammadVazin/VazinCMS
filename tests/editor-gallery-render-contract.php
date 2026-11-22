<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/BlockEditor.php';
require dirname(__DIR__).'/src/Security.php';
use VazinCMS\BlockEditor;
$count=0;
$check=static function(bool $ok,string $why)use(&$count):void{if(!$ok)throw new RuntimeException('GALLERY_RENDER_FAILED:'.$why);$count++;echo 'PASS '.$why.PHP_EOL;};
$doc=['schema'=>1,'blocks'=>[['type'=>'gallery','data'=>['columns'=>2,'images'=>[
 ['src'=>'/uploads/one.png','alt'=>'alt <script>no</script>','caption'=>'caption <img src=x onerror=alert(1)>','media_id'=>10,'custom'=>['keep'=>true]],
 ['src'=>'/uploads/two.png','alt'=>'دوم','caption'=>'زیرنویس فارسی','media_id'=>11]
]]]]];
$json=BlockEditor::encode($doc);$read=BlockEditor::decode($json);$html=BlockEditor::render($read);
$check($read===$doc,'gallery_exact_roundtrip_preserves_unknown_metadata');
$check(str_contains($html,'cms-gallery-columns-2'),'gallery_desktop_column_class');
$check(substr_count($html,'<figure>')===2,'two_gallery_figures');
$check(substr_count($html,'<figcaption>')===2,'captions_rendered');
$check(str_contains($html,'caption &lt;img')&&!str_contains($html,'<script>'),'gallery_caption_and_alt_escaped');
$check(strpos($html,'one.png')<strpos($html,'two.png'),'gallery_order_preserved');
$bad=$doc;$bad['blocks'][0]['data']['images'][0]['src']='javascript:alert(1)';
$check(!str_contains(BlockEditor::render($bad),'javascript:'),'javascript_url_rejected');
$bad['blocks'][0]['data']['images'][0]['src']='//outside.invalid/x.png';
$check(!str_contains(BlockEditor::render($bad),'outside.invalid'),'scheme_relative_url_rejected');
foreach([['columns',9],['images',array_fill(0,101,['src'=>'/uploads/x.png'])],['images',['bad']],['images',[['src'=>'/uploads/x.png','caption'=>str_repeat('x',501)]]]] as [$key,$value]){
 $bad=$doc;$bad['blocks'][0]['data'][$key]=$value;$denied=false;
 try{BlockEditor::encode($bad);}catch(Throwable){$denied=true;}
 $check($denied,'invalid_gallery_'.$key.'_'.$count.'_rejected');
}
$root=dirname(__DIR__);$siteName='QA';$locale='fa';$_SERVER['HTTP_HOST']='qa.invalid';
$page=['title'=>'Gallery only','slug'=>'gallery-only','content_type'=>'page','body'=>'fallback should not replace gallery','block_document'=>$json,'published_at'=>null,'created_at'=>'2026-11-13'];
ob_start();include $root.'/views/public/cms-page.php';$public=(string)ob_get_clean();
$check(str_contains($public,'cms-gallery-columns-2')&&str_contains($public,'/uploads/one.png'),'gallery_only_page_uses_structured_renderer');
$check(!str_contains($public,'fallback should not replace gallery'),'gallery_only_not_silently_reduced_to_legacy_body');
$css=file_get_contents($root.'/public/assets/content-columns-121.css');
$check(str_contains($css,'.cms-gallery-columns-2')&&str_contains($css,'.cms-gallery-columns-4')&&str_contains($css,'.content-body .cms-gallery{grid-template-columns:minmax(0,1fr)}'),'gallery_responsive_rules_present');
echo 'EDITOR_GALLERY_RENDER=PASS checks='.$count.' standalone_public_view,escaped_caption,bounds,block_metadata'.PHP_EOL;
