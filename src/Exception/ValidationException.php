<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Http\ApiError;

/**
 * Thrown when a request is invalid.
 *
 * It covers both cases:
 * - local validation, before anything is sent: errors() holds field-level
 *   messages and httpStatus() is null;
 * - HTTP 400 from Pey: httpStatus() is 400, apiError() holds Pey's error, and
 *   errors() holds a single `request` entry with Pey's message, because Pey does
 *   not report which field failed.
 */
final class ValidationException extends PeyException
{
    /**
     * @param array<string, non-empty-list<string>> $errors
     */
    private function __construct(
        string $message,
        private readonly array $errors,
        ?int $httpStatus = null,
        ?ApiError $apiError = null,
    ) {
        parent::__construct($message, $httpStatus, $apiError);
    }

    /**
     * @param array<string, non-empty-list<string>> $errors field name => messages describing the rule (never the value)
     */
    public static function withErrors(array $errors): self
    {
        if ($errors === []) {
            throw new \LogicException('A ValidationException needs at least one error.');
        }

        $parts = [];
        foreach ($errors as $field => $messages) {
            $parts[] = $field . ': ' . implode('; ', $messages);
        }

        return new self('Invalid Pey request. ' . implode(' | ', $parts), $errors);
    }

    public static function forField(string $field, string $message): self
    {
        return self::withErrors([$field => [$message]]);
    }

    public static function fromApiResponse(string $method, string $pathTemplate, ApiError $error): self
    {
        return new self(
            ApiException::describe($method, $pathTemplate, 400, $error),
            ['request' => [$error->message !== '' ? $error->message : $error->type]],
            400,
            $error,
        );
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * True when Pey rejected the request (HTTP 400); false when it was rejected
     * locally and never sent.
     */
    public function wasRejectedByPey(): bool
    {
        return $this->httpStatus() !== null;
    }
}
