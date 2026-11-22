<?php
declare(strict_types=1);

// Browsers may request their default icons concurrently with the first
// login page, before a session cookie exists. A missing static icon must
// NOT start a second PHP session or send a Set-Cookie header which replaces
// the login form's session and invalidates its genuine CSRF token.
// This path returns no site/user data. Every dynamic CMS route still goes
// through the unchanged bootstrap, authentication and SiteIsolationGate.
$iconPath=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
$iconMethod=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(in_array($iconMethod,['GET','HEAD'],true) &&
   in_array($iconPath,[
       '/favicon.ico','/apple-touch-icon.png','/apple-touch-icon-precomposed.png'
   ],true) && !is_file(__DIR__.$iconPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: 0');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    return;
}
require dirname(__DIR__) . '/src/bootstrap.php';
(new VazinCMS\App())->run();
