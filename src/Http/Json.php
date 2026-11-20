<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * JSON encoding and decoding without floating point.
 *
 * - encode() writes JsonNumber values verbatim, so amounts are sent as exact
 *   JSON numbers.
 * - decode() returns every JSON number as its original decimal string (for
 *   example `"118.50"`, `"1704067200"`), so amounts never pass through a float.
 *   DTOs convert those strings to `int` or `Amount` explicitly.
 *
 * @internal
 */
final class Json
{
    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    private const MAX_DEPTH = 64;

    /**
     * A JSON string (with escapes) or a JSON number. Strings are matched first,
     * so digits inside strings are never touched. Quantifiers are possessive to
     * rule out catastrophic backtracking.
     */
    private const TOKEN = '/"(?:[^"\\\\]++|\\\\.)*+"|-?(?:0|[1-9]\d*+)(?:\.\d++)?(?:[eE][+-]?\d++)?/s';

    /**
     * Encodes a request body as a JSON object.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \JsonException when a value cannot be encoded (invalid UTF-8, NaN, unsupported type)
     */
    public static function encodeObject(array $data): string
    {
        return $data === [] ? '{}' : self::encodeValue($data, 0);
    }

    /**
     * Decodes a JSON document whose top level is an object or an array. JSON
     * numbers come back as decimal strings.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException when the document is not valid JSON or its top level is a scalar
     */
    public static function decode(string $json): array
    {
        $quoted = preg_replace_callback(
            self::TOKEN,
            static fn(array $match): string => $match[0][0] === '"' ? $match[0] : '"' . $match[0] . '"',
            $json,
        );

        if ($quoted === null) {
            throw new \JsonException('Unable to tokenize the JSON document.');
        }

        $decoded = json_decode($quoted, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \JsonException('The JSON document is not an object or an array.');
        }

        return $decoded;
    }

    /**
     * @throws \JsonException
     */
    private static function encodeValue(mixed $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new \JsonException('Maximum JSON depth exceeded.');
        }

        if ($value instanceof JsonNumber) {
            return $value->literal;
        }

        if ($value instanceof \JsonSerializable) {
            return self::encodeValue($value->jsonSerialize(), $depth + 1);
        }

        if (\is_array($value)) {
            return self::encodeArray($value, $depth);
        }

        if ($value === null || \is_scalar($value)) {
            return json_encode($value, self::ENCODE_FLAGS);
        }

        throw new \JsonException(\sprintf('Values of type %s cannot be encoded as JSON.', get_debug_type($value)));
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @throws \JsonException
     */
    private static function encodeArray(array $value, int $depth): string
    {
        if (array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = self::encodeValue($item, $depth + 1);
            }

            return '[' . implode(',', $items) . ']';
        }

        $members = [];
        foreach ($value as $key => $item) {
            $members[] = json_encode((string) $key, self::ENCODE_FLAGS) . ':' . self::encodeValue($item, $depth + 1);
        }

        return '{' . implode(',', $members) . '}';
    }
}
