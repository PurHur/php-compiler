<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * JIT lowering for object::propertyIsInitialized() — thin trampoline to
 * {@see PropertyIsInitializedLlvm} + {@see \PHPCompiler\VM\PropertyIsInitializedJitHelper} (#10186).
 */
final class PropertyIsInitializedHelper
{
    public static function lower(Context $context, Variable $receiver, Variable $propNameArg): Value
    {
        return PropertyIsInitializedLlvm::lower($context, $receiver, $propNameArg);
    }
}
