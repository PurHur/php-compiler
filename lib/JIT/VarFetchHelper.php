<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\VarFetchRuntime;
use PHPCompiler\VM\VmVarFetch;

/**
 * JIT trampoline for dynamic variable fetch (`$$name`, issue #1226, #10289).
 *
 * SSOT: {@see \PHPCompiler\VM\VmVarFetch}
 */
final class VarFetchHelper
{
    public static function resolveTarget(Context $context, Block $block, Variable $nameVar, bool $forWrite = false): Variable
    {
        VarFetchRuntime::ensureLinked($context);

        return VmVarFetch::resolveCompileTimeTarget($context, $block, $nameVar, $forWrite);
    }

    public static function ensureBinding(Context $context, Block $block, string $name): Variable
    {
        VarFetchRuntime::ensureLinked($context);

        return VmVarFetch::ensureCompileTimeBinding($context, $block, $name);
    }

    public static function bindingByName(Context $context, Block $block, string $name): ?Variable
    {
        VarFetchRuntime::ensureLinked($context);

        return VmVarFetch::compileTimeBindingByName($context, $block, $name);
    }
}
