<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

use Dev1\Pey\Exception\ValidationException;

/**
 * Collects field-level validation errors so a request can report every problem
 * at once instead of failing on the first one.
 *
 * Messages describe the rule that failed and never echo the rejected value,
 * because values can be personal data (CLABEs, phone numbers, names).
 */
final class Violations
{
    /**
     * @var array<string, non-empty-list<string>>
     */
    private array $errors = [];

    public function add(string $field, string $message): self
    {
        $this->errors[$field][] = $message;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * @throws ValidationException when at least one error was collected
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw ValidationException::withErrors($this->errors);
        }
    }
}
