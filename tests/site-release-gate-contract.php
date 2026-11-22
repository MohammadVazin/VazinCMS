<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/SiteScopedReleaseGate.php';
use VazinCMS\SiteScopedReleaseGate;
$report=SiteScopedReleaseGate::assess($root);
$verify=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SITE_RELEASE_GATE_FAIL: '.$why);
};
$verify($report['release_decision']==='NO_GO',
        'unsigned customer multisite release appeared approved');
$verify($report['production_actions_performed']===false,
        'preflight reported deployment');
$verify($report['checks']['manifest_canonical_complete']===true,
        'manifest not verified');
$verify($report['checks']['official_tenant_migrations']===false,
        'unofficial proposals treated as release-ready');
$verify($report['checks']['external_live_oidc_staff_e2e_verified']===false,
        'synthetic IdP incorrectly accepted as live');
$verify($report['checks']['external_full_php_extension_isolation_verified']===false,
        'prototype sandbox incorrectly treated as production integrated');
$verify(in_array('signed_multisite_isolation_release_approval_missing',
        $report['blockers'],true),'central multisite approval bypass');
$verify(count($report['blockers'])>=7,
        'missing required independent gates');
echo "SITE_RELEASE_READINESS=PASS fail_closed_no_go,unsigned_proposals_and_live_approvals_blocked\n";
