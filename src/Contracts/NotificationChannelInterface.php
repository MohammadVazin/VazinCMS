<?php
declare(strict_types=1);

namespace VazinCMS\Contracts;

interface NotificationChannelInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * @param array<string,mixed> $metadata
     */
    public function send(string $recipient, string $subject, string $message, array $metadata = []): bool;
}
