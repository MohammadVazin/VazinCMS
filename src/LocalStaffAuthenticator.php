<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;

/**
 * Local bootstrap/recovery for pre-approved CMS staff only.
 *
 * A new Vazin ID user is NEVER promoted by signing in here. An administrator
 * must first create the local staff account and assign its role. That user
 * can then explicitly link Vazin ID while authenticated.
 */
final class LocalStaffAuthenticator
{
    private const DUMMY_HASH = '$2y$12$IF/zfSvAyk7V1lJNeE4izuWizTbemViFklcpFPt7z5p83xR4vVafG';

    public static function verify(PDO $pdo, string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $isValidEmail = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL);
        $user = null;

        if ($isValidEmail) {
            $statement = $pdo->prepare(
                "SELECT id,name,email,role,status,password_hash FROM users " .
                "WHERE email=:email AND status='active' AND role IN ('owner','admin','support') LIMIT 1"
            );
            $statement->execute(['email' => $email]);
            $found = $statement->fetch(PDO::FETCH_ASSOC);
            $user = is_array($found) ? $found : null;
        }

        // Keep similar work for unknown emails/roles instead of revealing
        // membership through fast failure. The caller handles rate limiting.
        $hash = $user !== null ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $validPassword = password_verify($password, $hash);
        return ($user !== null && $validPassword) ? $user : null;
    }
}
