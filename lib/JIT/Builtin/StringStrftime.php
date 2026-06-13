<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_strftime — gmtime/localtime + libc strftime (#3692).
 *
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(strftime), PHP_FUNCTION(gmstrftime)
 */
final class StringStrftime
{
    private const OUT_BYTES = 256;

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_strftime');
        $entry = $fn->appendBasicBlock('strftime_entry');
        $context->builder->positionAtEnd($entry);

        $format = $fn->getParam(0);
        $timestamp = $fn->getParam(1);
        $gmt = $fn->getParam(2);
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64p = $context->getTypeFromString('int64*');

        $fmtChars = $context->builder->structGep($format, $strMap['value']);
        $fmtPtr = $context->builder->pointerCast($fmtChars, $charPtr);

        $tsSlot = $context->builder->alloca($i64, 1, 'strftime_ts');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);

        $isGmt = $context->builder->icmp(Builder::INT_NE, $gmt, $i8->constInt(0, false));
        $localBb = $fn->appendBasicBlock('strftime_local');
        $utcBb = $fn->appendBasicBlock('strftime_utc');
        $mergeBb = $fn->appendBasicBlock('strftime_tm_merge');
        $afterTmBb = $fn->appendBasicBlock('strftime_after_tm');
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
        $outBuf = $context->builder->alloca($i8, self::OUT_BYTES, 'strftime_buf');
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $written = $context->builder->call(
            $context->lookupFunction('strftime'),
            $outPtr,
            $sizeT->constInt(self::OUT_BYTES, false),
            $fmtPtr,
            $tmPtr
        );
        $writtenI64 = $context->builder->zExt($written, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $writtenI64,
            $outPtr
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }
}
