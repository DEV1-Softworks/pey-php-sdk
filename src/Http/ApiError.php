<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * The error Pey reports in a non-2xx response body:
 * `{"error": {"message": "…", "type": "INVALID_AMOUNT", "requestId": "…"}}`.
 *
 * Values are sanitized before they reach exception messages and logs: control
 * characters are removed and long values are truncated.
 */
final readonly class ApiError
{
    /**
     * Used when the body does not carry a recognizable error type.
     */
    public const UNKNOWN_TYPE = 'UNKNOWN';

    private const MAX_TYPE_LENGTH = 100;

    private const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        public string $type,
        public string $message,
        public ?string $requestId,
    ) {}

    /**
     * Parses a response body defensively. It never throws: an unparseable body
     * becomes an error of type UNKNOWN.
     */
    public static function fromResponseBody(string $body): self
    {
        try {
            $data = Json::decode($body);
        } catch (\JsonException) {
            return new self(self::UNKNOWN_TYPE, '', null);
        }

        $error = $data['error'] ?? null;

        if (\is_array($error)) {
            $type = self::clean($error['type'] ?? null, self::MAX_TYPE_LENGTH);
            $requestId = self::clean($error['requestId'] ?? null, self::MAX_TYPE_LENGTH);

            return new self(
                $type !== '' ? $type : self::UNKNOWN_TYPE,
                self::clean($error['message'] ?? null, self::MAX_MESSAGE_LENGTH),
                $requestId !== '' ? $requestId : null,
            );
        }

        // TODO(pey-docs): bodies outside the documented error format (for example
        // from the API gateway authorizer on 401/403) are undocumented. Keep a
        // top-level "message" if there is one.
        return new self(self::UNKNOWN_TYPE, self::clean($data['message'] ?? null, self::MAX_MESSAGE_LENGTH), null);
    }

    private static function clean(mixed $value, int $maxLength): string
    {
        if (!\is_string($value) || preg_match('//u', $value) !== 1) {
            return '';
        }

        $singleLine = trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value));

        // Split into characters (not bytes) so truncation never breaks UTF-8.
        $characters = preg_split('//u', $singleLine, -1, \PREG_SPLIT_NO_EMPTY);

        if ($characters === false || \count($characters) <= $maxLength) {
            return $singleLine;
        }

        return implode('', \array_slice($characters, 0, $maxLength)) . '…';
    }
}
