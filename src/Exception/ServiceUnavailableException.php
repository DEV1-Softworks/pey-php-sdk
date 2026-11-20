<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 503: the endpoint is temporarily unavailable (maintenance). Pey uses
 * the error types SERVICE_UNAVAILABLE and FLAGS_UNAVAILABLE.
 */
final class ServiceUnavailableException extends ServerException {}
