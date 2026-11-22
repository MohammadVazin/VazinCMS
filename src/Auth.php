<?php
declare(strict_types=1);
namespace VazinCMS;

final class Auth
{
    public static function user(): ?array
    {
        $now = time();
        $last = (int)($_SESSION['last_activity_at'] ?? 0);
        $authenticated = (int)($_SESSION['authenticated_at'] ?? 0);
        if (($last > 0 && $now - $last > 3600) || ($authenticated > 0 && $now - $authenticated > 43200)) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity_at'] = $now;
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id < 1) return null;
        if (VazinIdSettings::required()
            && !hash_equals('vazin_id', (string)($_SESSION['auth_provider'] ?? ''))) {
            self::logout();
            return null;
        }
        try {
            $stmt = Database::connection()->prepare("SELECT u.id,u.name,u.email,u.role,u.status,u.session_version,
                i.display_name AS identity_display_name,i.preferred_username AS identity_username,
                i.picture_url AS identity_picture_url,i.locale AS identity_locale,i.theme AS identity_theme,
                i.profile_updated_at AS identity_profile_updated_at,i.profile_source AS identity_profile_source,
                i.preferences_shared AS identity_preferences_shared
                FROM users u LEFT JOIN user_identities i ON i.user_id=u.id AND i.provider='vazin_id'
                WHERE u.id=:id AND u.status='active'");
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch() ?: null;
            if ($user === null || (int)($user['session_version'] ?? 1) !== (int)($_SESSION['session_version'] ?? 0)) {
                self::logout();
                return null;
            }
            $hash = hash('sha256', session_id());
            $session = Database::connection()->prepare('SELECT id FROM active_sessions WHERE user_id=:uid AND session_hash=:hash AND revoked_at IS NULL');
            $session->execute(['uid' => $id, 'hash' => $hash]);
            if (!$session->fetchColumn()) {
                self::logout();
                return null;
            }
            if ($now - (int)($_SESSION['session_touched_at'] ?? 0) >= 60) {
                Database::connection()->prepare('UPDATE active_sessions SET last_seen_at=CURRENT_TIMESTAMP WHERE user_id=:uid AND session_hash=:hash')->execute(['uid'=>$id,'hash'=>$hash]);
                $_SESSION['session_touched_at'] = $now;
            }
        } catch (\Throwable $e) {
            error_log('[VazinCMS auth] stale session or incomplete authentication schema: '.$e->getMessage());
            self::forgetBrowserSession();
            return null;
        }
        return $user;
    }

    public static function requireUser(array $roles = []): array
    {
        $user = self::user();
        if ($user === null) { header('Location: /login'); exit; }
        if ($roles !== [] && !in_array($user['role'], $roles, true)) {
            http_response_code(403); View::render('error', ['title' => 'دسترسی غیرمجاز', 'message' => 'اجازه انجام این کار را ندارید.']); exit;
        }
        return $user;
    }

    public static function login(int $id, string $provider = 'password'): void
    {
        if (!in_array($provider, ['vazin_id', 'password'], true)) {
            throw new \InvalidArgumentException('Unsupported authentication provider.');
        }
        if (VazinIdSettings::required() && $provider !== 'vazin_id') {
            throw new \RuntimeException('Local authentication is disabled in Vazin ID-only mode.');
        }
        // If an already authenticated staff member completes explicit
        // Vazin ID linking, the old OAuth-rotated session must be revoked
        // when the new authenticated session is issued. Never keep both.
        $previousUserId=(int)($_SESSION['user_id']??0);
        $previousSessionId=session_id();
        $sessionId = SessionSecurity::renewForAuthentication();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id=:id');
        $stmt->execute(['id'=>$id]);
        $version = (int)$stmt->fetchColumn();
        $scoped = SiteScopedRuntimePolicy::scopedMode($pdo);
        if ($scoped) {
            // A legacy global owner/login never implicitly receives a site
            // session. Host and explicit site membership must be verified
            // before writing either login ledger.
            SiteScopedSessionGuard::assertAdminAccess(
                $pdo,SiteContext::id(),$id,(string)($_SERVER['HTTP_HOST']??'')
            );
        }
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_provider'] = $provider;
        $_SESSION['session_version'] = $version;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['session_touched_at'] = time();
        // When an OIDC callback already owns DatabaseTransaction::immediate,
        // identity changes and active/site session ledgers MUST commit (or
        // roll back) together. Other ambient transactions are not trusted.
        $callbackOwned = DatabaseTransaction::owns($pdo);
        if (!$callbackOwned && $pdo->inTransaction()) {
            throw new \RuntimeException('login_must_own_authentication_transaction');
        }
        if ($callbackOwned && (!$scoped || $provider !== 'vazin_id')) {
            throw new \RuntimeException('login_callback_transaction_scope_denied');
        }
        if (!$callbackOwned) $pdo->beginTransaction();
        try {
            if($previousUserId>0 && $previousSessionId!=='' &&
               !hash_equals($previousSessionId,$sessionId)){
                if($scoped){
                    SiteScopedAdminSessionBinding::revoke(
                        $pdo,$previousUserId,$previousSessionId
                    );
                }
                $pdo->prepare(
                    'UPDATE active_sessions SET revoked_at=CURRENT_TIMESTAMP ' .
                    'WHERE user_id=:uid AND session_hash=:hash AND revoked_at IS NULL'
                )->execute([
                    'uid'=>$previousUserId,
                    'hash'=>hash('sha256',$previousSessionId)
                ]);
            }
            $pdo->prepare('INSERT INTO active_sessions(user_id,session_hash,ip_address,user_agent) VALUES(:uid,:hash,:ip,:agent)')->execute([
                'uid'=>$id,'hash'=>hash('sha256',$sessionId),
                'ip'=>Security::clientIp(),
                'agent'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)
            ]);
            if ($scoped) {
                SiteScopedAdminSessionBinding::bind(
                    $pdo,SiteContext::id(),$id,$sessionId,
                    (string)($_SERVER['HTTP_HOST']??''),$provider,$version
                );
            }
            if (!$callbackOwned) $pdo->commit();
        } catch (\Throwable $error) {
            // When the identity callback owns this transaction, only its
            // outer transaction manager may roll back, including raw SQLite
            // BEGIN IMMEDIATE which PDO::inTransaction() may not detect.
            if (!$callbackOwned && $pdo->inTransaction()) $pdo->rollBack();
            // Do not leave a browser cookie authenticated after a failed
            // site-bound ledger insert. No legacy fallback is allowed.
            try { SessionSecurity::destroy(); } catch (\Throwable) {}
            throw $error;
        }
    }

    public static function logout(): void
    {
        $id=(int)($_SESSION['user_id']??0);
        if($id>0&&session_id()!==''){
            try {
                $pdo=Database::connection();
                if(SiteScopedRuntimePolicy::scopedMode($pdo)) {
                    SiteScopedAdminSessionBinding::revoke($pdo,$id,session_id());
                }
            } catch(\Throwable) {
                // Deleting the PHP session remains mandatory, even when the
                // site ledger is missing. Active_sessions is also revoked.
            }
            try{Database::connection()->prepare('UPDATE active_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=:uid AND session_hash=:hash AND revoked_at IS NULL')->execute(['uid'=>$id,'hash'=>hash('sha256',session_id())]);}catch(\Throwable){}
        }
        SessionSecurity::destroy();
    }

    private static function forgetBrowserSession(): void
    {
        SessionSecurity::destroy();
    }
}
