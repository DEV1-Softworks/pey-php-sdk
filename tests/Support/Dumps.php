<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

/**
 * Renders a value through every PHP mechanism that can expose object internals,
 * so tests can assert that a secret appears in none of them.
 */
final class Dumps
{
    public const MECHANISMS = [
        'var_dump',
        'print_r',
        'var_export',
        'debug_zval_dump',
        'json_encode',
        'serialize',
        'array_cast',
        'get_object_vars',
    ];

    /**
     * Data provider rows: one per mechanism.
     *
     * @return iterable<string, array{string}>
     */
    public static function mechanisms(): iterable
    {
        foreach (self::MECHANISMS as $mechanism) {
            yield $mechanism => [$mechanism];
        }
    }

    /**
     * Renders $value through one mechanism from MECHANISMS.
     */
    public static function via(string $mechanism, mixed $value): string
    {
        return match ($mechanism) {
            'var_dump' => self::capture(static function () use ($value): void {
                var_dump($value);
            }),
            'print_r' => print_r($value, true),
            'var_export' => var_export($value, true),
            'debug_zval_dump' => self::capture(static function () use ($value): void {
                debug_zval_dump($value);
            }),
            'json_encode' => (string) json_encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            'serialize' => self::serialized($value),
            'array_cast' => \is_object($value) ? print_r((array) $value, true) . var_export((array) $value, true) : '',
            'get_object_vars' => \is_object($value) ? print_r(get_object_vars($value), true) : '',
            default => throw new \InvalidArgumentException('Unknown dump mechanism ' . $mechanism),
        };
    }

    /**
     * Renders an exception's message, string form and the SDK frames of its
     * trace (with arguments), for itself and every previous exception.
     *
     * @return array<string, string>
     */
    public static function ofThrowable(\Throwable $throwable): array
    {
        $out = [];
        for ($current = $throwable, $i = 0; $current !== null; $current = $current->getPrevious(), ++$i) {
            $out["message#{$i}"] = $current->getMessage();
            $out["string#{$i}"] = (string) $current;
            $out["trace#{$i}"] = self::sdkTrace($current);
        }

        return $out;
    }

    /**
     * print_r() of the trace frames that belong to SDK code, arguments included.
     *
     * Frames from the test harness are excluded: PHPUnit passes the TestCase
     * (and therefore the mock HTTP client with its recorded, authenticated
     * requests) as an argument, which is test scaffolding, not SDK output.
     */
    public static function sdkTrace(\Throwable $throwable): string
    {
        $frames = array_filter(
            $throwable->getTrace(),
            static fn(array $frame): bool => isset($frame['class'])
                && str_starts_with($frame['class'], 'Dev1\\Pey\\')
                && !str_starts_with($frame['class'], 'Dev1\\Pey\\Tests\\'),
        );

        return print_r(array_values($frames), true);
    }

    /**
     * @param \Closure(): void $dump
     */
    private static function capture(\Closure $dump): string
    {
        ob_start();

        try {
            $dump();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private static function serialized(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            return 'serialize threw ' . $e::class . ': ' . $e->getMessage();
        }
    }
}
