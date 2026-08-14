<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\StringIdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for idate() — civil math in IR (#6830, #26900).
 *
 * NestedJIT / helper-runtime of IdateJitHelper segfaults or returns 0 under thin AOT.
 * Peer JitGetdate LLVM civil path.
 */
final class JitIdate
{
    public static function invoke(Context $context, JITVariable $format, ?JITVariable $timestamp = null): Value
    {
        // No-op link kept for Type/String_ compatibility.
        StringIdate::ensureLinked($context);

        $formatPtr = self::jitStringArg($context, $format);
        $ts = null === $timestamp
            ? JitDate::time($context)
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'idate',
                2,
                'timestamp',
                JitDate::time($context)
            );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $formatPtr);
        $badLen = $context->builder->icmp(Builder::INT_NE, $len, $sizeT->constInt(1, false));
        $badLenBb = BasicBlockHelper::append($context, 'idate_bad_len');
        $okLenBb = BasicBlockHelper::append($context, 'idate_ok_len');
        $mergeBb = BasicBlockHelper::append($context, 'idate_merge');
        $context->builder->branchIf($badLen, $badLenBb, $okLenBb);

        $context->builder->positionAtEnd($badLenBb);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($okLenBb);
        $dataPtr = $context->builder->structGep(
            $formatPtr,
            $context->structFieldMap['__string__']['value']
        );
        $ch = $context->builder->load($dataPtr);
        $ch64 = $context->builder->zExt($ch, $i64);

        $parts = JitGetdate::civilPartsPublic($context, $ts);
        $result = self::selectPart($context, $ch64, $ts, $parts);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $result, $i64->constInt(0, false));
        $falseBb = BasicBlockHelper::append($context, 'idate_tok_false');
        $intBb = BasicBlockHelper::append($context, 'idate_tok_int');
        $context->builder->branchIf($isNeg, $falseBb, $intBb);

        $context->builder->positionAtEnd($falseBb);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($intBb);
        JitValueBox::writeLong($context, $slot, $result);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $ptr;
    }

    /**
     * @param array{year:Value,month:Value,day:Value,hour:Value,minute:Value,second:Value,wday:Value,yday:Value} $parts
     */
    private static function selectPart(Context $context, Value $ch64, Value $ts, array $parts): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $negTwo = $i64->constInt(-2, true);
        $ord = static fn (string $c): Value => $i64->constInt(\ord($c), false);

        $out = $negTwo;
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('U')),
            $ts,
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('Y')),
            $parts['year'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('y')),
            $context->builder->signedRem($parts['year'], $i64->constInt(100, false)),
            $out
        );
        $out = $context->builder->select(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('m')),
                $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('n'))
            ),
            $parts['month'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('d')),
                $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('j'))
            ),
            $parts['day'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('H')),
            $parts['hour'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('i')),
            $parts['minute'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('s')),
            $parts['second'],
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('I')),
            self::localIsDst($context, $ts),
            $out
        );
        $out = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $ch64, $ord('w')),
            $parts['wday'],
            $out
        );

        return $out;
    }

    private static function localIsDst(Context $context, Value $timestamp): Value
    {
        DefaultTimezoneCivilRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_is_dst'),
            $timestamp
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'idate',
            0,
            'format'
        );
    }
}
