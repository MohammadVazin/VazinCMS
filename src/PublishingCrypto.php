<?php
declare(strict_types=1);
namespace VazinCMS;
final class PublishingCrypto{
 private static function key():string{$raw=(string)getenv('APP_KEY');if($raw==='')throw new \RuntimeException('APP_KEY برای رمزگذاری اتصال شبکه‌ها تنظیم نشده است.');return hash('sha256',$raw,true);}
 public static function encrypt(string $value):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'vazincms-social-v1');if($cipher===false)throw new \RuntimeException('رمزگذاری ناموفق بود.');return base64_encode($iv.$tag.$cipher);}
 public static function decrypt(string $value):string{$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new \RuntimeException('Token رمزگذاری‌شده نامعتبر است.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'vazincms-social-v1');if($plain===false)throw new \RuntimeException('بازکردن Token ناموفق بود.');return$plain;}
}
