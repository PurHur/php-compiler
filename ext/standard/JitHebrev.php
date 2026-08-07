<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Hebrev;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for hebrev() — HebrevJitHelper in-module, no C runtime (#3450). */
final class JitHebrev
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        if ($argc < 1 || $argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('hebrev() expects at least 1 argument, %d given', $argc)
                    : \sprintf('hebrev() expects at most 2 arguments, %d given', $argc)
            );

            return $slot;
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $maxLit = self::compileTimeMaxChars($context, $args, $argc);
        if (null !== $strLit && null !== $maxLit) {
            return self::materializeString($context, VmHebrev::convert($strLit, $maxLit));
        }

        $str = self::jitStringArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $max = $argc >= 2
            ? JitStrictIntArg::lower($context, $args[1], 'hebrev', 2, 'max_chars_per_line')
            : $i64->constInt(0, false);

        Hebrev::ensureLinked($context);
        $resultStr = $context->builder->call(
            Hebrev::helperFunction($context),
            $str,
            $max
        );
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeMaxChars(Context $context, array $args, int $argc): ?int
    {
        if ($argc < 2) {
            return 0;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type || JITVariable::KIND_VALUE !== $args[1]->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($args[1]->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($args[1]->value->value);
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hebrev',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hebrev',
            0,
            'string'
        );
    }
}
