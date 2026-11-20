<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * A JSON number written verbatim from its decimal string form.
 *
 * Pey types amounts as JSON numbers. Encoding them through PHP floats would
 * break the "money is never a float" rule, so money values are wrapped in a
 * JsonNumber and Json::encode() emits the literal as-is (for example `150.00`).
 *
 * @internal
 */
final readonly class JsonNumber
{
    private const PATTERN = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/';

    public string $literal;

    public function __construct(string $literal)
    {
        if (preg_match(self::PATTERN, $literal) !== 1) {
            throw new \InvalidArgumentException('A JSON number literal must be a plain decimal without exponent, for example "150.00".');
        }

        $this->literal = $literal;
    }
}
