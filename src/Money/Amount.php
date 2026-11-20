<?php

declare(strict_types=1);

namespace Dev1\Pey\Money;

use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\JsonNumber;

/**
 * An amount of Mexican pesos (MXN), stored as integer centavos.
 *
 * Money is never a float in this SDK: an Amount is built only from a decimal
 * string or from integer centavos, and serializes as a decimal with exactly
 * two places. Extra decimals round half-up, as Pey does:
 * `Amount::fromString('10.005')` is 10.01, and `'10.0049'` is 10.00.
 *
 * Amounts are never negative. The limits of each endpoint (for example
 * "greater than 0 and at most 1,000,000" for push notifications) are checked
 * by the request that uses the amount, not here.
 */
final readonly class Amount implements \JsonSerializable, \Stringable
{
    /**
     * Up to 15 integer digits keeps every amount far below PHP_INT_MAX centavos.
     */
    private const PATTERN = '/^(0|[1-9]\d{0,14})(?:\.(\d+))?$/D';

    private function __construct(
        private int $centavos,
    ) {}

    /**
     * Parses a plain decimal string such as "150", "150.5" or "150.00".
     *
     * Exponents, thousands separators, signs, surrounding whitespace and
     * leading zeros are rejected.
     *
     * @param string $field the request field to report in validation errors
     *
     * @throws ValidationException when the string is not a non-negative decimal
     */
    public static function fromString(string $value, string $field = 'amount'): self
    {
        if (str_starts_with($value, '-')) {
            throw ValidationException::forField($field, 'must not be negative.');
        }

        if (preg_match(self::PATTERN, $value, $match) !== 1) {
            throw ValidationException::forField($field, 'must be a decimal number such as "150" or "150.00", without exponent, separators or spaces.');
        }

        $fraction = $match[2] ?? '';
        $centavos = (int) $match[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        // Half-up: only the third decimal decides (0.005 -> 0.01, 0.00499 -> 0.00).
        if (\strlen($fraction) > 2 && $fraction[2] >= '5') {
            ++$centavos;
        }

        return new self($centavos);
    }

    /**
     * @throws ValidationException when $centavos is negative
     */
    public static function fromCentavos(int $centavos, string $field = 'amount'): self
    {
        if ($centavos < 0) {
            throw ValidationException::forField($field, 'must not be negative.');
        }

        return new self($centavos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    /**
     * The amount with exactly two decimals, for example "1500.00".
     */
    public function toDecimalString(): string
    {
        return intdiv($this->centavos, 100) . '.' . str_pad((string) ($this->centavos % 100), 2, '0', \STR_PAD_LEFT);
    }

    /**
     * The amount as a JSON number literal for request bodies.
     *
     * @internal
     */
    public function toJsonNumber(): JsonNumber
    {
        return new JsonNumber($this->toDecimalString());
    }

    public function add(self $other): self
    {
        return new self($this->centavos + $other->centavos);
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    /**
     * @return -1|0|1
     */
    public function compareTo(self $other): int
    {
        return $this->centavos <=> $other->centavos;
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->centavos > $other->centavos;
    }

    public function isLessThan(self $other): bool
    {
        return $this->centavos < $other->centavos;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Serializes as a decimal string ("150.00"), never as a float.
     */
    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
