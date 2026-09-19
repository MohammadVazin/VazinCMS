<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use Throwable;

final class HookCollection
{
    /** @var array<string,list<array{module:string,handler:callable}>> */
    private array $handlers = [];

    public function on(string $event, string $module, callable $handler): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $event) !== 1) {
            throw new InvalidArgumentException('نام رویداد ماژول معتبر نیست.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $module) !== 1) {
            throw new InvalidArgumentException('شناسهٔ ماژول رویداد معتبر نیست.');
        }
        $this->handlers[$event][] = ['module' => $module, 'handler' => $handler];
    }

    /** @return list<array{module:string,ok:bool,error:?string}> */
    public function emit(string $event, array $payload = []): array
    {
        $results = [];
        foreach ($this->handlers[$event] ?? [] as $registered) {
            try {
                ($registered['handler'])($payload);
                $results[] = ['module' => $registered['module'], 'ok' => true, 'error' => null];
            } catch (Throwable $error) {
                $message = mb_substr($error->getMessage(), 0, 1000);
                error_log('[VazinCMS hook=' . $event . ' module=' . $registered['module'] . '] ' . $message);
                $results[] = ['module' => $registered['module'], 'ok' => false, 'error' => $message];
            }
        }
        return $results;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->handlers));
    }
}
