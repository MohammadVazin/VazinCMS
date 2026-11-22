<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;

/**
 * Pure candidate-only DNS TXT challenge FORMAT / observation checker.
 *
 * It does NOT call DNS or HTTP, does NOT write verified_at, does NOT attest
 * that an observation came from independent resolvers, and cannot grant
 * owner/domain access. A future authorized external observer must perform
 * network-safe DNS proof, bind site+actor+nonce, enforce replay prevention,
 * persist a separately signed receipt, and provide independent approval.
 */
final class SiteDomainDnsClaim
{
    public static function canonicalHost(string $input):string
    {
        // Reject credentials, ports, wildcards, paths, URLs and hostless
        // values, not just PHP FILTER_VALIDATE_URL success.
        $value=trim($input);
        if($value==='' || strlen($value)>253 ||
           preg_match('/[^\x21-\x7e]/D',$value) ||
           strpbrk($value,'/@:?#\\%*')!==false){
            throw new InvalidArgumentException('domain_host_not_canonical');
        }
        $value=strtolower($value);
        if(filter_var($value,FILTER_VALIDATE_IP) !== false ||
           str_contains($value,'..')){
            throw new InvalidArgumentException('domain_host_not_canonical');
        }
        $labels=explode('.',$value);
        if(count($labels)<2){
            throw new InvalidArgumentException('domain_host_not_canonical');
        }
        foreach($labels as $label){
            if(strlen($label)<1 || strlen($label)>63 ||
               preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',$label)!==1){
                throw new InvalidArgumentException('domain_host_not_canonical');
            }
        }
        $suffix=end($labels);
        if(strlen($suffix)<2 || ctype_digit($suffix) ||
           in_array($suffix,['localhost','local','internal','invalid',
               'test','example','onion','arpa'],true)){
            throw new InvalidArgumentException('domain_host_not_canonical');
        }
        return $value;
    }

    /**
     * Describe a fresh expected DNS record. This is only a challenge
     * proposal; storing this value is not ownership verification.
     *
     * @return array{hostname:string,txt_record:string,site_id:int,actor_id:int,expires_at:int}
     */
    public static function plan(
        int $siteId,int $actorId,string $domain,string $nonce,
        int $issuedAt,int $ttlSeconds=600
    ):array {
        $domain=self::canonicalHost($domain);
        if($siteId<1 || $actorId<1 ||
           preg_match('/\A[a-f0-9]{32,64}\z/D',$nonce)!==1 ||
           $issuedAt<1600000000 ||
           $ttlSeconds<60 || $ttlSeconds>900){
            throw new InvalidArgumentException('domain_challenge_invalid');
        }
        return [
            'hostname'=>'_vazincms-verify.'.$domain,
            'txt_record'=>'vazincms-domain-v1:'.$siteId.':'.$actorId.':'.$nonce,
            'site_id'=>$siteId,
            'actor_id'=>$actorId,
            'expires_at'=>$issuedAt+$ttlSeconds,
        ];
    }

    /**
     * Offline contract for a bounded observation supplied by a hypothetical
     * trusted resolver. Exact and unique TXT match only. The return value
     * is intentionally NOT a grant and MUST NOT update any CMS row.
     *
     * @param array<string,mixed> $plan
     * @param list<string> $observed
     * @return array{dns_observation_matches:bool,ownership_verified:bool,release_go:bool}
     */
    public static function inspectObservation(
        array $plan,array $observed,int $now
    ):array {
        $matches=false;
        if($now>0 && $now<=(int)($plan['expires_at']??0) &&
           count($observed)>0 && count($observed)<=32 &&
           is_string($plan['txt_record']??null) &&
           is_string($plan['hostname']??null)){
            $count=0;
            foreach($observed as $entry){
                if(!is_string($entry) || strlen($entry)>1024){
                    $count=-1;
                    break;
                }
                if(hash_equals($plan['txt_record'],$entry))$count++;
            }
            $matches=$count===1;
        }
        return [
            'dns_observation_matches'=>$matches,
            // Even a positive observation is unauthenticated input and
            // does not prove the caller resolved a real public DNS record.
            'ownership_verified'=>false,
            'release_go'=>false,
        ];
    }
}
