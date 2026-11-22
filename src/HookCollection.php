<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use Throwable;

final class HookCollection
{
    /** @var array<string,list<array{module:string,priority:int,seq:int,handler:callable}>> */
    private array $handlers = [];

    /** @var array<string,list<array{module:string,priority:int,seq:int,handler:callable}>> */
    private array $filters = [];

    private int $sequence = 0;

    public function on(string $event, string $module, callable $handler, int $priority = 10): void
    {
        self::assertHookName($event);
        self::assertModuleKey($module);
        $this->handlers[$event][] = [
            'module' => $module,
            'priority' => max(-1000, min(1000, $priority)),
            'seq' => ++$this->sequence,
            'handler' => $handler,
        ];
    }

    public function addFilter(string $hook, string $module, callable $handler, int $priority = 10): void
    {
        self::assertHookName($hook);
        self::assertModuleKey($module);
        $this->filters[$hook][] = [
            'module' => $module,
            'priority' => max(-1000, min(1000, $priority)),
            'seq' => ++$this->sequence,
            'handler' => $handler,
        ];
    }

    /** @return list<array{module:string,ok:bool,error:?string,duration_us?:int}> */
    public function emit(string $event, array $payload = []): array
    {
        $list = $this->handlers[$event] ?? [];
        if ($list === []) {
            return [];
        }
        usort(
            $list,
            static fn(array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]
        );

        $results = [];
        foreach ($list as $registered) {
            $start = hrtime(true);
            try {
                ($registered['handler'])($payload);
                $results[] = [
                    'module' => $registered['module'],
                    'ok' => true,
                    'error' => null,
                    'duration_us' => (int) ((hrtime(true) - $start) / 1000),
                ];
            } catch (Throwable $error) {
                $message = mb_substr($error->getMessage(), 0, 1000);
                error_log('[VazinCMS hook=' . $event . ' module=' . $registered['module'] . '] ' . $message);
                $results[] = [
                    'module' => $registered['module'],
                    'ok' => false,
                    'error' => $message,
                    'duration_us' => (int) ((hrtime(true) - $start) / 1000),
                ];
            }
        }
        return $results;
    }

    /**
     * Applies priority-ordered module filters with strict fault & type isolation.
     * If a module filter throws an exception, the error is logged and the previous safe value is preserved.
     *
     * @param array<string,mixed> $context
     */
    public function applyFilters(string $hook, mixed $value, array $context = []): mixed
    {
        $list = $this->filters[$hook] ?? [];
        if ($list === []) {
            return $value;
        }
        usort(
            $list,
            static fn(array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]
        );

        $initialType = get_debug_type($value);
        $current = $value;
        foreach ($list as $registered) {
            try {
                $next = ($registered['handler'])($current, $context);
                if ($value !== null && get_debug_type($next) !== $initialType) {
                    error_log(sprintf(
                        '[VazinCMS filter=%s module=%s] Type mismatch ignored: expected %s, got %s',
                        $hook,
                        $registered['module'],
                        $initialType,
                        get_debug_type($next)
                    ));
                    continue;
                }
                $current = $next;
            } catch (Throwable $error) {
                $message = mb_substr($error->getMessage(), 0, 1000);
                error_log('[VazinCMS filter=' . $hook . ' module=' . $registered['module'] . '] ' . $message);
            }
        }
        return $current;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->handlers)) + array_sum(array_map('count', $this->filters));
    }

    private static function assertHookName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $name) !== 1) {
            throw new InvalidArgumentException('نام رویداد یا فیلتر ماژول معتبر نیست.');
        }
    }

    private static function assertModuleKey(string $module): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $module) !== 1) {
            throw new InvalidArgumentException('شناسهٔ ماژول معتبر نیست.');
        }
    }
}
