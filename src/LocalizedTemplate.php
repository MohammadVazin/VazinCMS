<?php
declare(strict_types=1);
namespace VazinCMS;

use RuntimeException;

/**
 * Renders trusted PHP templates while isolating every dynamic short-echo slot.
 * Static chrome is translated only after those slots have been replaced by
 * unguessable placeholders, so customer/provider/profile values are restored
 * byte-for-byte and can never be changed by a translation key collision.
 */
final class LocalizedTemplate
{
    public static function render(array $paths,array $scope,array $allowedRoots=[]):string
    {
        extract($scope,EXTR_SKIP);
        ob_start();
        try{
            foreach($paths as $path){
                $source=self::source((string)$path,$allowedRoots);
                eval('?>'.self::isolateEchoes($source));
            }
            return(string)ob_get_clean();
        }catch(\Throwable $error){ob_end_clean();throw$error;}
    }

    private static function source(string $path,array $allowedRoots):string
    {
        $roots=[dirname(__DIR__).'/views',...$allowedRoots];
        $resolvedRoots=[];
        foreach($roots as $root){
            $candidate=realpath((string)$root);
            if($candidate!==false&&is_dir($candidate)&&!is_link($candidate))$resolvedRoots[]=$candidate;
        }
        $resolved=realpath($path);$allowed=false;
        if($resolved!==false&&is_file($resolved)&&!is_link($resolved)){
            foreach($resolvedRoots as $root){
                if($resolved===$root||str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)){$allowed=true;break;}
            }
        }
        if(!$allowed){
            throw new RuntimeException('مسیر قالب معتبر نیست.');
        }
        $source=file_get_contents($resolved);
        if(!is_string($source)||strlen($source)>2_000_000)throw new RuntimeException('قالب قابل خواندن نیست.');
        // View supplies one canonical head/foot. A few legacy templates also
        // require those files internally; remove only these exact trusted
        // includes so no unisolated nested render or duplicate shell remains.
        $source = str_replace([
            "require __DIR__.'/partials/head.php';","require __DIR__.'/partials/foot.php';",
            "require __DIR__.'/head.php';","require __DIR__.'/foot.php';",
            'require __DIR__ . \'/partials/head.php\';','require __DIR__ . \'/partials/foot.php\';',
            'require __DIR__ . \'/head.php\';','require __DIR__ . \'/foot.php\';',
        ],'',$source);
        // Composed templates share one eval() body. A template-local strict
        // declaration is valid on disk but cannot appear after the shared
        // head, so retain its PHP opening tag and omit only that declaration.
        return preg_replace('/^(<\\?php\\s*)declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;\\s*/','$1',$source,1) ?? $source;
    }

    private static function isolateEchoes(string $source):string
    {
        $tokens=token_get_all($source);$output='';$count=count($tokens);
        for($index=0;$index<$count;$index++){
            $token=$tokens[$index];
            if(is_array($token)&&$token[0]===T_OPEN_TAG_WITH_ECHO){
                $expression='';$closed=false;
                for($index++;$index<$count;$index++){
                    $part=$tokens[$index];
                    if(is_array($part)&&$part[0]===T_CLOSE_TAG){$closed=true;break;}
                    $expression.=self::tokenText($part);
                }
                if(!$closed||trim($expression)==='')throw new RuntimeException('ساختار قالب معتبر نیست.');
                $expression=preg_replace('/;\s*$/','',$expression)??$expression;
                $output.='<?php echo \\VazinCMS\\UiLocale::protect((string)('.$expression.')); ?>';
                continue;
            }
            $output.=self::tokenText($token);
        }
        return$output;
    }

    private static function tokenText(array|string $token):string
    {
        if(!is_array($token))return$token;
        $text=$token[1];
        // Persian literals used by status/label ternaries and local label
        // arrays are trusted UI keys. Translate them before the complete echo
        // result is isolated; runtime/database values never take this path.
        if($token[0]===T_CONSTANT_ENCAPSED_STRING&&preg_match('/[\x{0600}-\x{06FF}]/u',$text)===1){
            return'\\VazinCMS\\UiLocale::t('.$text.')';
        }
        return$text;
    }
}
