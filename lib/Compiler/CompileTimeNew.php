<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time `new Class(...)` in attribute ctor args (#5418).
 *
 * Materialized when building ReflectionAttribute metadata (Zend compile-time constant expr).
 */
final class CompileTimeNew
{
    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public function __construct(
        public readonly string $className,
        public readonly array $args = [],
    ) {
    }
}
