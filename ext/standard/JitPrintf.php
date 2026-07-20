<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for printf() (%s, %d, %f, %%).
 *
 * Z_PARAM_STR $format: Zend 8.4 DEP+coerces null (#21234; reverts #20197 TypeError).
 */
final class JitPrintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        // User-standalone init skips StringFormat::ensureLinked (#13571) —
        // without a body the ABI symbols die at link with undefined
        // __compiler_printf (same as JitSprintf #15642).
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
        }
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'printf() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21234, formatted_print.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'printf', 0, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'printf', 0, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            // lowerStrict* already emitted TypeError+abort; do not lower __compiler_printf after terminator.
            return $context->constantFromInteger(0, 'int64');
        }
        $numArgs = $argc - 1;
        if (0 === $numArgs) {
            $nullArgv = $context->builder->pointerCast(
                $context->getTypeFromString('int64')->constInt(0, false),
                $context->getTypeFromString('__value__*')
            );

            return $context->builder->intCast(
                $context->builder->call(
                    $context->lookupFunction('__compiler_printf'),
                    $fmt,
                    $context->getTypeFromString('int64')->constInt(0, false),
                    $nullArgv
                ),
                $context->getTypeFromString('int64')
            );
        }

        $valueTy = $context->getTypeFromString('__value__');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $i32->constInt(1, false)
            ),
            $sizeT
        );
        $argvCountSize = $context->builder->intCast(
            $i64->constInt($numArgs, false),
            $sizeT
        );
        $argvBytes = $context->builder->mul($elemSize, $argvCountSize);
        $argvRaw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $argvBytes
        );
        $argvPtr = $context->builder->pointerCast(
            $argvRaw,
            $context->getTypeFromString('__value__*')
        );
        for ($i = 0; $i < $numArgs; ++$i) {
            $slot = $context->builder->inBoundsGEP(
                $argvPtr,
                $i64->constInt($i, false)
            );
            JitSprintf::writeArg($context, $slot, $args[$i + 1]);
        }
        $argcVal = $i64->constInt($numArgs, false);
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_printf'),
            $fmt,
            $argcVal,
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);

        return $context->builder->intCast($written, $i64);
    }
}
