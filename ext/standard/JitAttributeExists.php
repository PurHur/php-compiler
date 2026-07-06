<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for attribute_exists() (#6468, #16844). */
final class JitAttributeExists
{
    public static function invoke(Context $context, JITVariable $attributeArg, JITVariable $objectArg): Value
    {
        $attrLit = JitStringArg::compileTimeLiteral($attributeArg);
        $classLit = JitStringArg::compileTimeLiteral($objectArg);
        if (null !== $attrLit && null !== $classLit) {
            return ReflectionBuiltinHelper::attributeExistsLiteral($context, $classLit, $attrLit);
        }

        JitStringBuiltinArg::lower($context, $attributeArg, 'attribute_exists', 0, 'attribute');
        JitStringBuiltinArg::lower($context, $objectArg, 'attribute_exists', 1, 'object');
        throw new \LogicException(
            'attribute_exists() requires compile-time string arguments in this compiler build'
        );
    }
}
