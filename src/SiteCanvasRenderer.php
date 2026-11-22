<?php
declare(strict_types=1);
namespace VazinCMS;

/** Server render of validated trees. Content slots use existing safe block rendering. */
final class SiteCanvasRenderer
{
    private static function e(string $s):string{return Security::e($s);}
    public static function render(array $layout,array $context):string
    {
        $checked=SiteCanvas::validate(['layouts'=>[$layout['key']=>[$layout['kind']=>$layout['nodes']]],'shared'=>$layout['shared']]);
        $nodes=$checked['layouts'][$layout['key']][$layout['kind']];$layout['shared']=$checked['shared'];
        $source=(string)($layout['source']??'template');$pageId=(int)($layout['page_id']??0);
        return '<div class="public-card vz-canvas-public" data-canvas-template="'.self::e($layout['key']).'" data-canvas-kind="'.self::e($layout['kind']).'" data-canvas-source="'.self::e($source).'"'.($pageId>0?' data-canvas-page-id="'.$pageId.'"':'').'>'.self::nodes($nodes,$layout['shared'],$context).'</div>';
    }
    /** Render a canvas-managed public header or footer from the same shared library as page/archive layouts. */
    public static function renderShell(array $layout,string $part,array $context=[]):string
    {
        if(!in_array($part,['header','footer'],true)||($layout['kind']??null)!==$part)throw new \InvalidArgumentException('canvas_shell_invalid');
        $checked=SiteCanvas::validate(['layouts'=>[$layout['key']=>[$part=>$layout['nodes']]],'shared'=>$layout['shared']]);
        $nodes=$checked['layouts'][$layout['key']][$part];
        $tag=$part==='header'?'header':'footer';
        return '<'.$tag.' class="travel-'.$part.' vz-canvas-shell vz-canvas-'.$part.'" data-canvas-template="'.self::e($layout['key']).'" data-template-key="'.self::e($layout['key']).'" data-canvas-kind="'.$part.'">'.self::nodes($nodes,$checked['shared'],$context).'</'.$tag.'>';
    }
    private static function nodes(array $nodes,array $shared,array $ctx):string
    {
        $out='';
        foreach($nodes as $n){
            $id=self::e($n['id']);
            switch($n['type']){
                case 'section':
                    $out.='<section data-canvas-node="'.$id.'" class="vz-canvas-section layout-'.$n['layout'].' tone-'.$n['tone'].' gap-'.$n['gap'].'">'.self::nodes($n['children'],$shared,$ctx).'</section>';break;
                case 'heading':$out.='<h'.$n['level'].' data-canvas-node="'.$id.'">'.self::e($n['text']).'</h'.$n['level'].'>';break;
                case 'text':$out.='<p data-canvas-node="'.$id.'">'.nl2br(self::e($n['text'])).'</p>';break;
                case 'button':$out.='<a data-canvas-node="'.$id.'" class="vz-canvas-button" href="'.self::e(SiteCanvas::href($n['href'])).'">'.self::e($n['text']).'</a>';break;
                case 'shared':$c=$shared[$n['ref']];$out.='<div class="vz-canvas-shared" data-shared-ref="'.self::e($n['ref']).'">'.self::nodes($c['nodes'],[],[]).'</div>';break;
                case 'page_title':$out.='<h1>'.self::e((string)($ctx['page']['title']??'')).'</h1>';break;
                case 'page_content':$out.='<div class="content-body">'.self::body($ctx['page']??[]).'</div>';break;
                case 'archive_title':$out.='<h1>'.self::e((string)($ctx['archiveTitle']??'آرشیو نوشته‌ها')).'</h1>';break;
                case 'archive_list':$out.=self::archive($ctx['archive']??['items'=>[],'page'=>1,'pages'=>1,'locale'=>'fa']);break;
            }
        }
        return $out;
    }
    private static function body(array $page):string
    {
        $json=(string)($page['block_document']??'');
        if(trim($json)!==''){
            try{return BlockEditor::render(BlockEditor::decode($json));}catch(\Throwable){}
        }
        return nl2br(self::e((string)($page['body']??'')));
    }
    public static function archive(array $data):string
    {
        $html='<div class="vz-canvas-archive-list">';
        foreach($data['items'] as $item){
            if(preg_match('/\A[a-z0-9][a-z0-9-]{0,188}\z/D',(string)$item['slug'])!==1)continue;
            $url='/'.rawurlencode($data['locale']).'/page/'.$item['slug'];
            $excerpt=mb_substr(trim(strip_tags((string)$item['body'])),0,180);
            $html.='<article data-archive-id="'.(int)$item['id'].'"><h2><a href="'.self::e($url).'">'.self::e((string)$item['title']).'</a></h2><p>'.self::e($excerpt).'</p></article>';
        }
        if(!$data['items'])$html.='<p data-archive-empty>هنوز نوشته‌ای منتشر نشده است.</p>';
        $html.='</div><nav class="vz-canvas-pagination" aria-label="صفحه‌بندی آرشیو">';
        foreach([-1=>'قبلی',1=>'بعدی'] as $delta=>$label){
            $next=$data['page']+$delta;
            if($next>=1&&$next<=$data['pages'])$html.='<a rel="'.($delta<0?'prev':'next').'" href="/'.rawurlencode($data['locale']).'/archive?p='.$next.'">'.$label.'</a>';
        }
        return $html.'</nav>';
    }
}
