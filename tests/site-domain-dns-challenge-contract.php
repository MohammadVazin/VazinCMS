<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteDomainDnsClaim.php';
use VazinCMS\SiteDomainDnsClaim as Claim;
$expect=static function(bool $v,string $why):void {
    if(!$v)throw new RuntimeException('DOMAIN_CHALLENGE_QA_FAILED:'.$why);
};
$deny=static function(callable $fn) use($expect):void {
    try {$fn();}catch(InvalidArgumentException){return;}
    $expect(false,'unsafe_input_accepted');
};
$expect(Claim::canonicalHost('WWW.Example.ORG')==='www.example.org','canonical_case');
foreach([
    '','localhost','127.0.0.1','[::1]','example.invalid','site.local',
    'evil.com:443','user@evil.com','http://evil.com','*.example.org',
    'evil.com/other','a..example.org','-bad.example.org','foo_.example.org',
    'example.org?any=1',' example.org:443 ',str_repeat('a',64).'.org',
    "а.example.org","bad\n.example.org",'a.example', 'host.test'
] as $invalid){
    $deny(static fn()=>Claim::canonicalHost($invalid));
}
$now=1793896000;
$nonce=str_repeat('b',32);
$plan=Claim::plan(42,7,'Verify.Example.Org',$nonce,$now,600);
$expect($plan['hostname']==='_vazincms-verify.verify.example.org',
    'challenge_host_mismatch');
$expect(str_contains($plan['txt_record'],'42:7:'.$nonce),
    'owner_site_nonce_not_bound');
$match=Claim::inspectObservation($plan,[$plan['txt_record']],$now+1);
$expect($match['dns_observation_matches']===true,'exact_txt_missed');
$expect($match['ownership_verified']===false && $match['release_go']===false,
    'offline_observation_incorrectly_approved');
foreach([
    [[$plan['txt_record']],$now+601],
    [[$plan['txt_record'],$plan['txt_record']],$now+1],
    [['vazincms-domain-v1:43:7:'.$nonce],$now+1],
    [['vazincms-domain-v1:42:8:'.$nonce],$now+1],
    [['vazincms-domain-v1:42:7:'.str_repeat('a',32)],$now+1],
    [[], $now+1],
    [[str_repeat('x',1025)],$now+1]
] as [$txt,$time]){
    $x=Claim::inspectObservation($plan,$txt,$time);
    $expect($x['dns_observation_matches']===false &&
        $x['ownership_verified']===false && $x['release_go']===false,
        'bad_expired_duplicate_or_cross_site_claim_accepted');
}
$deny(static fn()=>Claim::plan(0,7,'example.org',$nonce,$now));
$deny(static fn()=>Claim::plan(42,0,'example.org',$nonce,$now));
$deny(static fn()=>Claim::plan(42,7,'example.org','short',$now));
$deny(static fn()=>Claim::plan(42,7,'example.org',$nonce,$now,3601));
echo "SITE_DOMAIN_DNS_CHALLENGE_PLAN=PASS canonical_host,site_actor_nonce,expiry,duplicate,replay_hint_cross_site_denied,never_authorizes\n";
