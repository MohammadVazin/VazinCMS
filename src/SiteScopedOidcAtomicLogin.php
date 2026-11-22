<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use Throwable;

/**
 * Atomic tenant-OIDC callback: subject admission, identity mutation,
 * rotated browser session and both DB authentication ledgers are one unit.
 *
 * The outer transaction is the only commit owner. In PostgreSQL,
 * SiteScopedOidcAdmission locks site/member/domain/user rows before changing
 * UserInfo. In SQLite DatabaseTransaction::immediate holds the writer lock.
 *
 * Never make this available as a public API or background worker.
 */
final class SiteScopedOidcAtomicLogin
{
    public static function complete(
        PDO $pdo,array $profile,int $siteId,?int $linkUserId,
        string $host,string $previousSessionId,
        string $previousProvider,int $previousVersion
    ):array {
        try {
            return DatabaseTransaction::immediate(
                $pdo,
                static function() use(
                    $pdo,$profile,$siteId,$linkUserId,$host,
                    $previousSessionId,$previousProvider,$previousVersion
                ):array {
                    $user=SiteScopedOidcAdmission::resolve(
                        $pdo,$profile,$siteId,$linkUserId,$host,
                        $previousSessionId,$previousProvider,$previousVersion
                    );
                    // Auth::login recognizes this transaction's ownership
                    // and MUST NOT commit, open a nested transaction or fall
                    // back to a global-only login. A failure rolls everything
                    // (including the identity mutation) back.
                    Auth::login((int)$user['id'],'vazin_id');
                    return $user;
                }
            );
        } catch(Throwable $error) {
            // No browser may retain authentication after a failed atomic
            // callback. DatabaseTransaction owns the rollback.
            try { SessionSecurity::destroy(); } catch(Throwable) {}
            throw $error;
        }
    }
}
