<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time method/function parameter metadata for reflection (#3340, #22522).
 *
 * Optional/variadic/by-ref/type/default fields feed Reflection*::__toString and
 * required-parameter counting (php-src ext/reflection/php_reflection.c).
 */
final class ParameterMetadata
{
    /**
     * @param list<AttributeEntry> $attributes
     * @param ?string              $typeString    Zend dump form (e.g. "int", "?array"); null = untyped
     * @param ?string              $defaultExport Zend dump default (e.g. "'a'", "NULL"); null = none/unavailable
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
        public readonly bool $isPromoted = false,
        public readonly bool $isOptional = false,
        public readonly bool $isVariadic = false,
        public readonly bool $byRef = false,
        public readonly ?string $typeString = null,
        public readonly ?string $defaultExport = null,
    ) {
    }
}
