<?php
declare(strict_types=1);
namespace VazinCMS;

/**
 * Read-only candidate QA for a disposable DB + media archive. This does NOT
 * back up, sign, restore, deploy, authenticate or access production. An
 * untrusted manifest is only an inventory consistency witness, not proof
 * of backup authenticity or real-world RPO/RTO.
 */
final class SiteRestoreArchiveIntegrity
{
    /** @return array{ok:bool,files:int,errors:list<string>,external_signature_verified:bool} */
    public static function inspect(string $archiveRoot):array
    {
        $errors=[];$tracked=[];
        $root=realpath($archiveRoot);
        if(!is_string($root) || !is_dir($root) || is_link($archiveRoot)){
            return self::fail('archive_root_missing');
        }
        $manifest=$root.'/archive-manifest.json';
        if(!is_file($manifest) || is_link($manifest)){
            return self::fail('manifest_missing');
        }
        $decoded=json_decode((string)@file_get_contents($manifest),true);
        if(!is_array($decoded) ||
           ($decoded['schema']??null)!=='vazincms-restore-check/v1' ||
           !is_array($decoded['files']??null) ||
           count($decoded['files'])<2 || count($decoded['files'])>1000){
            return self::fail('manifest_invalid');
        }
        foreach($decoded['files'] as $entry){
            if(!is_array($entry) ||
               !is_string($entry['path']??null) ||
               !is_string($entry['sha256']??null) ||
               !is_int($entry['bytes']??null)){
                $errors[]='file_entry_invalid';continue;
            }
            $name=$entry['path'];
            if(!preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z/D',$name) ||
               $name==='archive-manifest.json' ||
               str_contains($name,'..') ||
               isset($tracked[$name])){
                $errors[]='unsafe_or_duplicate_path';continue;
            }
            $tracked[$name]=true;
            if(!preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256']) ||
               $entry['bytes']<0){
                $errors[]='invalid_checksum_or_size';continue;
            }
            $file=$root.'/'.$name;
            $real=realpath($file);
            if(!is_string($real) ||
               !str_starts_with($real,$root.DIRECTORY_SEPARATOR) ||
               !is_file($real) || is_link($file) ||
               filesize($real)!==$entry['bytes'] ||
               !hash_equals($entry['sha256'],(string)hash_file('sha256',$real))){
                $errors[]='file_missing_tampered_or_outside';
            }
        }
        $actual=[];
        $iterator=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach($iterator as $item){
            $p=$item->getPathname();
            $name=str_replace(DIRECTORY_SEPARATOR,'/',substr($p,strlen($root)+1));
            if($item->isLink()){
                $errors[]='symlink_in_archive';continue;
            }
            if(!$item->isFile()){
                $errors[]='non_regular_archive_entry';continue;
            }
            if($name!=='archive-manifest.json')$actual[$name]=true;
        }
        if(count($actual)!==count($tracked) ||
           array_diff_key($actual,$tracked) ||
           array_diff_key($tracked,$actual)){
            $errors[]='archive_inventory_incomplete';
        }
        return [
            'ok'=>$errors===[],
            'files'=>count($tracked),
            'errors'=>array_values(array_unique($errors)),
            'external_signature_verified'=>false,
        ];
    }

    /** @return array{ok:bool,files:int,errors:list<string>,external_signature_verified:bool} */
    private static function fail(string $why):array
    {
        return ['ok'=>false,'files'=>0,'errors'=>[$why],
                'external_signature_verified'=>false];
    }
}
