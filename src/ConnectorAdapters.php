<?php
declare(strict_types=1);
namespace VazinCMS;

final class ConnectorAdapter implements ConnectorContract
{
    public function __construct(private string $key,private string $label,private string $type,private array $caps,private array $ui,private ?string $health=null){}
    public function key():string{return $this->key;}
    public function label():string{return $this->label;}
    public function serviceType():string{return $this->type;}
    public function capabilities():array{return $this->caps;}
    public function uiMeta():array{return $this->ui;}
    public function healthPath():?string{return $this->health;}
}

final class ConnectorAdapters
{
    public static function all(): array
    {
        return [
            new ConnectorAdapter('vazin-id','Vazin ID','identity',['oauth','profile','session'],['icon'=>'id','category'=>'identity'],'/health'),
            new ConnectorAdapter('vazinpay','VazinPay','payments',['wallet','invoice','payment','fx'],['icon'=>'wallet','category'=>'finance'],'/health'),
            new ConnectorAdapter('vazin-number','Vazin Number','telecom',['numbers','orders','sms'],['icon'=>'phone','category'=>'service'],'/health'),
            new ConnectorAdapter('vazin-ai','Vazin AI','ai',['chat','models','usage'],['icon'=>'spark','category'=>'ai'],'/health'),
            new ConnectorAdapter('vazin-travel','Vazin Travel Engine','travel',['search','quote','book','issue','status'],['icon'=>'plane','category'=>'travel'],'/health'),
            new ConnectorAdapter('learn-writer','Vazin Learn Writer','authoring',['draft','guides','mcp','oauth'],['icon'=>'book','category'=>'content'],'/healthz'),
        ];
    }
}
