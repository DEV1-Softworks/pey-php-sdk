<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function recordsAt(string $level): array
    {
        return array_values(array_filter($this->records, static fn(array $record): bool => $record['level'] === $level));
    }
}
