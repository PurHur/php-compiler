<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Leading-backslash normalization for introspection builtins in JIT/AOT (#12176).
 *
 * VM SSOT: {@see VmReflection::normalizeGlobalIntrospectionName}.
 * php-src: ext/standard/basic_functions.c
 */
final class GlobalIntrospectionNameJitHelper
{
    public static function normalize(string $name): string
    {
        return VmReflection::normalizeGlobalIntrospectionName($name);
    }
}
