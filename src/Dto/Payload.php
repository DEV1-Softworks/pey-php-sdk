<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto;

use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Money\Amount;

/**
 * Typed, path-aware reader over a decoded Pey response.
 *
 * Json::decode() returns JSON numbers as decimal strings, so integers and
 * amounts are parsed here explicitly and never pass through a float. A JSON
 * `null` counts as an absent field. Any mismatch becomes an
 * UnexpectedResponseException that names the field path but never its value.
 *
 * @internal
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     * @param string                  $resource response object name used in error messages
     * @param string                  $path     prefix of this object inside the response, for example "items[0]."
     */
    public function __construct(
        private array $data,
        private string $resource,
        private string $path = '',
    ) {}

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw $this->invalid($key, 'a string');
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value !== null && !\is_string($value)) {
            throw $this->invalid($key, 'a string');
        }

        return $value;
    }

    public function int(string $key): int
    {
        return $this->optionalInt($key) ?? throw $this->invalid($key, 'an integer');
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || \is_int($value)) {
            return $value;
        }

        $int = \is_string($value) ? filter_var($value, \FILTER_VALIDATE_INT) : false;

        return \is_int($int) ? $int : throw $this->invalid($key, 'an integer');
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : throw $this->invalid($key, 'a boolean');
    }

    public function amount(string $key): Amount
    {
        return $this->optionalAmount($key) ?? throw $this->invalid($key, 'a decimal amount');
    }

    public function optionalAmount(string $key): ?Amount
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (\is_int($value)) {
            $value = (string) $value;
        }

        try {
            return \is_string($value) ? Amount::fromString($value) : throw $this->invalid($key, 'a decimal amount');
        } catch (ValidationException) {
            throw $this->invalid($key, 'a non-negative decimal amount');
        }
    }

    /**
     * A Unix timestamp in seconds, as a UTC date.
     */
    public function timestamp(string $key): \DateTimeImmutable
    {
        return $this->optionalTimestamp($key) ?? throw $this->invalid($key, 'a Unix timestamp');
    }

    public function optionalTimestamp(string $key): ?\DateTimeImmutable
    {
        $seconds = $this->optionalInt($key);

        if ($seconds === null) {
            return null;
        }

        if ($seconds < 0) {
            throw $this->invalid($key, 'a Unix timestamp');
        }

        return new \DateTimeImmutable('@' . $seconds);
    }

    public function object(string $key): self
    {
        return $this->optionalObject($key) ?? throw $this->invalid($key, 'an object');
    }

    public function optionalObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'an object');
        }

        return new self($value, $this->resource, $this->path . $key . '.');
    }

    /**
     * A list of objects. An absent list is empty.
     *
     * @return list<self>
     */
    public function objectList(string $key): array
    {
        $items = [];

        foreach ($this->list($key, 'a list of objects') as $index => $item) {
            if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
                throw $this->invalid(\sprintf('%s[%d]', $key, $index), 'an object');
            }

            $items[] = new self($item, $this->resource, \sprintf('%s%s[%d].', $this->path, $key, $index));
        }

        return $items;
    }

    /**
     * A list of strings. An absent list is empty.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $strings = [];

        foreach ($this->list($key, 'a list of strings') as $index => $item) {
            $strings[] = \is_string($item) ? $item : throw $this->invalid(\sprintf('%s[%d]', $key, $index), 'a string');
        }

        return $strings;
    }

    /**
     * A map of string values, as Pey returns metadata. An absent map is empty.
     * Pey documents that every value comes back as a string; booleans and null
     * are converted the way Pey stores them ("true", "false", "null").
     *
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'an object');
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = match (true) {
                \is_string($item) => $item,
                $item === true => 'true',
                $item === false => 'false',
                $item === null => 'null',
                default => throw $this->invalid($key . '.' . $name, 'a string'),
            };
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function list(string $key, string $expected): array
    {
        $value = $this->data[$key] ?? [];

        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, $expected);
        }

        return $value;
    }

    private function invalid(string $key, string $expected): UnexpectedResponseException
    {
        return UnexpectedResponseException::invalidField($this->resource, $this->path . $key, $expected);
    }
}
