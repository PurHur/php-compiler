<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Zend parity for self/parent/static outside class scope.
 *
 * - Compile-time `::class` → {@see fatalInGlobalScope} (zend_compile.c; #5024)
 * - Runtime resolve (method/const fetch, eval) → {@see fatalNoActiveClassScope} (zend_execute.c; #29096)
 */
final class PseudoClassScope
{
    /** Compile-time self/parent/static::class with no class scope. */
    public static function fatalInGlobalScope(string $keyword): never
    {
        throw new \LogicException('Cannot use "'.$keyword.'" in the global scope');
    }

    /** Runtime self/parent/static:: when no active class scope (zend_execute.c). */
    public static function fatalNoActiveClassScope(string $keyword): never
    {
        throw new \LogicException('Cannot access "'.$keyword.'" when no class scope is active');
    }
}
