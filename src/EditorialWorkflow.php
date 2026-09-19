<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class EditorialWorkflow
{
    private const TRANSITIONS=[
        'draft'=>['in_review','published'],
        'in_review'=>['approved','rejected','draft'],
        'approved'=>['scheduled','published','draft'],
        'scheduled'=>['published','draft'],
        'rejected'=>['draft','in_review'],
        'published'=>['draft'],
    ];

    public static function transition(PDO $pdo,int $pageId,string $to,int $actorId,string $note=''): void
    {
        $q=$pdo->prepare('SELECT editorial_state FROM cms_pages WHERE id=:id');$q->execute(['id'=>$pageId]);$from=$q->fetchColumn();if($from===false)throw new RuntimeException('Content not found.');
        if(!in_array($to,self::TRANSITIONS[(string)$from]??[],true))throw new RuntimeException('Editorial transition not allowed.');
        $reviewed=in_array($to,['approved','rejected'],true);
        $pdo->prepare('UPDATE cms_pages SET editorial_state=:to,review_requested_at=CASE WHEN :to2=\'in_review\' THEN CURRENT_TIMESTAMP ELSE review_requested_at END,reviewed_at=CASE WHEN :reviewed=1 THEN CURRENT_TIMESTAMP ELSE reviewed_at END,reviewed_by=CASE WHEN :reviewed2=1 THEN :actor ELSE reviewed_by END,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['to'=>$to,'to2'=>$to,'reviewed'=>$reviewed?1:0,'reviewed2'=>$reviewed?1:0,'actor'=>$actorId,'id'=>$pageId]);
        $pdo->prepare('INSERT INTO cms_editorial_events(page_id,from_state,to_state,actor_id,note) VALUES(:page,:from,:to,:actor,:note)')->execute(['page'=>$pageId,'from'=>$from,'to'=>$to,'actor'=>$actorId,'note'=>mb_substr($note,0,1000)]);
    }

    public static function dueScheduled(PDO $pdo): array
    {
        return $pdo->query("SELECT id FROM cms_pages WHERE editorial_state='scheduled' AND published_at IS NOT NULL AND published_at<=CURRENT_TIMESTAMP AND trash_status='active'")->fetchAll(PDO::FETCH_COLUMN);
    }
}
