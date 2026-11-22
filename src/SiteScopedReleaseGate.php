<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Independent, read-only, fail-closed release-readiness assessment.
 *
 * This class has NO deploy/install/migrate/enable functionality. Its sources
 * are actual manifest bytes, formal migration files and read-only database
 * counts, not caller-provided "all passed" flags or report prose.
 *
 * External approvals (signer, live Vazin ID, Windows UI, extension isolation)
 * deliberately remain BLOCKED until a separate trusted verification workflow
 * supplies cryptographically authenticated evidence. They cannot be cleared
 * by adjusting untrusted candidate-local files.
 */
final class SiteScopedReleaseGate
{
    /** @return array<string,mixed> */
    public static function assess(string $candidateRoot,?PDO $readOnly=null):array
    {
        $root=realpath($candidateRoot);
        if(!is_string($root) || !is_dir($root) ||
           !is_file($root.'/MANIFEST.sha256')){
            throw new RuntimeException('release_candidate_missing');
        }
        [$manifestOk,$manifestCount,$manifestProblems]=self::manifest($root);
        $version=trim((string)@file_get_contents($root.'/VERSION'));
        $stagedMigrationsComplete=is_file(
            $root.'/src/SiteTenantSqliteUniqueMigrator.php'
        );
        for($i=0;$i<=8;$i++){
            foreach(['sqlite','pgsql'] as $driver){
                if(!is_file($root.'/database/migrations/10.31.'.$i.'-'.$driver.'.sql')){
                    $stagedMigrationsComplete=false;
                }
            }
        }
        // Staged files alone are not an approved, signed, official tenant
        // migration. Promotion requires an independent trust anchor.
        $migrationReady=false;
        $isolationCode=(string)@file_get_contents($root.'/src/SiteIsolationGate.php');
        // DB-only evidence, never proof of DNS challenge or external
        // owner consent. Match each site's owner to that SAME site's only
        // owner membership, an active user, and exactly one verified PRIMARY
        // domain. Independent signer and human acceptance still block GO.
        $dbConsistent=$readOnly instanceof PDO
            && self::ownerDomainDatabaseComplete($readOnly);
        // A DB row (even with verified_at set) is not proof of an
        // independently validated DNS/HTTP challenge or owner consent.
        // Never mark this release gate verified from the database alone.
        $externallyVerifiedOwnerDomain=false;

        // Presence of the candidate's own signed-looking document is NOT
        // enough: an independent trust anchor and signer provenance is
        // required. Never generate a signing key to satisfy this gate.
        $checks=[
            'manifest_canonical_complete'=>$manifestOk && $manifestCount>=600,
            'candidate_version_valid'=>preg_match('/^10\.30\.\d+$/D',$version)===1,
            'staged_pg_and_sqlite_migrations_complete'=>$stagedMigrationsComplete,
            'official_tenant_migrations'=>$migrationReady,
            'verified_owner_and_domain'=>$externallyVerifiedOwnerDomain,
            'multisite_http_gate_still_closed'=>
                str_contains($isolationCode,'class SiteIsolationGate'),
            'external_release_signature_verified'=>false,
            'external_live_oidc_staff_e2e_verified'=>false,
            'external_windows_mobile_ui_acceptance_verified'=>false,
            'external_full_php_extension_isolation_verified'=>false,
            'external_disaster_recovery_on_authorized_history_verified'=>false,
        ];

        // The retention safety guard must stay CLOSED until all independent
        // checks are proved by an authorized reviewer. It is not a bypass.
        $blockers=[];
        foreach($checks as $name=>$passed){
            if(!$passed)$blockers[]=$name;
        }
        // Even if all other checks pass, removing central HTTP isolation
        // needs a distinct signed tenant-safety approval (not present).
        $blockers[]='signed_multisite_isolation_release_approval_missing';
        $blockers=array_values(array_unique($blockers));
        return [
            'release_decision'=>'NO_GO',
            'production_actions_performed'=>false,
            'candidate_version'=>$version,
            'manifest_sha256'=>hash_file('sha256',$root.'/MANIFEST.sha256'),
            'tracked_files'=>$manifestCount,
            'checks'=>$checks,
            'observations'=>[
                'owner_domain_database_consistent'=>$dbConsistent,
                'external_owner_domain_proof_verified'=>false,
            ],
            'blockers'=>$blockers,
            'manifest_errors'=>$manifestProblems,
        ];
    }

    /**
     * Read-only DB consistency witness. This CANNOT verify a live DNS
     * challenge, real account consent or signed ownership attestation.
     * Any ambiguous site, wrong member, unverified primary domain or
     * unavailable table must fail closed without writing any row.
     */
    public static function ownerDomainDatabaseComplete(PDO $db):bool
    {
        try {
            $sql=
                'SELECT s.id, CASE WHEN ' .
                "s.status IN ('active','staging') " .
                "AND s.owner_user_id IS NOT NULL AND u.status='active' " .
                'AND (SELECT COUNT(*) FROM cms_site_memberships m ' .
                "WHERE m.site_id=s.id AND m.site_role='owner')=1 " .
                'AND EXISTS (SELECT 1 FROM cms_site_memberships m ' .
                'WHERE m.site_id=s.id AND m.user_id=s.owner_user_id ' .
                "AND m.site_role='owner') " .
                'AND (SELECT COUNT(*) FROM cms_site_domains d ' .
                'WHERE d.site_id=s.id AND d.is_primary=TRUE)=1 ' .
                'AND (SELECT COUNT(*) FROM cms_site_domains d ' .
                'WHERE d.site_id=s.id AND d.is_primary=TRUE ' .
                "AND d.verified_at IS NOT NULL AND TRIM(d.domain)<>'')=1 " .
                'THEN 1 ELSE 0 END AS db_complete ' .
                'FROM cms_sites s LEFT JOIN users u ON u.id=s.owner_user_id';
            $stmt=$db->query($sql);
            try { $rows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
            finally { $stmt->closeCursor(); }
            if(count($rows)<2)return false;
            foreach($rows as $row) {
                if((int)($row['db_complete']??0)!==1)return false;
            }
            return true;
        } catch(Throwable) {
            return false;
        }
    }

    /** @return array{bool,int,list<string>} */
    private static function manifest(string $root):array
    {
        $manifest=file($root.'/MANIFEST.sha256',FILE_IGNORE_NEW_LINES);
        if(!is_array($manifest))return [false,0,['read_failed']];
        $ok=true;$count=0;$problems=[];$seen=[];
        foreach($manifest as $line) {
            if(!preg_match('/^([a-f0-9]{64})  (.+)$/D',$line,$m)){
                $ok=false;$problems[]='bad_line_format';continue;
            }
            $name=$m[2];
            if(str_starts_with($name,'/') ||
               preg_match('#(?:^|/)\.\.(?:/|$)#',$name) ||
               isset($seen[$name]) || $name==='MANIFEST.sha256'){
                $ok=false;$problems[]='unsafe_or_duplicate_path';continue;
            }
            $seen[$name]=true;
            $file=$root.'/'.$name;
            $resolved=realpath($file);
            if(!is_string($resolved) || is_link($file) ||
               !str_starts_with($resolved,$root.DIRECTORY_SEPARATOR) ||
               !is_file($resolved) ||
               !hash_equals($m[1],(string)hash_file('sha256',$resolved))){
                $ok=false;$problems[]='mismatched_or_missing_file';
            }
            $count++;
        }
        $actual=[];
        $iter=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach($iter as $item){
            if($item->isLink()){
                $ok=false;$problems[]='symlink_in_candidate';
                continue;
            }
            if(!$item->isFile())continue;
            $name=str_replace(DIRECTORY_SEPARATOR,'/',
                substr($item->getPathname(),strlen($root)+1));
            if(self::excluded($name))continue;
            $actual[$name]=true;
        }
        if(count($actual)!==$count ||
           count(array_diff_key($actual,$seen))>0){
            $ok=false;$problems[]='untracked_release_files';
        }
        return [$ok,$count,array_values(array_unique($problems))];
    }

    private static function excluded(string $name):bool
    {
        if($name==='MANIFEST.sha256' || $name==='.git' ||
           str_starts_with($name,'.git/') ||
           $name==='.env' ||
           (str_starts_with($name,'.env.') && $name!=='.env.example') ||
           $name==='storage' || str_starts_with($name,'storage/') ||
           $name==='public/uploads' ||
           str_starts_with($name,'public/uploads/')){
            return true;
        }
        return in_array('__pycache__',explode('/',$name),true) ||
            str_ends_with($name,'.pyc') || str_ends_with($name,'.bak');
    }
}
