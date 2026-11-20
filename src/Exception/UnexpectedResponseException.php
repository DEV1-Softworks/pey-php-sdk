<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Http\ApiError;

/**
 * Pey answered with something the SDK cannot interpret: a success status with a
 * body that is not a JSON object, or an HTTP status the API does not document
 * (for example a redirect or an unexpected 4xx).
 */
final class UnexpectedResponseException extends PeyException
{
    public static function malformedBody(string $method, string $pathTemplate, int $httpStatus): self
    {
        return new self(
            \sprintf('Pey API returned HTTP %d for %s %s with a body that is not a JSON object.', $httpStatus, $method, $pathTemplate),
            $httpStatus,
        );
    }

    public static function unexpectedStatus(string $method, string $pathTemplate, int $httpStatus, ApiError $error): self
    {
        return new self(ApiException::describe($method, $pathTemplate, $httpStatus, $error), $httpStatus, $error);
    }

    /**
     * A field of a successful response is missing or has the wrong type. The
     * message names the field but never its value, which may be personal data.
     *
     * @param string $resource the response object, for example "Transaction"
     * @param string $path     the field path, for example "items[0].amount"
     * @param string $expected what the field should be, for example "a decimal amount"
     */
    public static function invalidField(string $resource, string $path, string $expected): self
    {
        return new self(\sprintf('Unexpected Pey response: %s field "%s" must be %s.', $resource, $path, $expected));
    }
}
