<?php
declare(strict_types=1);
namespace VazinCMS;

use RuntimeException;

/**
 * Verify-only helper. Never creates keys, signatures, approvals, packages
 * or release decisions. An untrusted candidate file cannot pin its own key.
 * The trusted public key / signature receipt must be deployed separately
 * by an authorized operator. Missing external material = BLOCKED.
 */
final class SiteReleaseEvidenceVerifier
{
    private const TRUST_DIR='/etc/vazincms-release-approval';

    public static function verifyDetachedHex(
        string $publicKeyHex,string $signatureHex,string $message
    ):bool {
        if(!extension_loaded('sodium') ||
           preg_match('/\A[a-f0-9]{64}\z/D',$publicKeyHex)!==1 ||
           preg_match('/\A[a-f0-9]{128}\z/D',$signatureHex)!==1){
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(
                sodium_hex2bin($signatureHex),
                $message,
                sodium_hex2bin($publicKeyHex)
            );
        }catch(\Throwable) {
            return false;
        }
    }

    /**
     * A trusted key file is meaningless if its parent directory can be
     * replaced or modified by an untrusted user. These checks only inspect
     * metadata; they do not install any trust root or sign a release.
     */
    private static function externalDirectorySafe(string $path):bool
    {
        clearstatcache(true,$path);
        return is_dir($path) && !is_link($path) &&
            realpath($path)===$path && fileowner($path)===0 &&
            (fileperms($path)&0022)===0;
    }

    private static function trustedFileSafe(string $path,int $maxBytes):bool
    {
        clearstatcache(true,$path);
        $size=@filesize($path);
        return is_file($path) && !is_link($path) &&
            is_int($size) && $size>0 && $size<=$maxBytes &&
            fileowner($path)===0 && (fileperms($path)&0022)===0;
    }

    /** @return array<string,mixed> */
    public static function inspect(string $candidateRoot):array
    {
        $root=realpath($candidateRoot);
        if(!is_string($root) || !is_file($root.'/MANIFEST.sha256') ||
           !is_file($root.'/VERSION')){
            throw new RuntimeException('candidate_missing');
        }
        $version=trim((string)file_get_contents($root.'/VERSION'));
        $digest=hash_file('sha256',$root.'/MANIFEST.sha256');
        $message="VAZINCMS-RELEASE-EVIDENCE-V1\n".
            "product=VazinCMS\nversion=".$version.
            "\nmanifest_sha256=".$digest."\n";
        $keyPath=self::TRUST_DIR.'/trusted-ed25519-public-key.hex';
        $receiptPath=self::TRUST_DIR.'/attestations/'.$digest.'.sig.hex';
        $evidenceReady=false;
        $reason='external_trust_anchor_or_receipt_missing';
        // Only externally governed files; no key/signature parameters from
        // a candidate, HTTP request, environment or user-provided .json.
        if(self::externalDirectorySafe('/etc') &&
           self::externalDirectorySafe(self::TRUST_DIR) &&
           self::externalDirectorySafe(self::TRUST_DIR.'/attestations') &&
           self::trustedFileSafe($keyPath,128) &&
           self::trustedFileSafe($receiptPath,256)){
            $pk=strtolower(trim((string)file_get_contents($keyPath)));
            $sig=strtolower(trim((string)file_get_contents($receiptPath)));
            $evidenceReady=self::verifyDetachedHex($pk,$sig,$message);
            $reason=$evidenceReady?'cryptographic_receipt_valid_only':
                'external_signature_invalid';
        }
        return [
            'candidate_version'=>$version,
            'manifest_sha256'=>$digest,
            'external_signature_valid'=>$evidenceReady,
            // This verifier NEVER supplies independent release GO or
            // owner approval and cannot unlock SiteIsolationGate.
            'signed_multisite_release_go'=>false,
            'reason'=>$reason,
            'production_actions_performed'=>false,
        ];
    }
}
