<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for file_get_contents() via {@see \PHPCompiler\JIT\Builtin\StringFileGetContents}.
 *
 * NestedJIT leaf: {@see JitFileGetContentsLibc} so `@file_get_contents` does not re-enter
 * {@see FileGetContentsJitHelper} via `__compiler_file_get_contents` (#29833 / #29545).
 */
final class JitFileGetContents
{
    public static function emitLengthValueErrorIfNegative(Context $context, Value $length): void
    {
        $i64 = $context->getTypeFromString('int64');
        $negative = $context->builder->icmp(Builder::INT_SLT, $length, $i64->constInt(0, false));
        $okBlock = BasicBlockHelper::append($context, 'fgc_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'fgc_len_err');
        $context->builder->branchIf($negative, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'file_get_contents(): Argument #5 ($length) must be greater than or equal to 0'
        );
        $context->builder->branch($okBlock);
        $context->builder->positionAtEnd($okBlock);
    }

    public static function wrapString(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    public static function invoke(Context $context, Value $pathStr): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::materializeStringOrFalse(
                $context,
                JitFileGetContentsLibc::call($context, $pathStr)
            );
        }

        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );

        return self::materializeStringOrFalse($context, $contents);
    }

    public static function invokeSlice(Context $context, Value $pathStr, Value $offset, Value $length): Value
    {
        $contents = NestedJitCompileScope::isActive()
            ? JitFileGetContentsLibc::call($context, $pathStr)
            : $context->builder->call(
                $context->lookupFunction('__compiler_file_get_contents'),
                $pathStr
            );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgc_slice_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgc_slice_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgc_slice_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $sliced = self::sliceString($context, $contents, $offset, $length);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $sliced
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function sliceString(Context $context, Value $str, Value $offset, Value $length): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $zero = JitStringIndex::zero($context);
        $i64 = $context->getTypeFromString('int64');
        $minusOne = $i64->constInt(-1, true);

        $negOffset = $context->builder->icmp(Builder::INT_SLT, $offset, $zero);
        $adjOffset = $context->builder->add($len, $offset);
        $clampedNeg = JitStringIndex::max($context, $adjOffset, $zero);
        $resolvedOffset = $context->builder->select($negOffset, $clampedNeg, $offset);
        $start = JitStringIndex::clamp($context, $resolvedOffset, $zero, $len);

        $unlimited = $context->builder->icmp(Builder::INT_EQ, $length, $minusOne);
        $remaining = $context->builder->sub($len, $start);
        $maxLen = JitStringIndex::max($context, $length, $zero);
        $limited = JitStringIndex::min($context, $maxLen, $remaining);
        $sliceLen = $context->builder->select($unlimited, $remaining, $limited);
        $sliceLen = JitStringIndex::max($context, $sliceLen, $zero);

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen, 'fgc');
    }

    private static function materializeStringOrFalse(Context $context, Value $contents): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgc_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgc_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $contents
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
