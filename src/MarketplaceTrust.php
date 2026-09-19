<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class MarketplaceTrust
{
    public static function verifyDetached(string $payload,string $signatureB64,string $publicKeyB64): bool
    {
        if(!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('libsodium unavailable.');
        $sig=base64_decode($signatureB64,true);$key=base64_decode($publicKeyB64,true);
        if($sig===false||$key===false||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return false;
        return sodium_crypto_sign_verify_detached($sig,$payload,$key);
    }

    public static function publisher(PDO $pdo,string $key): ?array
    {
        $q=$pdo->prepare('SELECT * FROM marketplace_publishers WHERE publisher_key=:key');$q->execute(['key'=>$key]);$r=$q->fetch();return is_array($r)?$r:null;
    }

    public static function isRevoked(PDO $pdo,string $extension,string $version,string $publisher=''): bool
    {
        $q=$pdo->prepare('SELECT 1 FROM marketplace_revocations WHERE extension_key=:e AND (version IS NULL OR version=:v) AND (publisher_key IS NULL OR publisher_key=:p) LIMIT 1');$q->execute(['e'=>$extension,'v'=>$version,'p'=>$publisher]);return(bool)$q->fetchColumn();
    }
    public static function entitlement(PDO $pdo,string $extension,string $subjectType,string $subjectValue): string
    {
        $q=$pdo->prepare("SELECT status,expires_at FROM marketplace_entitlements WHERE extension_key=:e AND subject_type=:t AND subject_value=:v LIMIT 1");$q->execute(['e'=>$extension,'t'=>$subjectType,'v'=>$subjectValue]);$r=$q->fetch();if(!$r)return 'missing';if($r['status']!=='active')return(string)$r['status'];if(!empty($r['expires_at'])&&strtotime((string)$r['expires_at'])<time())return'expired';return'active';
    }

    public static function assertInstallable(PDO $pdo,string $extension,string $version,string $publisherKey,string $payload,string $signature,string $subjectType='domain',string $subjectValue=''): array
    {
        $publisher=self::publisher($pdo,$publisherKey);if(!$publisher||$publisher['trust_status']!=='trusted')throw new RuntimeException('Publisher is not trusted.');
        if(self::isRevoked($pdo,$extension,$version,$publisherKey))throw new RuntimeException('Package is revoked.');
        if(!self::verifyDetached($payload,$signature,(string)$publisher['public_key']))throw new RuntimeException('Package signature verification failed.');
        $license=$subjectValue===''?'not_required':self::entitlement($pdo,$extension,$subjectType,$subjectValue);if($subjectValue!==''&&$license!=='active')throw new RuntimeException('Package entitlement is not active.');
        return['signature_status'=>'trusted','license_status'=>$license,'publisher_key'=>$publisherKey];
    }
}
