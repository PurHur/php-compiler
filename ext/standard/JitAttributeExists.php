<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for attribute_exists() (#6468). */
final class JitAttributeExists
{
    public static function invoke(Context $context, JITVariable $classArg, JITVariable $attributeArg): Value
    {
        $classLit = JitStringArg::compileTimeLiteral($classArg);
        $attrLit = JitStringArg::compileTimeLiteral($attributeArg);
        if (null !== $classLit && null !== $attrLit) {
            return ReflectionBuiltinHelper::attributeExistsLiteral($context, $classLit, $attrLit);
        }

        JitStringBuiltinArg::lower($context, $classArg, 'attribute_exists', 0, 'class');
        JitStringBuiltinArg::lower($context, $attributeArg, 'attribute_exists', 1, 'attribute');
        throw new \LogicException(
            'attribute_exists() requires compile-time string arguments in this compiler build'
        );
    }
}
