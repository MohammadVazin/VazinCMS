<?php
declare(strict_types=1);
namespace VazinCMS;

interface ConnectorContract
{
    public function key(): string;
    public function label(): string;
    public function serviceType(): string;
    public function capabilities(): array;
    public function uiMeta(): array;
    public function healthPath(): ?string;
}
