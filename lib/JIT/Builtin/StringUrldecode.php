<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__urldecode and __string__rawurldecode.
 */
final class StringUrldecode
{
    public static function implement(Context $context): void
    {
        self::implementFunction($context, '__string__urldecode', true);
        self::implementFunction($context, '__string__rawurldecode', false);
    }

    private static function implementFunction(Context $context, string $name, bool $formDecoding): void
    {
        $fn = $context->lookupFunction($name);
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $i64, $i8, $zero, $one, $two, $three, $formDecoding);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop($context, $fn, $srcChars, $len, $destChars, $iSlot, $posSlot, $i64, $i8, $zero, $one, $two, $three, $formDecoding);

        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }

    private static function countLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two,
        Value $three,
        bool $formDecoding
    ): void {
        $head = $fn->appendBasicBlock('urldecode_count_head');
        $body = $fn->appendBasicBlock('urldecode_count_body');
        $done = $fn->appendBasicBlock('urldecode_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $step = self::decodedStep($context, $fn, $srcChars, $len, $i, $ch, $one, $two, $three, $i64, $i8, $formDecoding);
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
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two,
        Value $three,
        bool $formDecoding
    ): void {
        $head = $fn->appendBasicBlock('urldecode_write_head');
        $body = $fn->appendBasicBlock('urldecode_write_body');
        $done = $fn->appendBasicBlock('urldecode_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $step = self::writeDecoded($context, $fn, $srcChars, $len, $i, $destAt, $ch, $one, $two, $three, $i64, $i8, $formDecoding);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $step), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function decodedStep(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $ch,
        Value $one,
        Value $two,
        Value $three,
        $i64,
        $i8,
        bool $formDecoding
    ): Value {
        $merge = $fn->appendBasicBlock('urldecode_step_merge');

        if ($formDecoding) {
            $plus = $fn->appendBasicBlock('urldecode_step_plus');
            $afterPlus = $fn->appendBasicBlock('urldecode_step_after_plus');
            $isPlus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('+'), false));
            $context->builder->branchIf($isPlus, $plus, $afterPlus);
            $context->builder->positionAtEnd($plus);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($afterPlus);
        }

        $isPct = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('%'), false));
        $pct = $fn->appendBasicBlock('urldecode_step_pct');
        $plain = $fn->appendBasicBlock('urldecode_step_plain');
        $context->builder->branchIf($isPct, $pct, $plain);

        $context->builder->positionAtEnd($pct);
        $hasRoom = $context->builder->icmp(
            Builder::INT_SLT,
            $context->builder->addNoSignedWrap($i, $two),
            $len
        );
        $tri = $fn->appendBasicBlock('urldecode_step_tri');
        $oneByte = $fn->appendBasicBlock('urldecode_step_one');
        $context->builder->branchIf($hasRoom, $tri, $oneByte);

        $context->builder->positionAtEnd($tri);
        $hiCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $loCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $isHex = self::isHexPair($context, $hiCh, $loCh, $i64, $i8);
        $triOk = $fn->appendBasicBlock('urldecode_step_tri_ok');
        $triBad = $fn->appendBasicBlock('urldecode_step_tri_bad');
        $context->builder->branchIf($isHex, $triOk, $triBad);

        $context->builder->positionAtEnd($triOk);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($triBad);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($oneByte);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($plain);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $step = $context->builder->phi($i64);
        if ($formDecoding) {
            $step->addIncoming($one, $plus);
        }
        $step->addIncoming($three, $triOk);
        $step->addIncoming($one, $triBad);
        $step->addIncoming($one, $oneByte);
        $step->addIncoming($one, $plain);

        return $step;
    }

    private static function writeDecoded(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $destAt,
        Value $ch,
        Value $one,
        Value $two,
        Value $three,
        $i64,
        $i8,
        bool $formDecoding
    ): Value {
        $merge = $fn->appendBasicBlock('urldecode_write_merge');

        if ($formDecoding) {
            $plus = $fn->appendBasicBlock('urldecode_write_plus');
            $afterPlus = $fn->appendBasicBlock('urldecode_write_after_plus');
            $isPlus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('+'), false));
            $context->builder->branchIf($isPlus, $plus, $afterPlus);
            $context->builder->positionAtEnd($plus);
            $context->builder->store($i8->constInt(ord(' '), false), $destAt);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($afterPlus);
        }

        $isPct = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('%'), false));
        $pct = $fn->appendBasicBlock('urldecode_write_pct');
        $plain = $fn->appendBasicBlock('urldecode_write_plain');
        $context->builder->branchIf($isPct, $pct, $plain);

        $context->builder->positionAtEnd($pct);
        $hasRoom = $context->builder->icmp(
            Builder::INT_SLT,
            $context->builder->addNoSignedWrap($i, $two),
            $len
        );
        $tri = $fn->appendBasicBlock('urldecode_write_tri');
        $oneByte = $fn->appendBasicBlock('urldecode_write_one');
        $context->builder->branchIf($hasRoom, $tri, $oneByte);

        $context->builder->positionAtEnd($tri);
        $hiCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $loCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $isHex = self::isHexPair($context, $hiCh, $loCh, $i64, $i8);
        $triOk = $fn->appendBasicBlock('urldecode_write_tri_ok');
        $triBad = $fn->appendBasicBlock('urldecode_write_tri_bad');
        $context->builder->branchIf($isHex, $triOk, $triBad);

        $context->builder->positionAtEnd($triOk);
        $byte = self::decodeHexPair($context, $hiCh, $loCh, $i64, $i8);
        $context->builder->store($byte, $destAt);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($triBad);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($oneByte);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($plain);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $step = $context->builder->phi($i64);
        if ($formDecoding) {
            $step->addIncoming($one, $plus);
        }
        $step->addIncoming($three, $triOk);
        $step->addIncoming($one, $triBad);
        $step->addIncoming($one, $oneByte);
        $step->addIncoming($one, $plain);

        return $step;
    }

    private static function isHexPair(Context $context, Value $hiCh, Value $loCh, $i64, $i8): Value
    {
        $invalid = $i64->constInt(-1, true);
        $hi = self::hexNibbleValue($context, $hiCh, $i64, $i8);
        $lo = self::hexNibbleValue($context, $loCh, $i64, $i8);

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $hi, $invalid),
            $context->builder->icmp(Builder::INT_NE, $lo, $invalid)
        );
    }

    private static function decodeHexPair(Context $context, Value $hiCh, Value $loCh, $i64, $i8): Value
    {
        $hi = self::hexNibbleValue($context, $hiCh, $i64, $i8);
        $lo = self::hexNibbleValue($context, $loCh, $i64, $i8);
        $combined = $context->builder->or(
            $context->builder->shl($hi, $i64->constInt(4, false)),
            $lo
        );

        return $context->builder->truncOrBitCast($combined, $i8);
    }

    /** @return Value int64 nibble 0-15, or -1 when not hex */
    private static function hexNibbleValue(Context $context, Value $ch, $i64, $i8): Value
    {
        $ord = $context->builder->zExt($ch, $i64);
        $invalid = $i64->constInt(-1, true);

        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('9'), false))
        );
        $digitVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(ord('0'), false));

        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('F'), false))
        );
        $upperVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(55, false));

        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('a'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('f'), false))
        );
        $lowerVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(87, false));

        $alnum = $context->builder->select($isDigit, $digitVal, $invalid);
        $mixed = $context->builder->select($isUpper, $upperVal, $alnum);

        return $context->builder->select($isLower, $lowerVal, $mixed);
    }
}
