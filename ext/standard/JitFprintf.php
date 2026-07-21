<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for fprintf() — __compiler_sprintf + __compiler_fwrite (#3301).
 *
 * Z_PARAM_STR $format: Zend 8.4 DEP+coerces null (#21234; reverts #20197 TypeError).
 */
final class JitFprintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        // Link __compiler_sprintf body for user-standalone AOT (#15642 / #21514).
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
        }
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException('fprintf() expects at least two arguments');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fprintf() stream'),
            $i64
        );
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21234, formatted_print.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'fprintf', 1, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'fprintf', 1, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            // lowerStrict* already emitted TypeError+abort; do not lower sprintf/fwrite after terminator.
            return $context->constantFromInteger(0, 'int64');
        }
        $numArgs = $argc - 2;
        if (0 === $numArgs) {
            $formatted = $context->builder->call(
                $context->lookupFunction('__compiler_sprintf'),
                $fmt,
                $i64->constInt(0, false),
                $context->builder->pointerCast(
                    $i64->constInt(0, false),
                    $context->getTypeFromString('__value__*')
                )
            );

            return JitFwrite::invoke($context, $handle, $formatted, JitFwrite::lengthWriteAll($context, $formatted));
        }

        $valueTy = $context->getTypeFromString('__value__');
        $i32 = $context->getTypeFromString('int32');
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
            JitSprintf::writeArg($context, $slot, $args[$i + 2]);
        }
        $formatted = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $i64->constInt($numArgs, false),
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);

        return JitFwrite::invoke($context, $handle, $formatted, JitFwrite::lengthWriteAll($context, $formatted));
    }
}
