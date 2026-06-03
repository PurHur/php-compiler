<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Zend parity for self/parent/static outside class scope (zend_compile.c).
 */
final class PseudoClassScope
{
    public static function fatalInGlobalScope(string $keyword): never
    {
        throw new \LogicException('Cannot use "'.$keyword.'" in the global scope');
    }
}
