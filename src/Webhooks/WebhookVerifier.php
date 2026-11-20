<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Security\Secret;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use StandardWebhooks\Webhook;

/**
 * Verifies Pey webhooks (Standard Webhooks) and returns their typed event.
 *
 * Verification happens before the body is parsed:
 * 1. the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers
 *    are read (case-insensitively). Each must appear exactly once;
 * 2. the timestamp must be within the tolerance of the PSR-20 clock (default
 *    5 minutes, both directions), which limits replays;
 * 3. the expected `v1` signature over "id.timestamp.body" is computed with the
 *    official Standard Webhooks library and compared in constant time with
 *    every signature in the header, for every configured secret;
 * 4. only then is the body parsed into an event.
 *
 * Pass the raw body exactly as received: re-encoding parsed JSON breaks the
 * signature. Then deduplicate with VerifiedWebhook::$id, and confirm payments
 * through the API before fulfilling (see the event classes).
 *
 * The webhook secret is held in Secret objects and is never stored as a plain
 * string. The library's Webhook object (which keeps the decoded secret in a
 * plain property) is created per verification and discarded.
 */
final class WebhookVerifier
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private const MAX_TOLERANCE_SECONDS = 86_400;

    private const SECRET_PREFIX = 'whsec_';

    /**
     * @var non-empty-list<Secret>
     */
    private readonly array $secrets;

    /**
     * @param string|Secret|list<string|Secret> $secret           the webhook secret from the Pey portal (`whsec_…`).
     *                                                             During a secret rotation pass both, old and new.
     * @param int                               $toleranceSeconds how far the timestamp may be from now, in either direction
     *
     * @throws ConfigurationException
     */
    public function __construct(
        #[\SensitiveParameter]
        string|Secret|array $secret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ($toleranceSeconds < 1 || $toleranceSeconds > self::MAX_TOLERANCE_SECONDS) {
            throw ConfigurationException::because(\sprintf('The webhook tolerance must be between 1 and %d seconds.', self::MAX_TOLERANCE_SECONDS));
        }

        $secrets = [];
        foreach (\is_array($secret) ? $secret : [$secret] as $candidate) {
            $secrets[] = self::validateSecret($candidate);
        }

        if ($secrets === []) {
            throw ConfigurationException::because('At least one webhook secret is required.');
        }

        $this->secrets = $secrets;
    }

    /**
     * @param string                  $rawBody the request body exactly as received
     * @param array<array-key, mixed> $headers header name => value or list of values, for example
     *                                         getallheaders() or a PSR-7 getHeaders()
     *
     * @throws WebhookVerificationException when the request is not a valid, recent Pey webhook
     * @throws UnexpectedResponseException  when a verified body is not a valid event
     */
    public function verify(#[\SensitiveParameter] string $rawBody, array $headers): VerifiedWebhook
    {
        $id = self::header($headers, 'webhook-id');
        $timestampHeader = self::header($headers, 'webhook-timestamp');
        $signatureHeader = self::header($headers, 'webhook-signature');

        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $id) !== 1) {
            throw WebhookVerificationException::invalidHeader('webhook-id');
        }

        if (preg_match('/^[1-9]\d{0,11}$/D', $timestampHeader) !== 1) {
            throw WebhookVerificationException::invalidHeader('webhook-timestamp');
        }

        $timestamp = (int) $timestampHeader;
        $now = $this->clock->now()->getTimestamp();

        if ($timestamp < $now - $this->toleranceSeconds) {
            throw WebhookVerificationException::timestampTooOld();
        }

        if ($timestamp > $now + $this->toleranceSeconds) {
            throw WebhookVerificationException::timestampTooNew();
        }

        if (!$this->signatureMatches($id, $timestamp, $rawBody, $signatureHeader)) {
            throw WebhookVerificationException::invalidSignature();
        }

        // The body is authentic from here on; only now is it parsed.
        return new VerifiedWebhook($id, new \DateTimeImmutable('@' . $timestamp), EventFactory::fromVerifiedBody($rawBody));
    }

    /**
     * Verifies a PSR-7 server request, reading its body from the start.
     *
     * @throws WebhookVerificationException
     * @throws UnexpectedResponseException
     */
    public function verifyRequest(ServerRequestInterface $request): VerifiedWebhook
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $this->verify($body->getContents(), $request->getHeaders());
    }

    private function signatureMatches(string $id, int $timestamp, #[\SensitiveParameter] string $rawBody, string $signatureHeader): bool
    {
        $candidates = array_filter(explode(' ', $signatureHeader), static fn(string $candidate): bool => $candidate !== '');

        foreach ($this->secrets as $secret) {
            // Built per call and discarded: the library keeps the decoded secret in a plain property.
            $expected = (new Webhook($secret->reveal()))->sign($id, $timestamp, $rawBody);

            foreach ($candidates as $candidate) {
                // sign() is untyped in the library; it returns "v1,<base64>".
                if (\is_string($expected) && hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @throws WebhookVerificationException
     */
    private static function header(array $headers, string $name): string
    {
        $values = [];

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            foreach (\is_array($value) ? $value : [$value] as $item) {
                if (!\is_string($item)) {
                    throw WebhookVerificationException::invalidHeader($name);
                }
                $values[] = trim($item);
            }
        }

        if ($values === []) {
            throw WebhookVerificationException::missingHeader($name);
        }

        if (\count($values) > 1) {
            throw WebhookVerificationException::invalidHeader($name);
        }

        return $values[0];
    }

    /**
     * @throws ConfigurationException
     */
    private static function validateSecret(#[\SensitiveParameter] mixed $secret): Secret
    {
        $value = match (true) {
            $secret instanceof Secret => $secret->reveal(),
            \is_string($secret) => $secret,
            default => throw ConfigurationException::because('Webhook secrets must be strings or Secret instances.'),
        };

        $encoded = str_starts_with($value, self::SECRET_PREFIX) ? substr($value, \strlen(self::SECRET_PREFIX)) : $value;
        $decoded = preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $encoded) === 1 ? base64_decode($encoded, true) : false;

        if ($decoded === false || $decoded === '') {
            // The value is never echoed.
            throw ConfigurationException::because('The webhook secret must be the whsec_… value from the Pey portal (base64 after the prefix).');
        }

        return $secret instanceof Secret ? $secret : new Secret($value);
    }
}
