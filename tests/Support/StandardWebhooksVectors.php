<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use StandardWebhooks\Webhook;

/**
 * Webhook secrets and signatures for tests. These are not real credentials.
 *
 * The vector below is published by the Standard Webhooks specification. Its
 * signature was reproduced independently with Python's hmac module before it
 * was added here, so the signature tests do not rely only on the library
 * agreeing with itself.
 */
final class StandardWebhooksVectors
{
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    public const MESSAGE_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    public const TIMESTAMP = 1614265330;

    public const PAYLOAD = '{"test": 2432232314}';

    public const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    /**
     * A second, unrelated test secret (base64 of "another-test-secret-for-the-pey-sdk").
     */
    public const OTHER_SECRET = 'whsec_YW5vdGhlci10ZXN0LXNlY3JldC1mb3ItdGhlLXBleS1zZGs=';

    /**
     * Headers signed with $secret, as Pey would send them.
     *
     * @return array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}
     */
    public static function headers(string $secret, string $id, int $timestamp, string $body): array
    {
        $signature = (new Webhook($secret))->sign($id, $timestamp, $body);
        if (!\is_string($signature)) {
            throw new \LogicException('Unexpected signature type.');
        }

        return ['webhook-id' => $id, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => $signature];
    }
}
