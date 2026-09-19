<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class TelegramAssistantApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }
}
