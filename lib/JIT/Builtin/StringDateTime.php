<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_format_datetime — gmtime/localtime + PHP format subset.
 */
final class StringDateTime
{
    private const TM_SEC = 0;
    private const TM_MIN = 4;
    private const TM_HOUR = 8;
    private const TM_MDAY = 12;
    private const TM_MON = 16;
    private const TM_YEAR = 20;

    private const OUT_BYTES = 256;

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_format_datetime');
        $entry = $fn->appendBasicBlock('dt_entry');
        $context->builder->positionAtEnd($entry);

        $format = $fn->getParam(0);
        $timestamp = $fn->getParam(1);
        $gmt = $fn->getParam(2);
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $four = $i64->constInt(4, false);
        $ten = $i64->constInt(10, false);
        $hundred = $i64->constInt(100, false);
        $thousand = $i64->constInt(1000, false);
        $yearBase = $i32->constInt(1900, false);

        $fmtLen = $context->builder->load($context->builder->structGep($format, $strMap['length']));
        $fmtChars = $context->builder->structGep($format, $strMap['value']);

        $i64p = $context->getTypeFromString('int64*');
        $tsSlot = $context->builder->alloca($i64, 1, 'ts_slot');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);

        $isGmt = $context->builder->icmp(Builder::INT_NE, $gmt, $i8->constInt(0, false));
        $localBb = $fn->appendBasicBlock('dt_local');
        $utcBb = $fn->appendBasicBlock('dt_utc');
        $mergeBb = $fn->appendBasicBlock('dt_tm_merge');
        $afterTmBb = $fn->appendBasicBlock('dt_after_tm');
        $context->builder->branchIf($isGmt, $utcBb, $localBb);

        $context->builder->positionAtEnd($localBb);
        $localTm = $context->builder->call($context->lookupFunction('localtime'), $tsPtr);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($utcBb);
        $utcTm = $context->builder->call($context->lookupFunction('gmtime'), $tsPtr);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $tmPtr = $context->builder->phi($i8p);
        $tmPtr->addIncoming($localTm, $localBb);
        $tmPtr->addIncoming($utcTm, $utcBb);
        $context->builder->branch($afterTmBb);

        $context->builder->positionAtEnd($afterTmBb);
        $tmYear = self::loadTmField($context, $tmPtr, self::TM_YEAR);
        $tmMon = self::loadTmField($context, $tmPtr, self::TM_MON);
        $tmMday = self::loadTmField($context, $tmPtr, self::TM_MDAY);
        $tmHour = self::loadTmField($context, $tmPtr, self::TM_HOUR);
        $tmMin = self::loadTmField($context, $tmPtr, self::TM_MIN);
        $tmSec = self::loadTmField($context, $tmPtr, self::TM_SEC);
        $year = $context->builder->add($context->builder->zExt($tmYear, $i64), $context->builder->zExt($yearBase, $i64));
        $month = $context->builder->addNoSignedWrap($context->builder->zExt($tmMon, $i64), $one);
        $day = $context->builder->zExt($tmMday, $i64);
        $hour = $context->builder->zExt($tmHour, $i64);
        $minute = $context->builder->zExt($tmMin, $i64);
        $second = $context->builder->zExt($tmSec, $i64);

        $outLenSlot = $context->builder->alloca($i64, 1, 'out_len');
        $context->builder->store($zero, $outLenSlot);
        $outBuf = $context->builder->alloca($i8, self::OUT_BYTES, 'out_buf');
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $iSlot = $context->builder->alloca($i64, 1, 'fmt_i');
        $context->builder->store($zero, $iSlot);

        $head = $fn->appendBasicBlock('fmt_head');
        $body = $fn->appendBasicBlock('fmt_body');
        $done = $fn->appendBasicBlock('fmt_done');
        $emitBb = $fn->appendBasicBlock('fmt_emit');
        $afterBb = $fn->appendBasicBlock('fmt_after');
        $chSlot = $context->builder->alloca($i32, 1, 'fmt_ch');
        $context->builder->branch($head);

        $emitBlocks = self::createEmitBlocks(
            $context,
            $fn,
            $outPtr,
            $outLenSlot,
            $afterBb,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
            $i64,
            $i32,
            $i8,
            $one,
            $ten,
            $hundred,
            $thousand,
            $chSlot
        );

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $i, $fmtLen);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($fmtChars, $i));
        $chI32 = $context->builder->zExt($ch, $i32);
        $isBackslash = $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(0x5C, false));
        $escBb = $fn->appendBasicBlock('fmt_esc');
        $context->builder->branchIf($isBackslash, $escBb, $emitBb);

        $context->builder->positionAtEnd($escBb);
        $nextI = $context->builder->addNoSignedWrap($i, $one);
        $escCh = $context->builder->load($context->builder->gep($fmtChars, $nextI));
        $pos = $context->builder->load($outLenSlot);
        $context->builder->store($escCh, $context->builder->inBoundsGEP($outPtr, $pos));
        $context->builder->store($context->builder->add($pos, $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($nextI, $one), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($emitBb);
        self::branchEmitFormatChar($context, $chI32, $emitBlocks, $chSlot);

        $context->builder->positionAtEnd($afterBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($outLenSlot);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $outPtr
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function loadTmField(Context $context, Value $tmPtr, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $tmFields = $context->builder->pointerCast($tmPtr, $i32p);

        return $context->builder->load(
            $context->builder->gep($tmFields, $i32->constInt((int) ($offset / 4), false))
        );
    }

    /**
     * @return array{plain: \PHPLLVM\BasicBlock, match: array<string, \PHPLLVM\BasicBlock>, fall: array<string, \PHPLLVM\BasicBlock>}
     */
    private static function createEmitBlocks(
        Context $context,
        Value $fn,
        Value $outPtr,
        Value $outLenSlot,
        $afterBb,
        Value $year,
        Value $month,
        Value $day,
        Value $hour,
        Value $minute,
        Value $second,
        $i64,
        $i32,
        $i8,
        Value $one,
        Value $ten,
        Value $hundred,
        Value $thousand,
        Value $chSlot
    ): array {
        $plain = $fn->appendBasicBlock('fmt_plain');
        $mapping = [
            'Y' => [$year, 4],
            'm' => [$month, 2],
            'd' => [$day, 2],
            'H' => [$hour, 2],
            'i' => [$minute, 2],
            's' => [$second, 2],
        ];
        $matchBlocks = [];
        $fallBlocks = [];
        $chars = array_keys($mapping);
        foreach ($mapping as $char => $spec) {
            [$value, $width] = $spec;
            $matchBlocks[$char] = $fn->appendBasicBlock('fmt_'.$char);
            $context->builder->positionAtEnd($matchBlocks[$char]);
            self::writePaddedInt($context, $outPtr, $outLenSlot, $value, $width, $i64, $i8, $ten, $hundred, $thousand);
            $context->builder->branch($afterBb);
            if ($char !== $chars[\count($chars) - 1]) {
                $fallBlocks[$char] = $fn->appendBasicBlock('fmt_fall_'.$char);
            }
        }

        $context->builder->positionAtEnd($plain);
        $pos = $context->builder->load($outLenSlot);
        $ch = $context->builder->load($chSlot);
        $context->builder->store($context->builder->trunc($ch, $i8), $context->builder->inBoundsGEP($outPtr, $pos));
        $context->builder->store($context->builder->add($pos, $one), $outLenSlot);
        $context->builder->branch($afterBb);

        return ['plain' => $plain, 'match' => $matchBlocks, 'fall' => $fallBlocks];
    }

    /** @param array{plain: \PHPLLVM\BasicBlock, match: array<string, \PHPLLVM\BasicBlock>, fall: array<string, \PHPLLVM\BasicBlock>} $blocks */
    private static function branchEmitFormatChar(Context $context, Value $chI32, array $blocks, Value $chSlot): void
    {
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store($chI32, $chSlot);
        $mapping = ['Y', 'm', 'd', 'H', 'i', 's'];
        $fallthrough = null;
        foreach ($mapping as $idx => $char) {
            if (null !== $fallthrough) {
                $context->builder->positionAtEnd($fallthrough);
            }
            $eq = $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(\ord($char), false));
            $onFalse = isset($mapping[$idx + 1]) ? $blocks['fall'][$char] : $blocks['plain'];
            $context->builder->branchIf($eq, $blocks['match'][$char], $onFalse);
            $fallthrough = $blocks['fall'][$char] ?? null;
        }
    }

    private static function writePaddedInt(
        Context $context,
        Value $outPtr,
        Value $outLenSlot,
        Value $value,
        int $numDigits,
        $i64,
        $i8,
        Value $ten,
        Value $hundred,
        Value $thousand
    ): void {
        $pos = $context->builder->load($outLenSlot);
        $ascii0 = $i8->constInt(0x30, false);
        if (4 === $numDigits) {
            $d1 = $context->builder->signedDiv($value, $thousand);
            $r1 = $context->builder->signedRem($value, $thousand);
            $d2 = $context->builder->signedDiv($r1, $hundred);
            $r2 = $context->builder->signedRem($r1, $hundred);
            $d3 = $context->builder->signedDiv($r2, $ten);
            $d4 = $context->builder->signedRem($r2, $ten);
            foreach ([$d1, $d2, $d3, $d4] as $idx => $digit) {
                $context->builder->store(
                    $context->builder->add($ascii0, $context->builder->trunc($digit, $i8)),
                    $context->builder->inBoundsGEP($outPtr, $context->builder->add($pos, $i64->constInt($idx, false)))
                );
            }
            $context->builder->store($context->builder->add($pos, $i64->constInt(4, false)), $outLenSlot);

            return;
        }

        $hi = $context->builder->signedDiv($value, $ten);
        $lo = $context->builder->signedRem($value, $ten);
        $context->builder->store(
            $context->builder->add($ascii0, $context->builder->trunc($hi, $i8)),
            $context->builder->inBoundsGEP($outPtr, $pos)
        );
        $context->builder->store(
            $context->builder->add($ascii0, $context->builder->trunc($lo, $i8)),
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($pos, $i64->constInt(1, false)))
        );
        $context->builder->store($context->builder->add($pos, $i64->constInt(2, false)), $outLenSlot);
    }
}
