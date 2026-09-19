<?php
declare(strict_types=1);
namespace VazinCMS;

final class ThemeHierarchy
{
    public static function candidates(string $context,array $data=[]): array
    {
        return match($context){
            'front-page'=>['front-page','home','page','index'],
            'home'=>['home','archive','index'],
            'single'=>array_values(array_filter(['single-'.($data['content_type']??''),'single','index'])),
            'page'=>array_values(array_filter(['page-'.($data['slug']??''),'page','index'])),
            'archive'=>array_values(array_filter(['archive-'.($data['content_type']??''),'archive','index'])),
            'taxonomy'=>array_values(array_filter(['taxonomy-'.($data['taxonomy']??''),'taxonomy','archive','index'])),
            'search'=>['search','archive','index'],
            '404'=>['404','index'],
            default=>[$context,'index'],
        };
    }
}
