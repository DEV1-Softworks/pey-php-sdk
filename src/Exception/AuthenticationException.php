<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 401: Pey rejected the API key.

 * TODO(pey-docs): Pey does not document any 401 response. Authentication is
 * handled by an API gateway authorizer whose error body is unknown, so the
 * ApiError of this exception is often of type UNKNOWN.
 */
final class AuthenticationException extends ApiException {}
