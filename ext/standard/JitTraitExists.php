<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for trait_exists() via TraitExistsJitHelper PHP (#1371, #16173, #19223).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringTraitExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitTraitExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::traitExistsLiteral($context, $literal);
        }

        return self::invokeLowered(
            $context,
            JitStringArg::lower($context, $nameArg, 'trait_exists() trait name')
        );
    }

    /** Pre-lowered {@see __string__*} name (8.4 Z_PARAM_STR null guard at call site, #19223). */
    public static function invokeLowered(Context $context, Value $nameStr): Value
    {
        return StringTraitExists::invoke($context, $nameStr);
    }
}
