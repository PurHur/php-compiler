<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GetClassVarsRuntime;
use PHPCompiler\JIT\Builtin\StringGetClassVars;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for get_class_vars() via GetClassVarsJitHelper PHP (#3159, #16713).
 *
 * Thin standalone AOT: compile-time Object_ defaults via {@see GetClassVarsRuntime} (#27229).
 * NestedJIT / VM: PHP SSOT bridge (scope-aware, #23531).
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_vars)
 */
final class JitGetClassVars
{
    public static function invoke(Context $context, JITVariable $classArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null === $literal) {
            throw new \LogicException(
                'get_class_vars() class name must be a string literal in this compiler build'
            );
        }

        // Helper-runtime GetClassVarsJitHelper stubs VmReflection → silent NULL under thin AOT (#27229 / #579).
        if ($context->isThinStandaloneAotMain()) {
            return GetClassVarsRuntime::emitForClassName($context, $literal);
        }

        return self::routeThroughPhpHelper($context, $classArg);
    }

    /** NestedJIT / VALUE operand — PHP helper applies "C" soft-null (#30060). */
    public static function invokeFromValueBox(Context $context, JITVariable $classArg): Value
    {
        return self::routeThroughPhpHelper($context, $classArg);
    }

    private static function routeThroughPhpHelper(Context $context, JITVariable $classArg): Value
    {
        $operandPtr = self::operandToValueBox($context, $classArg);

        return StringGetClassVars::invoke($context, $operandPtr);
    }

    private static function operandToValueBox(Context $context, JITVariable $classArg): Value
    {
        if (JITVariable::TYPE_VALUE === $classArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $classArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->helper->loadValue($classArg)
        );

        return $ptr;
    }
}
