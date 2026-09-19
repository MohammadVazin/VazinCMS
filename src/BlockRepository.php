<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class BlockRepository
{
    public static function saveReusable(PDO $pdo,string $key,string $label,array $doc,int $userId): int
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{1,118}$/',$key)||mb_strlen(trim($label))<2)throw new InvalidArgumentException('Reusable block metadata invalid.');
        $json=BlockEditor::encode($doc);$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){
            $pdo->prepare('INSERT INTO cms_reusable_blocks(block_key,label,block_document,block_schema_version,created_by) VALUES(:key,:label,:doc,1,:uid) ON CONFLICT(block_key) DO UPDATE SET label=:label2,block_document=:doc2,block_schema_version=1,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$label,'doc'=>$json,'uid'=>$userId,'label2'=>$label,'doc2'=>$json]);
        }else{
            $pdo->prepare('INSERT INTO cms_reusable_blocks(block_key,label,block_document,block_schema_version,created_by) VALUES(:key,:label,:doc,1,:uid) ON CONFLICT(block_key) DO UPDATE SET label=EXCLUDED.label,block_document=EXCLUDED.block_document,block_schema_version=1,updated_at=CURRENT_TIMESTAMP')->execute(['key'=>$key,'label'=>$label,'doc'=>$json,'uid'=>$userId]);
        }
        $q=$pdo->prepare('SELECT id FROM cms_reusable_blocks WHERE block_key=:key');$q->execute(['key'=>$key]);return(int)$q->fetchColumn();
    }

    public static function patterns(PDO $pdo): array{return $pdo->query('SELECT * FROM cms_block_patterns ORDER BY category,label,id')->fetchAll();}
    public static function seedPatterns(PDO $pdo): void
    {
        $patterns=[
            ['hero-basic','Hero ساده','layout',['schema'=>1,'blocks'=>[['type'=>'heading','data'=>['level'=>2,'text'=>'عنوان اصلی']],['type'=>'paragraph','data'=>['text'=>'توضیح کوتاه این بخش']],['type'=>'button','data'=>['url'=>'/','label'=>'شروع کنید']]]]],
            ['article-intro','مقدمه مقاله','content',['schema'=>1,'blocks'=>[['type'=>'heading','data'=>['level'=>2,'text'=>'عنوان بخش']],['type'=>'paragraph','data'=>['text'=>'متن مقدمه را اینجا بنویسید.']]]]],
        ];
        foreach($patterns as [$key,$label,$category,$doc]){
            $json=BlockEditor::encode($doc);$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if($driver==='sqlite')$pdo->prepare('INSERT OR IGNORE INTO cms_block_patterns(pattern_key,label,category,block_document,block_schema_version,is_system) VALUES(:key,:label,:category,:doc,1,1)')->execute(compact('key','label','category')+['doc'=>$json]);
            else $pdo->prepare('INSERT INTO cms_block_patterns(pattern_key,label,category,block_document,block_schema_version,is_system) VALUES(:key,:label,:category,:doc,1,TRUE) ON CONFLICT(pattern_key) DO NOTHING')->execute(compact('key','label','category')+['doc'=>$json]);
        }
    }
}
