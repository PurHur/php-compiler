<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\UnpackJitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for unpack() via __compiler_unpack (issue #3188, #5442).
 *
 * Z_PARAM_STR $format: null TypeError on PHP_COMPILER_PROFILE=8.4 (#20241).
 * $string soft-null on 8.4 (#21246, pack.c) — DEP+coerce, not TypeError.
 */
final class JitUnpack
{
    public static function unpack(Context $context, JITVariable ...$args): Value
    {
        UnpackJitRuntime::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'unpack() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'unpack() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $format — null TypeError on PROFILE=8.4 (#20241, pack.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'unpack', 0, 'format')
            : JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'unpack', 0, 'format');
        if (
            $nullFormat
            && (
                $context->callerStrictTypes
                || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile()
            )
        ) {
            // lower* already emitted TypeError+abort; do not lower __compiler_unpack after terminator.
            return $context->constantFromBool(false);
        }
        // $string soft-null on 8.4 (#21246); strict_types still TypeError.
        $data = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'unpack', 1, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'unpack', 1, 'string');
        if ($context->callerStrictTypes && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            return $context->constantFromBool(false);
        }
        $offset = $context->getTypeFromString('int64')->constInt(0, false);
        if (3 === $argc) {
            $offset = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'unpack', 3, 'offset');
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack'),
            $fmt,
            $data,
            $offset,
            $ptr
        );

        return $ptr;
    }

}
