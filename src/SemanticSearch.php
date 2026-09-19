<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class SemanticSearch
{
    public static function upsert(PDO $pdo,int $siteId,string $entityType,int $entityId,string $locale,string $contentHash,array $embedding,array $metadata=[]): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$emb=json_encode(array_values(array_map('floatval',$embedding)),JSON_UNESCAPED_SLASHES);$meta=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sql=$driver==='sqlite'?'INSERT INTO cms_semantic_documents(site_id,entity_type,entity_id,locale,content_hash,embedding_json,metadata_json) VALUES(:site,:type,:id,:locale,:hash,:embedding,:meta) ON CONFLICT(site_id,entity_type,entity_id,locale) DO UPDATE SET content_hash=:hash2,embedding_json=:embedding2,metadata_json=:meta2,updated_at=CURRENT_TIMESTAMP':'INSERT INTO cms_semantic_documents(site_id,entity_type,entity_id,locale,content_hash,embedding_json,metadata_json) VALUES(:site,:type,:id,:locale,:hash,:embedding,:meta) ON CONFLICT(site_id,entity_type,entity_id,locale) DO UPDATE SET content_hash=EXCLUDED.content_hash,embedding_json=EXCLUDED.embedding_json,metadata_json=EXCLUDED.metadata_json,updated_at=CURRENT_TIMESTAMP';
        $args=['site'=>$siteId,'type'=>$entityType,'id'=>$entityId,'locale'=>$locale,'hash'=>$contentHash,'embedding'=>$emb,'meta'=>$meta];if($driver==='sqlite')$args+=['hash2'=>$contentHash,'embedding2'=>$emb,'meta2'=>$meta];$pdo->prepare($sql)->execute($args);
    }

    public static function search(PDO $pdo,int $siteId,array $queryVector,string $locale='',int $limit=20): array
    {
        $sql='SELECT * FROM cms_semantic_documents WHERE site_id=:site'.($locale!==''?' AND locale=:locale':'');$q=$pdo->prepare($sql);$args=['site'=>$siteId];if($locale!=='')$args['locale']=$locale;$q->execute($args);$rows=[];foreach($q->fetchAll() as$r){$vec=json_decode((string)$r['embedding_json'],true);if(!is_array($vec)||count($vec)!==count($queryVector))continue;$r['score']=self::cosine($queryVector,$vec);$r['metadata']=json_decode((string)$r['metadata_json'],true)?:[];$rows[]=$r;}usort($rows,fn($a,$b)=>$b['score']<=>$a['score']);return array_slice($rows,0,max(1,min(100,$limit)));
    }
    private static function cosine(array $a,array $b): float
    {
        $dot=0.0;$na=0.0;$nb=0.0;foreach($a as$i=>$v){$x=(float)$v;$y=(float)($b[$i]??0);$dot+=$x*$y;$na+=$x*$x;$nb+=$y*$y;}if($na<=0||$nb<=0)return 0.0;return $dot/(sqrt($na)*sqrt($nb));
    }
}
