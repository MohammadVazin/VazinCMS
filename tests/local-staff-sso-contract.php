<?php
declare(strict_types=1);

putenv('SESSION_SECURE=false');
require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\LocalStaffAuthenticator;
use VazinCMS\VazinIdentityLink;

$assert = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('STAFF_SSO_CONTRACT_FAIL: ' . $reason);
};
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,email TEXT UNIQUE,password_hash TEXT,role TEXT,status TEXT)");
$pdo->exec("CREATE TABLE user_identities(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,provider TEXT NOT NULL,provider_subject TEXT NOT NULL,email TEXT,email_verified INTEGER NOT NULL DEFAULT 0,linked_at TEXT,last_login_at TEXT,display_name TEXT,display_name_source TEXT,preferred_username TEXT,picture_url TEXT,locale TEXT,theme TEXT,profile_updated_at INTEGER,profile_source TEXT,preferences_shared INTEGER,profile_revision INTEGER,UNIQUE(provider,provider_subject),UNIQUE(user_id,provider))");
$password = 'qa-Only-Temporary-Staff-Pass-2026';
$hash = password_hash($password, PASSWORD_DEFAULT);
$roles = [
    ['Owner','owner@cms.test','owner','active'],
    ['Admin','admin@cms.test','admin','active'],
    ['Support','support@cms.test','support','active'],
    ['Customer','customer@cms.test','client','active'],
    ['Disabled','disabled@cms.test','admin','suspended'],
];
$insert = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,?,?)');
foreach ($roles as [$name,$email,$role,$status]) {
    $insert->execute([$name,$email,$hash,$role,$status]);
}
foreach (['owner','admin','support'] as $i => $role) {
    $user = LocalStaffAuthenticator::verify($pdo, $roles[$i][1], $password);
    $assert($user !== null && $user['role'] === $role, 'approved role cannot authenticate: ' . $role);
}
$assert(LocalStaffAuthenticator::verify($pdo, 'ADMIN@CMS.TEST', $password)['role'] === 'admin', 'email normalization');
foreach (['customer@cms.test','disabled@cms.test','absent@cms.test','bad-address'] as $email) {
    $assert(LocalStaffAuthenticator::verify($pdo, $email, $password) === null, 'ineligible user entered admin: ' . $email);
}
$assert(LocalStaffAuthenticator::verify($pdo, 'admin@cms.test', 'incorrect') === null, 'wrong password permitted');

$profile = static fn(string $sub, string $email): array => [
    'sub' => $sub, 'email' => $email, 'email_verified' => true, 'name' => 'QA Staff',
];
$admin = VazinIdentityLink::resolve($pdo, $profile('QA-ADMIN-SUB', 'admin@cms.test'), 2, false);
$assert((int)$admin['id'] === 2 && $admin['role'] === 'admin', 'explicit admin link changed role');
$support = VazinIdentityLink::resolve($pdo, $profile('QA-SUPPORT-SUB', 'support@cms.test'), 3, false);
$assert((int)$support['id'] === 3 && $support['role'] === 'support', 'explicit support link changed role');
$same = VazinIdentityLink::resolve($pdo, $profile('QA-SUPPORT-SUB', 'newmail@cms.test'), null, false);
$assert((int)$same['id'] === 3 && $same['role'] === 'support', 'linked SSO login does not preserve role');
$thrown = false;
try {
    VazinIdentityLink::resolve($pdo, $profile('QA-FORGED-SUB', 'admin@cms.test'), null, true);
} catch (RuntimeException) {
    $thrown = true;
}
$assert($thrown, 'email collision auto-promoted a new Vazin ID account');
$assert((int)$pdo->query('SELECT COUNT(*) FROM user_identities')->fetchColumn() === 2, 'unexpected identity link');

$controller = file_get_contents(dirname(__DIR__).'/src/Controllers/AuthController.php');
$assert(str_contains($controller, 'LocalStaffAuthenticator::verify('), 'real login controller is not wired');
$assert(str_contains($controller, 'auth.local_staff_login'), 'staff audit event missing');
$assert(str_contains($controller, 'Auth::login((int)$user[\'id\'])'), 'session login binding missing');
$workspace = file_get_contents(dirname(__DIR__).'/src/Controllers/AdminWorkspaceController.php');
$assert(str_contains($workspace, "Auth::requireUser(['owner', 'admin', 'support'])"),
    'staff dashboard rejects support before SSO link');
$assert(str_contains($workspace, "'admin-support'"), 'support has no restricted landing');
$assert(str_contains(file_get_contents(dirname(__DIR__).'/views/login.php'), 'ورود با وزین آیدی'), 'passwordless label absent');

echo "Local staff and Vazin ID linking contract: PASS (owner/admin/support, client denied, collision denied, exact links, no role escalation)\n";
