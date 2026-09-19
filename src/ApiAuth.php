<?php
declare(strict_types=1);
namespace VazinCMS;

final class ApiAuth
{
    public static function authorize(string $scope): array
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
        if(!preg_match('/^Bearer\s+(vo_live_[A-Fa-f0-9]{48})$/',$header,$m)) self::fail(401,'invalid_token','کلید API معتبر ارسال نشده است.');
        $pdo=Database::connection();$hash=hash('sha256',$m[1]);
        $s=$pdo->prepare('SELECT * FROM api_tokens WHERE token_hash=:hash AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)');$s->execute(['hash'=>$hash]);$token=$s->fetch();
        if(!$token) self::fail(401,'invalid_token','کلید API نامعتبر، منقضی یا لغوشده است.');
        $scopes=json_decode((string)$token['scopes'],true);$scopes=is_array($scopes)?$scopes:[];
        if(!in_array($scope,$scopes,true)&&!in_array('*',$scopes,true)) self::fail(403,'insufficient_scope','این کلید مجوز لازم را ندارد.');
        $pdo->prepare('UPDATE api_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$token['id']]);
        return $token;
    }
    public static function respond(array $data,int $status=200): never
    { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
    private static function fail(int $status,string $code,string $message): never
    { self::respond(['ok'=>false,'error'=>['code'=>$code,'message'=>$message]],$status); }
}
