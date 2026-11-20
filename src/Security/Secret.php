<?php

declare(strict_types=1);

namespace Dev1\Pey\Security;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\SecretExposureException;

/**
 * Holds a credential (the Pey API key or a webhook secret) without exposing it.
 *
 * The value is not stored in a property of this object. It lives in a private
 * static WeakMap keyed by the instance, so var_dump(), print_r(), var_export(),
 * debug_zval_dump(), (array) casts, get_object_vars() and json_encode() have
 * nothing to print, and the entry is released when the instance is destroyed.
 * Serialization, unserialization and cloning are refused.
 */
final class Secret implements \JsonSerializable, \Stringable
{
    public const REDACTED = '[REDACTED]';

    /**
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $values = null;

    /**
     * @throws ConfigurationException when the value is empty
     */
    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw ConfigurationException::because('A secret must not be empty.');
        }

        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * Returns the raw value. Call it only at the point where the value must be
     * sent (for example when building the Authorization header), and never
     * store, log or return the result.
     */
    public function reveal(): string
    {
        // The fallback is unreachable for instances built through the constructor.
        return self::$values[$this] ?? throw new \LogicException('The secret value is not available.');
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<never>
     *
     * @throws SecretExposureException always
     */
    public function __serialize(): array
    {
        throw SecretExposureException::serialization();
    }

    /**
     * @param array<mixed> $data
     *
     * @throws SecretExposureException always
     */
    public function __unserialize(array $data): void
    {
        throw SecretExposureException::serialization();
    }

    public function __clone(): void
    {
        throw SecretExposureException::cloning();
    }
}
