<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 403: the API key may not access this resource (for example, a payment
 * link created with another API key).
 */
final class ForbiddenException extends ApiException {}
