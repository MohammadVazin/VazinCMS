<?php
declare(strict_types=1);
namespace VazinCMS;

use InvalidArgumentException;

final class BlockEditor
{
    public const SCHEMA=1;
    private const TYPES=['paragraph','heading','list','image','gallery','button','embed','code','columns','reusable'];

    public static function decode(string $json): array
    {
        if(trim($json)==='')return ['schema'=>self::SCHEMA,'blocks'=>[]];
        $doc=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($doc)||($doc['schema']??null)!==self::SCHEMA||!is_array($doc['blocks']??null))throw new InvalidArgumentException('Block document is invalid.');
        foreach($doc['blocks'] as $block)self::validateBlock($block);
        return $doc;
    }

    public static function encode(array $doc): string
    {
        self::decode(json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function render(array $doc): string
    {
        $out='';foreach($doc['blocks'] as $block)$out.=self::renderBlock($block);return $out;
    }

    public static function legacy(string $body): array
    {
        $blocks=[];foreach(preg_split('/\R{2,}/u',trim($body))?:[] as $chunk){$text=trim($chunk);if($text==='')continue;$blocks[]=['type'=>'paragraph','data'=>['text'=>$text]];}
        return ['schema'=>self::SCHEMA,'blocks'=>$blocks];
    }

    private static function validateBlock(mixed $block): void
    {
        if(!is_array($block)||!in_array($block['type']??'',self::TYPES,true)||!is_array($block['data']??null))throw new InvalidArgumentException('Unsupported block.');
        if(($block['type']??'')==='columns')foreach(($block['data']['columns']??[]) as $column)foreach(($column['blocks']??[]) as $child)self::validateBlock($child);
    }

    private static function esc(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function renderBlock(array $b): string
    {
        $t=$b['type'];$d=$b['data'];
        return match($t){
            'paragraph'=>'<p>'.self::esc((string)($d['text']??'')).'</p>',
            'heading'=>'<h'.max(2,min(6,(int)($d['level']??2))).'>'.self::esc((string)($d['text']??'')).'</h'.max(2,min(6,(int)($d['level']??2))).'>',
            'list'=>self::renderList($d),
            'image'=>self::renderImage($d),
            'gallery'=>self::renderGallery($d),
            'button'=>self::renderButton($d),
            'embed'=>self::renderEmbed($d),
            'code'=>'<pre><code>'.self::esc((string)($d['code']??'')).'</code></pre>',
            'columns'=>self::renderColumns($d),
            'reusable'=>'<div data-reusable="'.self::esc((string)($d['key']??'')).'"></div>',
            default=>''};
    }

    private static function renderList(array $d):string{$tag=!empty($d['ordered'])?'ol':'ul';$items='';foreach((array)($d['items']??[]) as $x)$items.='<li>'.self::esc((string)$x).'</li>';return '<'.$tag.'>'.$items.'</'.$tag.'>';}
    private static function safeUrl(string $u):string{return filter_var($u,FILTER_VALIDATE_URL)&&preg_match('#^https?://#i',$u)?$u:(str_starts_with($u,'/')?$u:'');}
    private static function renderImage(array $d):string{$src=self::safeUrl((string)($d['src']??''));return $src===''?'':'<figure><img src="'.self::esc($src).'" alt="'.self::esc((string)($d['alt']??'')).'" loading="lazy"></figure>';}
    private static function renderGallery(array $d):string{$x='<div class="cms-gallery">';foreach((array)($d['images']??[]) as $i)$x.=self::renderImage((array)$i);return $x.'</div>';}
    private static function renderButton(array $d):string{$url=self::safeUrl((string)($d['url']??''));return $url===''?'':'<p class="cms-button"><a href="'.self::esc($url).'">'.self::esc((string)($d['label']??'')).'</a></p>';}
    private static function renderEmbed(array $d):string{$url=self::safeUrl((string)($d['url']??''));return $url===''?'':'<p class="cms-embed"><a href="'.self::esc($url).'" rel="noopener noreferrer">'.self::esc($url).'</a></p>';}
    private static function renderColumns(array $d):string{$out='<div class="cms-columns">';foreach((array)($d['columns']??[]) as $col){$out.='<div class="cms-column">';foreach((array)($col['blocks']??[]) as $child)$out.=self::renderBlock((array)$child);$out.='</div>';}$out.='</div>';return $out;}
}
