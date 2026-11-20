<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * Thrown when the SDK is configured with invalid values or cannot find the
 * HTTP components it needs. Messages never echo the API key.
 */
final class ConfigurationException extends PeyException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
