<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

/**
 * A minimal JSON Schema (2020-12) checker for the keywords Pey's request
 * schemas use: type, enum, pattern, minLength, maxLength, minimum, maximum,
 * exclusiveMinimum, required, properties, additionalProperties, maxProperties
 * and propertyNames. Unknown keywords are ignored.
 *
 * Values are decoded with json_decode() without associative arrays, so JSON
 * objects (stdClass) and arrays (lists) stay distinguishable. This is test
 * code: comparing number bounds as floats is fine here.
 */
final class JsonSchema
{
    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string> violations, empty when the value matches
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $type = self::typeOf($value);
        $allowed = isset($schema['type']) ? (array) $schema['type'] : null;

        if ($allowed !== null && !\in_array($type, $allowed, true) && !($type === 'integer' && \in_array('number', $allowed, true))) {
            return [\sprintf('%s: type %s is not one of %s', $path, $type, implode('|', array_filter($allowed, \is_string(...))))];
        }

        $errors = [];

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $errors[] = $path . ': not in enum';
        }

        if (\is_string($value)) {
            $errors = [...$errors, ...self::validateString($value, $schema, $path)];
        }

        if (\is_int($value) || \is_float($value)) {
            $errors = [...$errors, ...self::validateNumber($value, $schema, $path)];
        }

        if ($value instanceof \stdClass) {
            $errors = [...$errors, ...self::validateObject($value, $schema, $path)];
        }

        return $errors;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_string($value) => 'string',
            \is_array($value) => 'array',
            default => 'object',
        };
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private static function validateString(string $value, array $schema, string $path): array
    {
        $errors = [];
        $length = preg_match_all('/./su', $value);

        if (isset($schema['pattern']) && \is_string($schema['pattern']) && preg_match('/' . $schema['pattern'] . '/Du', $value) !== 1) {
            $errors[] = $path . ': does not match ' . $schema['pattern'];
        }
        if (isset($schema['minLength']) && \is_int($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = $path . ': shorter than minLength';
        }
        if (isset($schema['maxLength']) && \is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = $path . ': longer than maxLength';
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private static function validateNumber(int|float $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = $path . ': below minimum';
        }
        if (isset($schema['exclusiveMinimum']) && is_numeric($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $errors[] = $path . ': not above exclusiveMinimum';
        }
        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = $path . ': above maximum';
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private static function validateObject(\stdClass $value, array $schema, string $path): array
    {
        $errors = [];
        $members = get_object_vars($value);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $required = \is_array($schema['required'] ?? null) ? array_filter($schema['required'], \is_string(...)) : [];
        foreach ($required as $name) {
            if (!\array_key_exists($name, $members)) {
                $errors[] = \sprintf('%s: missing required %s', $path, $name);
            }
        }

        if (isset($schema['maxProperties']) && \is_int($schema['maxProperties']) && \count($members) > $schema['maxProperties']) {
            $errors[] = $path . ': too many properties';
        }

        $propertyNames = \is_array($schema['propertyNames'] ?? null) ? $schema['propertyNames'] : null;
        $additional = $schema['additionalProperties'] ?? true;

        foreach ($members as $name => $member) {
            $name = (string) $name;
            if ($propertyNames !== null) {
                $errors = [...$errors, ...self::validateString($name, $propertyNames, $path . '.<key ' . $name . '>')];
            }

            if (isset($properties[$name]) && \is_array($properties[$name])) {
                $errors = [...$errors, ...self::validate($member, $properties[$name], $path . '.' . $name)];
            } elseif ($additional === false) {
                $errors[] = \sprintf('%s: unexpected property %s', $path, $name);
            } elseif (\is_array($additional)) {
                $errors = [...$errors, ...self::validate($member, $additional, $path . '.' . $name)];
            }
        }

        return $errors;
    }
}
