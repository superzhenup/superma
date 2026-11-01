<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ToolContracts.php';

/** Deterministic, fail-closed subset used by the first-party Tool schemas. */
final class ToolJsonSchemaValidator
{
    /** @param array<string,mixed> $schema */
    public static function assertValid(mixed $value, array $schema, string $path = '$'): void
    {
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            throw new ToolSchemaViolationException("{$path}: schema type is required.");
        }
        if (isset($schema['enum'])) {
            if (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true)) {
                throw new ToolSchemaViolationException("{$path}: value is outside the allowed enum.");
            }
        }

        match ($type) {
            'object' => self::validateObject($value, $schema, $path),
            'array' => self::validateArray($value, $schema, $path),
            'string' => self::validateString($value, $schema, $path),
            'integer' => self::validateInteger($value, $schema, $path),
            'number' => self::validateNumber($value, $schema, $path),
            'boolean' => is_bool($value)
                ?: throw new ToolSchemaViolationException("{$path}: expected boolean."),
            'null' => $value === null
                ?: throw new ToolSchemaViolationException("{$path}: expected null."),
            default => throw new ToolSchemaViolationException(
                "{$path}: unsupported schema type {$type}."
            ),
        };
    }

    /** @param array<string,mixed> $schema */
    private static function validateObject(mixed $value, array $schema, string $path): true
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ToolSchemaViolationException("{$path}: expected object.");
        }
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))
            || !is_array($required) || !array_is_list($required)) {
            throw new ToolSchemaViolationException("{$path}: malformed object schema.");
        }
        foreach ($required as $field) {
            if (!is_string($field) || !array_key_exists($field, $value)) {
                throw new ToolSchemaViolationException("{$path}: required field {$field} is missing.");
            }
        }
        foreach ($value as $field => $item) {
            if (!is_string($field)) {
                throw new ToolSchemaViolationException("{$path}: object keys must be strings.");
            }
            if (!array_key_exists($field, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    throw new ToolSchemaViolationException("{$path}: unknown field {$field}.");
                }
                continue;
            }
            if (!is_array($properties[$field])) {
                throw new ToolSchemaViolationException("{$path}.{$field}: malformed property schema.");
            }
            self::assertValid($item, $properties[$field], "{$path}.{$field}");
        }
        return true;
    }

    /** @param array<string,mixed> $schema */
    private static function validateArray(mixed $value, array $schema, string $path): true
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ToolSchemaViolationException("{$path}: expected array.");
        }
        $count = count($value);
        if (isset($schema['minItems']) && $count < (int)$schema['minItems']) {
            throw new ToolSchemaViolationException("{$path}: too few items.");
        }
        if (isset($schema['maxItems']) && $count > (int)$schema['maxItems']) {
            throw new ToolSchemaViolationException("{$path}: too many items.");
        }
        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            throw new ToolSchemaViolationException("{$path}: array item schema is required.");
        }
        foreach ($value as $index => $item) {
            self::assertValid($item, $items, "{$path}.{$index}");
        }
        return true;
    }

    /** @param array<string,mixed> $schema */
    private static function validateString(mixed $value, array $schema, string $path): true
    {
        if (!is_string($value)) {
            throw new ToolSchemaViolationException("{$path}: expected string.");
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (isset($schema['minLength']) && $length < (int)$schema['minLength']) {
            throw new ToolSchemaViolationException("{$path}: string is too short.");
        }
        if (isset($schema['maxLength']) && $length > (int)$schema['maxLength']) {
            throw new ToolSchemaViolationException("{$path}: string is too long.");
        }
        if (isset($schema['pattern'])
            && (!is_string($schema['pattern'])
                || @preg_match($schema['pattern'], $value) !== 1)) {
            throw new ToolSchemaViolationException("{$path}: string does not match pattern.");
        }
        return true;
    }

    /** @param array<string,mixed> $schema */
    private static function validateInteger(mixed $value, array $schema, string $path): true
    {
        if (!is_int($value)) {
            throw new ToolSchemaViolationException("{$path}: expected integer.");
        }
        self::validateBounds($value, $schema, $path);
        return true;
    }

    /** @param array<string,mixed> $schema */
    private static function validateNumber(mixed $value, array $schema, string $path): true
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            throw new ToolSchemaViolationException("{$path}: expected finite number.");
        }
        self::validateBounds((float)$value, $schema, $path);
        return true;
    }

    /** @param array<string,mixed> $schema */
    private static function validateBounds(int|float $value, array $schema, string $path): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new ToolSchemaViolationException("{$path}: value is below minimum.");
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new ToolSchemaViolationException("{$path}: value exceeds maximum.");
        }
    }
}
