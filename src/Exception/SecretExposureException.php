<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * Thrown when code tries to serialize or clone an object that holds a secret.
 */
final class SecretExposureException extends PeyException
{
    public static function serialization(): self
    {
        return new self(
            'Objects holding Pey secrets cannot be serialized or unserialized. '
            . 'Rebuild the client from configuration where it is needed (for example inside a queued job) instead of serializing it.',
        );
    }

    public static function cloning(): self
    {
        return new self('Objects holding Pey secrets cannot be cloned.');
    }
}
