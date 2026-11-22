<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Scoped reusable block and pattern repository. Only activated by
 * BlockRepository when all 24 baseline content tables have site_id and
 * the per-site unique migration for blocks/patterns has been verified.
 *
 * This is NOT a multi-site rollout switch: SiteIsolationGate stays in force.
 */
final class SiteScopedBlockRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if ($siteId < 1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_block_schema_not_ready');
        }
        $check=$pdo->prepare(
            "SELECT 1 FROM cms_sites WHERE id=:site AND status IN ('active','staging')"
        );
        $check->execute(['site'=>$siteId]);
        if (!$check->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
    }

    public function saveReusable(int $actorId,string $key,string $label,array $doc): int
    {
        $this->requireEditor($actorId);
        if(preg_match('/^[a-z0-9][a-z0-9-]{1,118}$/D',$key)!==1 ||
           mb_strlen(trim($label))<2) {
            throw new InvalidArgumentException('tenant_block_metadata_invalid');
        }
        $this->requireComposite('cms_reusable_blocks','block_key');
        $encoded=BlockEditor::encode($doc);
        $q=$this->pdo->prepare(
            'INSERT INTO cms_reusable_blocks(site_id,block_key,label,block_document,block_schema_version,created_by) ' .
            'VALUES(:site,:key,:label,:document,1,:actor) ' .
            'ON CONFLICT(site_id,block_key) DO UPDATE SET ' .
            'label=excluded.label,block_document=excluded.block_document,' .
            'block_schema_version=excluded.block_schema_version,updated_at=CURRENT_TIMESTAMP ' .
            'WHERE cms_reusable_blocks.site_id=excluded.site_id'
        );
        $q->execute([
            'site'=>$this->siteId,'key'=>$key,'label'=>trim($label),
            'document'=>$encoded,'actor'=>$actorId
        ]);
        if ($q->rowCount()!==1) throw new RuntimeException('tenant_block_write_conflict');
        $get=$this->pdo->prepare(
            'SELECT id FROM cms_reusable_blocks WHERE site_id=:site AND block_key=:key'
        );
        $get->execute(['site'=>$this->siteId,'key'=>$key]);
        $id=$get->fetchColumn();
        if (!$id)throw new RuntimeException('tenant_block_write_conflict');
        return (int)$id;
    }

    public function reusable(string $key): ?array
    {
        $get=$this->pdo->prepare(
            'SELECT * FROM cms_reusable_blocks WHERE site_id=:site AND block_key=:key LIMIT 1'
        );
        $get->execute(['site'=>$this->siteId,'key'=>$key]);
        $record=$get->fetch(PDO::FETCH_ASSOC);
        return is_array($record)?$record:null;
    }

    public function patterns(): array
    {
        $q=$this->pdo->prepare(
            'SELECT * FROM cms_block_patterns WHERE site_id=:site ' .
            'ORDER BY category,label,id'
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function seedPatterns(array $patterns): int
    {
        $this->requireComposite('cms_block_patterns','pattern_key');
        $q=$this->pdo->prepare(
            'INSERT INTO cms_block_patterns(site_id,pattern_key,label,category,block_document,block_schema_version,is_system) ' .
            'VALUES(:site,:key,:label,:category,:document,1,1) ' .
            'ON CONFLICT(site_id,pattern_key) DO NOTHING'
        );
        $created=0;
        foreach ($patterns as $pattern) {
            if (!is_array($pattern) || count($pattern)!==4) {
                throw new InvalidArgumentException('tenant_pattern_invalid');
            }
            [$key,$label,$category,$document]=$pattern;
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9-]{1,118}$/D',$key)!==1
                || !is_string($label) || mb_strlen($label)<2
                || !is_string($category) || !is_array($document)) {
                throw new InvalidArgumentException('tenant_pattern_invalid');
            }
            $q->execute([
                'site'=>$this->siteId,'key'=>$key,'label'=>$label,
                'category'=>$category,'document'=>BlockEditor::encode($document)
            ]);
            $created+=$q->rowCount();
        }
        return $created;
    }

    private function requireComposite(string $table,string $key): void
    {
        if (SiteUniqueKeys::target($this->pdo,$table,$key)!=='(site_id,'.$key.')') {
            throw new RuntimeException('tenant_block_uniqueness_not_ready');
        }
    }

    private function requireEditor(int $actorId): void
    {
        if ($actorId<1)throw new RuntimeException('tenant_access_denied');
        $query=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active' LIMIT 1"
        );
        $query->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        $role=$query->fetchColumn();
        if (!in_array($role,['owner','admin','editor'],true)) {
            throw new RuntimeException('tenant_access_denied');
        }
    }
}
