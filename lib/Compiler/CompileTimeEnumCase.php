<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time `Enum::Case` in attribute ctor args (#9988).
 *
 * Materialized when building ReflectionAttribute metadata (Zend compile-time constant expr).
 */
final class CompileTimeEnumCase
{
    public function __construct(
        public readonly string $enumName,
        public readonly string $caseName,
    ) {
    }
}
