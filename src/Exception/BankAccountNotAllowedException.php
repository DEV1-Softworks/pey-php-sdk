<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * BankAccountGuard refused to register a CLABE. Nothing was sent to Pey.
 *
 * Registering a CLABE redirects incoming CoDi payments to that account, so
 * registration requires the CLABE to be on the explicit allowlist in Config.
 * The message shows only the last 4 digits.
 */
final class BankAccountNotAllowedException extends PeyException
{
    private const ADVICE = 'Registering a CLABE redirects incoming CoDi payments to it. Add a CLABE to Config::$allowedClabes only after verifying that the account belongs to you.';

    private function __construct(
        string $message,
        private readonly string $maskedClabe,
    ) {
        parent::__construct($message);
    }

    public static function registrationDisabled(string $maskedClabe): self
    {
        return new self(
            \sprintf('Bank account registration is blocked: no CLABE is allowlisted (attempted %s). %s', $maskedClabe, self::ADVICE),
            $maskedClabe,
        );
    }

    public static function notAllowlisted(string $maskedClabe): self
    {
        return new self(
            \sprintf('Bank account registration is blocked: CLABE %s is not on the allowlist. %s', $maskedClabe, self::ADVICE),
            $maskedClabe,
        );
    }

    /**
     * The attempted CLABE with all but its last 4 digits masked.
     */
    public function maskedClabe(): string
    {
        return $this->maskedClabe;
    }
}
