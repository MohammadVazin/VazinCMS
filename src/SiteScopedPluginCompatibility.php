<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

/**
 * Read-only, fail-closed capability preflight for the current worker image.
 *
 * This is a compatibility diagnostic, not a security scanner. It tokenizes
 * PHP without evaluating it and reports CMS host APIs which the isolated
 * worker does not expose. A signed receipt cannot override missing APIs.
 */
final class SiteScopedPluginCompatibility
{
    private const REQUIRED_API_PATTERNS=[
        'cms_module_context_routes'=>'/\bModuleContext\b/',
        'cms_authentication'=>'/\bAuth\s*::/',
        'cms_authorization'=>'/\bAccess\s*::/',
        'cms_database'=>'/\bDatabase\s*::\s*connection\s*\(/',
        'cms_runtime_paths'=>'/\bRuntimePaths\s*::/',
        'cms_site_context'=>'/\bSiteContext\s*::/',
        'cms_secret_store'=>'/\bSecretStore\s*::/',
        'cms_security_csrf'=>'/\bSecurity\s*::/',
        'cms_media_storage'=>'/\bMediaStorage\s*::/',
        'outbound_https'=>'/\bcurl_(?:init|exec)\s*\(/',
        'host_filesystem_writes'=>'/\b(?:file_put_contents|mkdir|unlink|rename|move_uploaded_file)\s*\(/',
    ];

    /**
     * @return array{compatible:bool,files_scanned:int,php_files:int,missing_capabilities:list<string>,worker_profile:string}
     */
    public static function inspect(string $packageRoot):array
    {
        if(PHP_SAPI!=='cli'){
            throw new RuntimeException('plugin_compatibility_cli_only');
        }
        $root=realpath($packageRoot);
        if(!is_string($root) || !is_dir($root) || is_link($packageRoot)){
            throw new RuntimeException('plugin_compatibility_package_untrusted');
        }
        // Reuse the sandbox's tree walker: this rejects symlinks, special
        // files, path tricks and oversized packages before source scanning.
        SiteScopedExtensionSandbox::packageDigest($root);
        $phpFiles=[];$allFiles=0;
        $iterator=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)
        );
        foreach($iterator as $entry){
            if($entry->isLink())throw new RuntimeException('plugin_compatibility_package_untrusted');
            if(!$entry->isFile())continue;
            $allFiles++;
            if(strtolower($entry->getExtension())==='php'){
                $phpFiles[]=$entry->getPathname();
            }
        }
        if($phpFiles===[]){
            throw new RuntimeException('plugin_compatibility_no_php_entrypoint');
        }
        $code='';
        foreach($phpFiles as $file){
            $source=file_get_contents($file);
            if(!is_string($source)){
                throw new RuntimeException('plugin_compatibility_source_unreadable');
            }
            $tokens=token_get_all($source);
            foreach($tokens as $token){
                if(is_string($token)){
                    $code.=$token;
                    continue;
                }
                [$id,$value]=$token;
                if(in_array($id,[
                    T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,
                    T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE,
                ],true)){
                    continue;
                }
                $code.=$value;
            }
            $code.="\n";
        }
        $missing=[];
        foreach(self::REQUIRED_API_PATTERNS as $capability=>$pattern){
            if(preg_match($pattern,$code)===1)$missing[]=$capability;
        }
        sort($missing,SORT_STRING);
        return [
            'compatible'=>$missing===[],
            'files_scanned'=>$allFiles,
            'php_files'=>count($phpFiles),
            'missing_capabilities'=>$missing,
            'worker_profile'=>'php-cli-readonly-package-no-network-no-cms-api',
        ];
    }
}
