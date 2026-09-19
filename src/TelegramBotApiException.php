<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class TelegramBotApiException extends RuntimeException
{
    public function __construct(string $message, private int $telegramErrorCode = 0, private int $retryAfter = 0)
    {
        parent::__construct($message, $telegramErrorCode);
    }

    public function telegramErrorCode(): int { return $this->telegramErrorCode; }
    public function retryAfter(): int { return $this->retryAfter; }
}
