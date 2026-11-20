<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 5xx: Pey failed to process the request. For a POST, the outcome is
 * unknown: check the resource's state before trying again.
 */
class ServerException extends ApiException {}
