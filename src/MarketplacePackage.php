<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class MarketplacePackage
{
    public const FILE='vazin-marketplace.json';
    public static function verifyDirectory(PDO $pdo,string $directory,ExtensionManifest $manifest,string $subject=''): array
    {
        $file=rtrim($directory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::FILE;
        if(!is_file($file))return['signature_status'=>'untrusted-local','license_status'=>'not_required','publisher_key'=>null];
        $meta=json_decode((string)file_get_contents($file),true);if(!is_array($meta))throw new RuntimeException('Marketplace metadata invalid.');
        $publisher=(string)($meta['publisher_key']??'');$signature=(string)($meta['signature']??'');$license=(string)($meta['license_subject']??$subject);
        $payload=self::payload($directory,$manifest);
        return MarketplaceTrust::assertInstallable($pdo,$manifest->key(),$manifest->version(),$publisher,$payload,$signature,'domain',$license);
    }
    public static function payload(string $directory,ExtensionManifest $manifest): string
    {
        $rows=[];$root=rtrim($directory,DIRECTORY_SEPARATOR);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($it as$f){if(!$f->isFile())continue;$rel=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1));if($rel===self::FILE)continue;$rows[$rel]=hash_file('sha256',$f->getPathname());}
        ksort($rows,SORT_STRING);return json_encode(['extension_key'=>$manifest->key(),'version'=>$manifest->version(),'files'=>$rows],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
