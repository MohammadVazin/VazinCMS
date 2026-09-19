<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO;
final class MarketplaceCatalog
{
    public static function upsertPackage(PDO $pdo,array $row): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql=$driver==='sqlite'?'INSERT INTO marketplace_packages(extension_key,version,publisher_id,package_type,catalog_meta_json,checksum,signature,license_product_key,status) VALUES(:extension,:version,:publisher,:type,:meta,:checksum,:signature,:license,:status) ON CONFLICT(extension_key,version) DO UPDATE SET publisher_id=:publisher2,package_type=:type2,catalog_meta_json=:meta2,checksum=:checksum2,signature=:signature2,license_product_key=:license2,status=:status2':'INSERT INTO marketplace_packages(extension_key,version,publisher_id,package_type,catalog_meta_json,checksum,signature,license_product_key,status) VALUES(:extension,:version,:publisher,:type,:meta,:checksum,:signature,:license,:status) ON CONFLICT(extension_key,version) DO UPDATE SET publisher_id=EXCLUDED.publisher_id,package_type=EXCLUDED.package_type,catalog_meta_json=EXCLUDED.catalog_meta_json,checksum=EXCLUDED.checksum,signature=EXCLUDED.signature,license_product_key=EXCLUDED.license_product_key,status=EXCLUDED.status';
        $args=['extension'=>$row['extension_key'],'version'=>$row['version'],'publisher'=>$row['publisher_id']??null,'type'=>$row['package_type'],'meta'=>json_encode($row['meta']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'checksum'=>$row['checksum'],'signature'=>$row['signature']??'','license'=>$row['license_product_key']??'','status'=>$row['status']??'active'];
        if($driver==='sqlite')$args+=['publisher2'=>$args['publisher'],'type2'=>$args['type'],'meta2'=>$args['meta'],'checksum2'=>$args['checksum'],'signature2'=>$args['signature'],'license2'=>$args['license'],'status2'=>$args['status']];$pdo->prepare($sql)->execute($args);
    }
    public static function feed(PDO $pdo): array{return $pdo->query("SELECT p.extension_key,p.version,p.package_type,p.catalog_meta_json,p.checksum,p.license_product_key,p.status,pub.publisher_key,pub.name publisher_name,pub.trust_status FROM marketplace_packages p LEFT JOIN marketplace_publishers pub ON pub.id=p.publisher_id WHERE p.status<>'hidden' ORDER BY p.extension_key,p.version DESC")->fetchAll();}
}
