<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time method parameter metadata for reflection (#3340).
 */
final class ParameterMetadata
{
    /**
     * @param list<AttributeEntry> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
    ) {
    }
}
