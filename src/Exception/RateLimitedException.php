<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 429: too many requests. retryAfter() returns the raw `Retry-After`
 * header when Pey sends one.
 *
 * TODO(pey-docs): Pey documents neither rate limits nor 429 responses, although
 * free accounts are known to have request limits.
 */
final class RateLimitedException extends ApiException {}
