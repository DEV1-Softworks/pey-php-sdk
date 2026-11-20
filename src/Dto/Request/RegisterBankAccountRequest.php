<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Request;

use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Violations;

/**
 * Body of `POST /bank-account`.
 *
 * Registering a CLABE redirects incoming CoDi money to it. The SDK sends this
 * request only for CLABEs on the configured allowlist (BankAccountGuard).
 */
final readonly class RegisterBankAccountRequest implements RequestBody
{
    /**
     * @param string $alias friendly name, 1 to 255 characters
     * @param string $name  full legal name of the holder, 1 to 255 characters
     * @param string $clabe 18 digits with a valid check digit
     *
     * @throws ValidationException listing every invalid field
     */
    public function __construct(
        public string $alias,
        public string $name,
        public string $clabe,
        public Entity $entity,
    ) {
        $violations = new Violations();
        Text::checkLength($alias, 1, 255, 'alias', $violations);
        Text::checkLength($name, 1, 255, 'name', $violations);
        Clabe::check($clabe, 'clabe', $violations);
        if ($entity === Entity::Unknown) {
            $violations->add('entity', 'must be BUSINESS or PERSON.');
        }
        $violations->throwIfAny();
    }

    public function toBody(): array
    {
        return [
            'alias' => $this->alias,
            'name' => $this->name,
            'clabe' => $this->clabe,
            'entity' => $this->entity->value,
        ];
    }
}
