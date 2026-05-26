<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__htmlspecialchars_decode (mirrors VmString / encode subset).
 */
final class StringHtmlspecialcharsDecode
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__htmlspecialchars_decode');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $quoteBoth = self::quoteBothFlag($context, $flags);

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $quoteBoth, $i64, $i8, $zero, $one);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop($context, $fn, $srcChars, $len, $destChars, $iSlot, $posSlot, $quoteBoth, $i64, $i8, $zero, $one);

        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }

    private static function quoteBothFlag(Context $context, Value $flags): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(3, false)),
            $zero
        );
    }

    private static function countLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        Value $quoteBoth,
        $i64,
        $i8,
        Value $zero,
        Value $one
    ): void {
        $head = $fn->appendBasicBlock('hsd_count_head');
        $body = $fn->appendBasicBlock('hsd_count_body');
        $done = $fn->appendBasicBlock('hsd_count_done');

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $i, $len),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $step = self::consumeStep($context, $fn, $srcChars, $len, $i, $quoteBoth, $i64, $i8, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $step), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function writeLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $iSlot,
        Value $posSlot,
        Value $quoteBoth,
        $i64,
        $i8,
        Value $zero,
        Value $one
    ): void {
        $head = $fn->appendBasicBlock('hsd_write_head');
        $body = $fn->appendBasicBlock('hsd_write_body');
        $done = $fn->appendBasicBlock('hsd_write_done');

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $i, $len),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $i = $context->builder->load($iSlot);
        $pos = $context->builder->load($posSlot);
        $outByte = self::outputByte($context, $fn, $srcChars, $len, $i, $quoteBoth, $i64, $i8);
        $context->builder->store($outByte, $context->builder->gep($destChars, $pos));
        $step = self::consumeStep($context, $fn, $srcChars, $len, $i, $quoteBoth, $i64, $i8, $one);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $step), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function consumeStep(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $quoteBoth,
        $i64,
        $i8,
        Value $one
    ): Value {
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isAmp = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(38, false));
        $entityStep = self::entityStep($context, $fn, $srcChars, $len, $i, $quoteBoth, $i64, $i8);

        return $context->builder->select($isAmp, $entityStep, $one);
    }

    private static function outputByte(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $quoteBoth,
        $i64,
        $i8
    ): Value {
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isAmp = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(38, false));
        $decoded = self::entityByte($context, $fn, $srcChars, $len, $i, $quoteBoth, $i64, $i8);

        return $context->builder->select($isAmp, $decoded, $ch);
    }

    private static function entityStep(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $quoteBoth,
        $i64,
        $i8
    ): Value {
        $one = $i64->constInt(1, false);
        $step = $one;
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, 'amp;', 5, $step, $i64, $i8);
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, 'lt;', 4, $step, $i64, $i8);
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, 'gt;', 4, $step, $i64, $i8);
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, 'quot;', 6, $step, $i64, $i8, $quoteBoth);
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, '#039;', 6, $step, $i64, $i8, $quoteBoth);
        $step = self::stepIfEntity($context, $fn, $srcChars, $len, $i, '#39;', 5, $step, $i64, $i8, $quoteBoth);

        return $step;
    }

    private static function entityByte(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $quoteBoth,
        $i64,
        $i8
    ): Value {
        $amp = $i8->constInt(38, false);
        $out = $amp;
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, 'amp;', 5, 38, $out, $i64, $i8);
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, 'lt;', 4, 60, $out, $i64, $i8);
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, 'gt;', 4, 62, $out, $i64, $i8);
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, 'quot;', 6, 34, $out, $i64, $i8, $quoteBoth);
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, '#039;', 6, 39, $out, $i64, $i8, $quoteBoth);
        $out = self::byteIfEntity($context, $fn, $srcChars, $len, $i, '#39;', 5, 39, $out, $i64, $i8, $quoteBoth);

        return $out;
    }

    private static function stepIfEntity(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        string $tail,
        int $totalLen,
        Value $current,
        $i64,
        $i8,
        ?Value $require = null
    ): Value {
        $match = self::entityMatches($context, $fn, $srcChars, $len, $i, $tail, $totalLen, $i64, $i8);
        if (null !== $require) {
            $match = $context->builder->and($match, $require);
        }

        return $context->builder->select($match, $i64->constInt($totalLen, false), $current);
    }

    private static function byteIfEntity(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        string $tail,
        int $totalLen,
        int $byte,
        Value $current,
        $i64,
        $i8,
        ?Value $require = null
    ): Value {
        $match = self::entityMatches($context, $fn, $srcChars, $len, $i, $tail, $totalLen, $i64, $i8);
        if (null !== $require) {
            $match = $context->builder->and($match, $require);
        }

        return $context->builder->select($match, $i8->constInt($byte, false), $current);
    }

    private static function entityMatches(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        string $tail,
        int $totalLen,
        $i64,
        $i8
    ): Value {
        $end = $context->builder->addNoSignedWrap($i, $i64->constInt($totalLen, false));
        $fits = $context->builder->icmp(Builder::INT_SLE, $end, $len);
        $match = $fits;
        $off = 1;
        $tailLen = strlen($tail);
        for ($j = 0; $j < $tailLen; ++$j) {
            $at = $context->builder->addNoSignedWrap($i, $i64->constInt($off + $j, false));
            $ch = $context->builder->load($context->builder->gep($srcChars, $at));
            $eq = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord($tail[$j]), false));
            $match = $context->builder->and($match, $eq);
        }

        return $match;
    }
}
