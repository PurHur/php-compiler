<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Builtin registry lookup for compiled JIT/AOT modules (#9239, php-in-PHP).
 *
 * VM SSOT uses {@see VmReflection::functionExists}; JIT/AOT call this helper via
 * {@see \PHPCompiler\JIT\Builtin\FunctionExistsRuntime} thin bridge.
 * php-src: ext/standard/basic_functions.c — function_exists / function_table
 */
final class FunctionExistsJitHelper
{
    public static function builtinExists(string $name): bool
    {
        return null !== BuiltinRegistry::resolve(VmReflection::normalizeGlobalIntrospectionName($name));
    }
}
