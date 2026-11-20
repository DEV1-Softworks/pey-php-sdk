<?php

declare(strict_types=1);

namespace Dev1\Pey;

/**
 * Version information for this SDK.
 *
 * This is an unofficial SDK. It is not affiliated with, endorsed by, or supported
 * by Pey or Banco de México.
 */
final class Version
{
    public const VERSION = '0.1.0-dev';

    /**
     * Sent as the User-Agent header so Pey can identify traffic from this SDK.
     */
    public const USER_AGENT = 'dev1-pey-php-sdk/' . self::VERSION . ' (+https://github.com/DEV1-Softworks/pey-sdk; unofficial)';
}
