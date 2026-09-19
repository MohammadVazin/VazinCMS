<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class ThemeSettings
{
    public static function styles(PDO $pdo): array
    {
        $raw=(string)$pdo->query('SELECT style_tokens_json FROM cms_theme_settings WHERE id=1')->fetchColumn();
        $data=json_decode($raw,true);return is_array($data)?$data:[];
    }

    public static function saveStyles(PDO $pdo,array $tokens): void
    {
        $allowed=['color.primary','color.background','color.text','font.family','layout.contentWidth','spacing.base'];$clean=[];
        foreach($tokens as $k=>$v){if(in_array((string)$k,$allowed,true)&&is_scalar($v))$clean[(string)$k]=(string)$v;}
        $json=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE cms_theme_settings SET style_tokens_json=:json,updated_at=CURRENT_TIMESTAMP WHERE id=1')->execute(['json'=>$json]);
    }

    public static function cssVariables(array $tokens): string
    {
        $out=[];foreach($tokens as $k=>$v){$name='--vazin-'.str_replace('.','-',preg_replace('/[^a-zA-Z0-9.\-]/','',(string)$k));$out[]=$name.':'.self::cssValue((string)$v);}
        return implode(';',$out);
    }
    private static function cssValue(string $value): string
    {
        if(preg_match('/^#[0-9a-fA-F]{3,8}$/',$value))return $value;
        if(preg_match('/^[0-9.]+(?:px|rem|em|%)$/',$value))return $value;
        return preg_replace('/[^a-zA-Z0-9 ,"\'-]/','',$value)??'';
    }
}
