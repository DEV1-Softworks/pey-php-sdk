<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Webhooks\VerificationFailure;

/**
 * A webhook request failed verification. It did not provably come from Pey
 * within the allowed time window, so its body was not parsed. Respond with a
 * 4xx status.
 *
 * Messages never contain header values, the body or the secret.
 */
final class WebhookVerificationException extends PeyException
{
    private function __construct(
        string $message,
        private readonly VerificationFailure $failure,
    ) {
        parent::__construct('Webhook verification failed: ' . $message);
    }

    public static function missingHeader(string $header): self
    {
        return new self(\sprintf('the %s header is missing.', $header), VerificationFailure::MissingHeader);
    }

    public static function invalidHeader(string $header): self
    {
        return new self(\sprintf('the %s header is malformed or repeated.', $header), VerificationFailure::InvalidHeader);
    }

    public static function timestampTooOld(): self
    {
        return new self('the timestamp is older than the allowed tolerance (possible replay).', VerificationFailure::TimestampTooOld);
    }

    public static function timestampTooNew(): self
    {
        return new self('the timestamp is too far in the future (check the server clock).', VerificationFailure::TimestampTooNew);
    }

    public static function invalidSignature(): self
    {
        return new self('no signature matches the webhook secret.', VerificationFailure::InvalidSignature);
    }

    public function failure(): VerificationFailure
    {
        return $this->failure;
    }
}
