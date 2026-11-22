<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/SiteReleaseEvidenceVerifier.php';
use VazinCMS\SiteReleaseEvidenceVerifier as Verifier;
$need=static function(bool $v,string $reason):void{
    if(!$v)throw new RuntimeException('EXTERNAL_SIGNATURE_CONTRACT_FAIL:'.$reason);
};
// RFC8032 Ed25519 test vector #1, known public signature for empty bytes.
// We DO NOT generate any key, sign any data or approve a VazinCMS release.
$pk='d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
$sig='e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';
$need(Verifier::verifyDetachedHex($pk,$sig,'')===true,
      'known_reference_signature_not_verified');
$need(Verifier::verifyDetachedHex($pk,$sig,'changed')===false,
      'tampered_payload_accepted');
$need(Verifier::verifyDetachedHex($pk,str_repeat('0',128),'')===false,
      'forged_signature_accepted');
$need(Verifier::verifyDetachedHex('invalid',$sig,'')===false,
      'invalid_key_accepted');
$e=Verifier::inspect($root);
$need(($e['signed_multisite_release_go']??null)===false,
      'verify_only_tool_authorized_release');
$need(($e['production_actions_performed']??null)===false,
      'verify_only_tool_claimed_deploy');
$need(is_bool($e['external_signature_valid']??null),
      'external_verification_result_not_boolean');
echo "SITE_EXTERNAL_SIGNATURE_VERIFY_ONLY=PASS reference_vector,tamper_denied,trust_anchor_missing,go_denied\n";
