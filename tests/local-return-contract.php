<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('SESSION_SECURE=false');require$root.'/src/bootstrap.php';
$fallback='/admin';$bad=['//host/path','/\\host/path','/%2e%2e/admin','/%252e%252e/admin','/a/../b','/a/./b','/auth/vazin-id/start','/auth/vazin-id/callback','/login','/logout','/install','/admin/login',"/admin\nnext",'http://host/admin'];
foreach($bad as$value)if(VazinCMS\LocalReturn::path($value)!==$fallback)throw new RuntimeException('unsafe return accepted');
if(VazinCMS\LocalReturn::path('/admin/pages?edit=2')!=='/admin/pages?edit=2')throw new RuntimeException('valid return rejected');
echo"VazinCMS local-return contract: OK\n";
