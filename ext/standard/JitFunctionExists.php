<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GlobalIntrospectionNameRuntime;
use PHPCompiler\JIT\Builtin\StringFunctionExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for function_exists() (issue #1216, #16424, #20360). */
final class JitFunctionExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20360, zend_builtin_functions.c).
        // Compile-time null must not continue into StringFunctionExists after catchable abort.
        if (JITVariable::TYPE_NULL === $nameArg->type || ($nameArg->isNullConstant ?? false)) {
            self::jitNameArg($context, $nameArg);

            return $i1->constInt(0, false);
        }
        $nameStr = self::jitNameArg($context, $nameArg);
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            $literal = VmReflection::normalizeGlobalIntrospectionName($literal);
            if (!VmReflection::isVisibleToFunctionExists($literal)) {
                return $i1->constInt(0, false);
            }

            return $i1->constInt(
                $context->functionIsRegistered($literal)
                    && BuiltinIntrospectionPolicy::functionIsAdvertised($literal) ? 1 : 0,
                false
            );
        }

        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);

        return StringFunctionExists::invoke($context, $nameStr);
    }

    private static function jitNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'function_exists',
                0,
                'function'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'function_exists',
            0,
            'function'
        );
    }
}
