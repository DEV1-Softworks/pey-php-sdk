<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * CoDi expiration rules, in seconds.
 *
 * - QR: 0 means the QR never expires. Any other value must be at least 360
 *   (6 minutes).
 * - Push notification: at least 360. Pey's default is 360.
 */
final class Expiration
{
    public const NEVER = 0;

    public const MINIMUM_SECONDS = 360;

    public static function checkQr(int $seconds, string $field, Violations $violations): void
    {
        if ($seconds !== self::NEVER && $seconds < self::MINIMUM_SECONDS) {
            $violations->add($field, \sprintf('must be 0 (no expiration) or at least %d seconds.', self::MINIMUM_SECONDS));
        }
    }

    public static function checkPushNotification(int $seconds, string $field, Violations $violations): void
    {
        if ($seconds < self::MINIMUM_SECONDS) {
            $violations->add($field, \sprintf('must be at least %d seconds.', self::MINIMUM_SECONDS));
        }
    }
}
