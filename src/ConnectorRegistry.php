<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class ConnectorRegistry
{
    public static function register(PDO $pdo,ConnectorContract $adapter,string $baseUrl='',string $authType='none'): int
    {
        $key=$adapter->key();if(!preg_match('/^[a-z0-9][a-z0-9-]{2,118}$/',$key))throw new InvalidArgumentException('Invalid connector key.');
        if($baseUrl!==''&&(!filter_var($baseUrl,FILTER_VALIDATE_URL)||!str_starts_with($baseUrl,'https://')))throw new InvalidArgumentException('Connector base URL must be HTTPS.');
        $caps=json_encode(array_values(array_unique($adapter->capabilities())),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ui=json_encode($adapter->uiMeta(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare('INSERT INTO service_connectors(connector_key,label,service_type,base_url,auth_type,capabilities_json,ui_meta_json,status) VALUES(:key,:label,:type,:url,:auth,:caps,:ui,\'disabled\') ON CONFLICT(connector_key) DO UPDATE SET label=:label2,service_type=:type2,base_url=:url2,auth_type=:auth2,capabilities_json=:caps2,ui_meta_json=:ui2,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$adapter->label(),'type'=>$adapter->serviceType(),'url'=>$baseUrl?:null,'auth'=>$authType,'caps'=>$caps,'ui'=>$ui,'label2'=>$adapter->label(),'type2'=>$adapter->serviceType(),'url2'=>$baseUrl?:null,'auth2'=>$authType,'caps2'=>$caps,'ui2'=>$ui]);
        else $pdo->prepare('INSERT INTO service_connectors(connector_key,label,service_type,base_url,auth_type,capabilities_json,ui_meta_json,status) VALUES(:key,:label,:type,:url,:auth,:caps,:ui,\'disabled\') ON CONFLICT(connector_key) DO UPDATE SET label=EXCLUDED.label,service_type=EXCLUDED.service_type,base_url=EXCLUDED.base_url,auth_type=EXCLUDED.auth_type,capabilities_json=EXCLUDED.capabilities_json,ui_meta_json=EXCLUDED.ui_meta_json,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$adapter->label(),'type'=>$adapter->serviceType(),'url'=>$baseUrl?:null,'auth'=>$authType,'caps'=>$caps,'ui'=>$ui]);
        $q=$pdo->prepare('SELECT id FROM service_connectors WHERE connector_key=:key');$q->execute(['key'=>$key]);return(int)$q->fetchColumn();
    }
    public static function setCredentials(PDO $pdo,int $connectorId,array $credentials): void
    {
        $sealed=SecretStore::seal(json_encode($credentials,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'connector.credentials.'.$connectorId);
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare('INSERT INTO service_connector_credentials(connector_id,credentials_sealed) VALUES(:id,:sealed) ON CONFLICT(connector_id) DO UPDATE SET credentials_sealed=:sealed2,updated_at=CURRENT_TIMESTAMP')->execute(['id'=>$connectorId,'sealed'=>$sealed,'sealed2'=>$sealed]);
        else $pdo->prepare('INSERT INTO service_connector_credentials(connector_id,credentials_sealed) VALUES(:id,:sealed) ON CONFLICT(connector_id) DO UPDATE SET credentials_sealed=EXCLUDED.credentials_sealed,updated_at=CURRENT_TIMESTAMP')->execute(['id'=>$connectorId,'sealed'=>$sealed]);
    }

    public static function credentials(PDO $pdo,int $connectorId): array
    {
        $q=$pdo->prepare('SELECT credentials_sealed FROM service_connector_credentials WHERE connector_id=:id');$q->execute(['id'=>$connectorId]);$sealed=$q->fetchColumn();if($sealed===false)return[];$decoded=json_decode(SecretStore::open((string)$sealed,'connector.credentials.'.$connectorId),true);return is_array($decoded)?$decoded:[];
    }

    public static function all(PDO $pdo): array{return $pdo->query('SELECT * FROM service_connectors ORDER BY connector_key')->fetchAll();}
}
