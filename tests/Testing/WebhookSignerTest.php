<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Testing;

use Dev1\Pey\Testing\SignedWebhook;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Tests\Support\StandardWebhooksVectors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookSigner::class)]
#[CoversClass(SignedWebhook::class)]
final class WebhookSignerTest extends TestCase
{
    public function testTheSignerProducesHeadersTheVerifierAccepts(): void
    {
        $signed = WebhookSigner::sign(StandardWebhooksVectors::SECRET, StandardWebhooksVectors::PAYLOAD, StandardWebhooksVectors::MESSAGE_ID, StandardWebhooksVectors::TIMESTAMP);

        self::assertSame(StandardWebhooksVectors::SIGNATURE, $signed->headers['webhook-signature']);
        self::assertSame(StandardWebhooksVectors::MESSAGE_ID, $signed->id);
        self::assertSame((string) StandardWebhooksVectors::TIMESTAMP, $signed->headers['webhook-timestamp']);
    }

    public function testTheSignerDefaultsToARandomIdAndTheCurrentTime(): void
    {
        $first = WebhookSigner::sign(StandardWebhooksVectors::SECRET, '{}');
        $second = WebhookSigner::sign(StandardWebhooksVectors::SECRET, '{}');

        self::assertNotSame($first->id, $second->id);
        self::assertMatchesRegularExpression('/^msg_[0-9a-f]{24}$/D', $first->id);
        self::assertEqualsWithDelta(time(), (int) $first->headers['webhook-timestamp'], 5);
    }
}
