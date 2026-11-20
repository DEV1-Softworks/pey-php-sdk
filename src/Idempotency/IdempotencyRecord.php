<?php

declare(strict_types=1);

namespace Dev1\Pey\Idempotency;

/**
 * What the store holds for one idempotency key.
 *
 * - started: an attempt acquired the key and may be talking to Pey right now.
 * - completed: Pey created the resource; the response is kept for replay.
 * - unknown: an attempt may have reached Pey, but its outcome is unknown.
 *
 * @internal
 */
final readonly class IdempotencyRecord
{
    public const STARTED = 'started';

    public const COMPLETED = 'completed';

    public const UNKNOWN = 'unknown';

    private const VERSION = 1;

    /**
     * @param int                             $startedAt   Unix time the attempt acquired the key
     * @param string                          $fingerprint SHA-256 of the operation and its canonical request body
     * @param array<string, string|null>|null $response    the response fields, for completed records
     */
    private function __construct(
        public string $state,
        public int $startedAt,
        public string $fingerprint,
        public ?array $response,
    ) {}

    public static function started(string $fingerprint, int $startedAt): self
    {
        return new self(self::STARTED, $startedAt, $fingerprint, null);
    }

    /**
     * @param array<string, string|null> $response
     */
    public function completed(array $response): self
    {
        return new self(self::COMPLETED, $this->startedAt, $this->fingerprint, $response);
    }

    public function unknown(): self
    {
        return new self(self::UNKNOWN, $this->startedAt, $this->fingerprint, null);
    }

    public function toJson(): string
    {
        return json_encode([
            'v' => self::VERSION,
            'state' => $this->state,
            'startedAt' => $this->startedAt,
            'fingerprint' => $this->fingerprint,
            'response' => $this->response,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parses a stored record. Returns null for anything unexpected, which the
     * caller treats as "outcome unknown" (fail closed).
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (
            !\is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || !\in_array($data['state'] ?? null, [self::STARTED, self::COMPLETED, self::UNKNOWN], true)
            || !\is_int($data['startedAt'] ?? null)
            || !\is_string($data['fingerprint'] ?? null)
        ) {
            return null;
        }

        $response = $data['response'] ?? null;
        if ($data['state'] === self::COMPLETED) {
            $response = self::parseResponse($response);
            if ($response === null) {
                return null;
            }
        } elseif ($response !== null) {
            return null;
        }

        return new self($data['state'], $data['startedAt'], $data['fingerprint'], $response);
    }

    /**
     * @return array<string, string|null>|null
     */
    private static function parseResponse(mixed $response): ?array
    {
        if (!\is_array($response) || $response === []) {
            return null;
        }

        $fields = [];
        foreach ($response as $name => $value) {
            if (!\is_string($name) || ($value !== null && !\is_string($value))) {
                return null;
            }
            $fields[$name] = $value;
        }

        return $fields;
    }
}
