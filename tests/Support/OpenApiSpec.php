<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

/**
 * Read access to the openapi.json fixture (tests/Fixtures/openapi.json).
 */
final class OpenApiSpec
{
    /**
     * @var array<array-key, mixed>|null
     */
    private static ?array $document = null;

    /**
     * @return array<array-key, mixed>
     */
    public static function document(): array
    {
        if (self::$document === null) {
            $contents = file_get_contents(\dirname(__DIR__) . '/Fixtures/openapi.json');
            if ($contents === false) {
                throw new \RuntimeException('Missing tests/Fixtures/openapi.json.');
            }

            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('openapi.json is not an object.');
            }

            self::$document = $decoded;
        }

        return self::$document;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function requestSchema(string $method, string $path): array
    {
        return self::array(self::operation($method, $path), 'requestBody', 'content', 'application/json', 'schema');
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function responseSchema(string $method, string $path, string $status): array
    {
        return self::array(self::operation($method, $path), 'responses', $status, 'content', 'application/json', 'schema');
    }

    /**
     * The example of a response, re-encoded as JSON text so it can go through
     * the SDK's float-free decoder exactly like a real response.
     */
    public static function responseExampleJson(string $method, string $path, string $status): string
    {
        $example = self::array(self::operation($method, $path), 'responses', $status, 'content', 'application/json')['example'] ?? null;

        if (!\is_array($example)) {
            throw new \RuntimeException(\sprintf('No example for %s %s %s.', $method, $path, $status));
        }

        return json_encode($example, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string> the property names of an object schema
     */
    public static function propertyNames(array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        return \is_array($properties) ? array_map('strval', array_keys($properties)) : [];
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    public static function property(array $schema, string ...$names): array
    {
        foreach ($names as $name) {
            $schema = $name === '[]' ? self::array($schema, 'items') : self::array($schema, 'properties', $name);
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    public static function enumValues(array $schema): array
    {
        $enum = $schema['enum'] ?? null;
        if (!\is_array($enum)) {
            throw new \RuntimeException('Schema has no enum.');
        }

        return array_values(array_map(static fn(mixed $value): string => \is_string($value) ? $value : throw new \RuntimeException('Non-string enum value.'), $enum));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function operation(string $method, string $path): array
    {
        return self::array(self::document(), 'paths', $path, strtolower($method));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function array(array $data, string ...$keys): array
    {
        foreach ($keys as $key) {
            $next = $data[$key] ?? null;
            if (!\is_array($next)) {
                throw new \RuntimeException(\sprintf('The spec has no "%s" (path: %s).', $key, implode(' > ', $keys)));
            }
            $data = $next;
        }

        return $data;
    }
}
