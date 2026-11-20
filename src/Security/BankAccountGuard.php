<?php

declare(strict_types=1);

namespace Dev1\Pey\Security;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Validation\Clabe;

/**
 * Guards bank account registration, which is on by default.
 *
 * Registering a CLABE with Pey redirects incoming CoDi payments to that
 * account. Anyone who can make the application register an arbitrary CLABE
 * (a bug, a compromised admin panel, an injected parameter) could divert
 * money. The guard therefore allows a registration only when the CLABE is on
 * an explicit allowlist. An empty allowlist blocks every registration.
 *
 * There is deliberately no switch to disable the guard: to register a CLABE,
 * allowlist it.
 *
 * CLABE parameters are marked sensitive so full account numbers (personal
 * data) stay out of exception traces; messages show only the last 4 digits.
 */
final readonly class BankAccountGuard
{
    /**
     * @var array<string, true>
     */
    private array $allowed;

    /**
     * @param list<string> $allowedClabes 18-digit CLABEs with valid check digits
     *
     * @throws ConfigurationException when an entry is not a valid CLABE
     */
    public function __construct(array $allowedClabes)
    {
        $allowed = [];
        foreach ($allowedClabes as $index => $clabe) {
            if (!Clabe::isValid($clabe)) {
                throw ConfigurationException::because(\sprintf(
                    'allowedClabes[%d] (%s) is not a valid 18-digit CLABE with a correct check digit.',
                    $index,
                    Clabe::mask($clabe),
                ));
            }
            $allowed[$clabe] = true;
        }

        $this->allowed = $allowed;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->allowedClabes());
    }

    /**
     * False when the allowlist is empty, which means every registration is blocked.
     */
    public function allowsAnyRegistration(): bool
    {
        return $this->allowed !== [];
    }

    public function allows(#[\SensitiveParameter] string $clabe): bool
    {
        return isset($this->allowed[$clabe]);
    }

    /**
     * @throws BankAccountNotAllowedException when the CLABE is not allowlisted
     */
    public function assertAllowed(#[\SensitiveParameter] string $clabe): void
    {
        if ($this->allowed === []) {
            throw BankAccountNotAllowedException::registrationDisabled(Clabe::mask($clabe));
        }

        if (!$this->allows($clabe)) {
            throw BankAccountNotAllowedException::notAllowlisted(Clabe::mask($clabe));
        }
    }
}
