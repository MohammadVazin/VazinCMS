<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class SiteBuilder
{
    public static function saveTemplate(PDO $pdo,string $key,string $label,string $type,array $doc,bool $system=false): int
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{1,188}$/',$key))throw new InvalidArgumentException('Invalid template key.');
        if(!in_array($type,['front-page','home','single','page','archive','taxonomy','search','404','part'],true))throw new InvalidArgumentException('Invalid template type.');
        $json=BlockEditor::encode($doc);$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare('INSERT INTO cms_site_templates(template_key,label,template_type,block_document,block_schema_version,is_system) VALUES(:key,:label,:type,:doc,1,:system) ON CONFLICT(template_key) DO UPDATE SET label=:label2,template_type=:type2,block_document=:doc2,block_schema_version=1,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$label,'type'=>$type,'doc'=>$json,'system'=>$system?1:0,'label2'=>$label,'type2'=>$type,'doc2'=>$json]);
        else $pdo->prepare('INSERT INTO cms_site_templates(template_key,label,template_type,block_document,block_schema_version,is_system) VALUES(:key,:label,:type,:doc,1,:system) ON CONFLICT(template_key) DO UPDATE SET label=EXCLUDED.label,template_type=EXCLUDED.template_type,block_document=EXCLUDED.block_document,block_schema_version=1,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$label,'type'=>$type,'doc'=>$json,'system'=>$system]);
        $q=$pdo->prepare('SELECT id FROM cms_site_templates WHERE template_key=:key');$q->execute(['key'=>$key]);return(int)$q->fetchColumn();
    }

    public static function resolveTemplate(PDO $pdo,string $context,array $data=[]): ?array
    {
        foreach(ThemeHierarchy::candidates($context,$data) as $key){$q=$pdo->prepare('SELECT * FROM cms_site_templates WHERE template_key=:key LIMIT 1');$q->execute(['key'=>$key]);if($row=$q->fetch())return$row;}return null;
    }
}
