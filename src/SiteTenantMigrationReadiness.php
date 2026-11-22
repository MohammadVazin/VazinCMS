<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO;
use Throwable;

/**
 * Read-only operator preflight for a future explicit, reviewed tenant-schema
 * migration. Even a positive result IS NOT release/deployment approval.
 */
final class SiteTenantMigrationReadiness
{
    public static function inspect(PDO $pdo): array
    {
        $blockers=[];
        $counts=[];
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['sqlite','pgsql'],true)){
            return ['schema_proposal_review_ready'=>false,'blockers'=>['unsupported_driver']];
        }
        try {
            $sites=(int)$pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn();
            $counts['site_count']=$sites;
            $site=$pdo->query("SELECT id,owner_user_id FROM cms_sites ".
                "WHERE site_key='default' AND status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if($sites!==1 || !is_array($site)){
                $blockers[]='requires_exactly_one_active_default_site';
            } else {
                $siteId=(int)$site['id'];
                $ownerId=(int)($site['owner_user_id']??0);
                if($ownerId<1){
                    $blockers[]='missing_explicit_site_owner_binding';
                } else {
                    $q=$pdo->prepare(
                        "SELECT COUNT(*) FROM users u WHERE u.id=:owner ".
                        "AND u.role='owner' AND u.status='active'"
                    );
                    $q->execute(['owner'=>$ownerId]);
                    if((int)$q->fetchColumn()!==1){
                        $blockers[]='invalid_or_inactive_owner';
                    } else {
                        $q=$pdo->prepare(
                            "SELECT COUNT(*) FROM cms_site_memberships m ".
                            "JOIN users u ON u.id=m.user_id ".
                            "WHERE m.site_id=:site AND m.user_id=:owner ".
                            "AND m.site_role='owner' AND u.status='active'"
                        );
                        $q->execute(['site'=>$siteId,'owner'=>$ownerId]);
                        $exactOwner=(int)$q->fetchColumn();
                        $q->closeCursor();
                        $ownerCount=$pdo->prepare(
                            "SELECT COUNT(*) FROM cms_site_memberships ".
                            "WHERE site_id=:site AND site_role='owner'"
                        );
                        $ownerCount->execute(['site'=>$siteId]);
                        $totalOwners=(int)$ownerCount->fetchColumn();
                        $ownerCount->closeCursor();
                        if($exactOwner!==1 || $totalOwners!==1){
                            $blockers[]='owner_membership_requires_explicit_binding';
                        }
                    }
                }
                // A random verified secondary domain is not a verified
                // canonical host. Require exactly one primary row and that
                // very row to have a nonempty domain and verified_at in DB.
                // This is DB consistency only, not external DNS proof.
                $q=$pdo->prepare(
                    'SELECT COUNT(*) FROM cms_site_domains '.
                    'WHERE site_id=:site AND is_primary=TRUE'
                );
                $q->execute(['site'=>$siteId]);
                $primaries=(int)$q->fetchColumn();
                $q->closeCursor();
                $q=$pdo->prepare(
                    'SELECT COUNT(*) FROM cms_site_domains '.
                    'WHERE site_id=:site AND is_primary=TRUE '.
                    "AND verified_at IS NOT NULL AND TRIM(domain)<>''"
                );
                $q->execute(['site'=>$siteId]);
                $verified=(int)$q->fetchColumn();
                $q->closeCursor();
                $counts['primary_domains']=$primaries;
                $counts['verified_domains']=$verified;
                if($primaries!==1 || $verified!==1){
                    $blockers[]='verified_canonical_domain_missing';
                }
            }
            $media=(int)$pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn();
            $counts['legacy_media_rows']=$media;
            if($media>0)$blockers[]='legacy_media_path_migration_restore_drill_required';
        } catch(Throwable) {
            $blockers[]='preflight_query_or_schema_unavailable';
        }
        return [
            'schema_proposal_review_ready'=>count($blockers)===0,
            'blockers'=>$blockers,
            'counts'=>$counts,
            'notice'=>'Read-only preflight only. Separate release, backup, restore and owner approvals remain required.'
        ];
    }
}
