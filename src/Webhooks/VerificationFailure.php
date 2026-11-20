<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks;

/**
 * Why a webhook was rejected. Respond with a 4xx status and do not process it.
 */
enum VerificationFailure: string
{
    case MissingHeader = 'missing_header';
    case InvalidHeader = 'invalid_header';
    case TimestampTooOld = 'timestamp_too_old';
    case TimestampTooNew = 'timestamp_too_new';
    case InvalidSignature = 'invalid_signature';
}
