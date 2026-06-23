<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__stripslashes (mirrors VmString::stripslashes).
 */
final class StringStripslashes
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__stripslashes');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $backslash = $i8->constInt(92, false);
        $zeroDigit = $i8->constInt(48, false);
        $nulByte = $i8->constInt(0, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $i64, $zero, $one, $two, $backslash);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop(
            $context,
            $fn,
            $srcChars,
            $len,
            $destChars,
            $iSlot,
            $posSlot,
            $i64,
            $zero,
            $one,
            $two,
            $backslash,
            $zeroDigit,
            $nulByte
        );

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
        Value $zero,
        Value $one,
        Value $two,
        Value $backslash
    ): void {
        $head = $fn->appendBasicBlock('stripslashes_count_head');
        $body = $fn->appendBasicBlock('stripslashes_count_body');
        $done = $fn->appendBasicBlock('stripslashes_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $len);
        $isBackslash = $context->builder->icmp(Builder::INT_EQ, $ch, $backslash);
        $canUnescape = $context->builder->and($isBackslash, $hasNext);

        $unescapeBlock = $fn->appendBasicBlock('stripslashes_count_unescape');
        $plainBlock = $fn->appendBasicBlock('stripslashes_count_plain');
        $context->builder->branchIf($canUnescape, $unescapeBlock, $plainBlock);

        $context->builder->positionAtEnd($unescapeBlock);
        $context->builder->store($context->builder->addNoSignedWrap($context->builder->load($outLenSlot), $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($context->builder->addNoSignedWrap($context->builder->load($outLenSlot), $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
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
        Value $zero,
        Value $one,
        Value $two,
        Value $backslash,
        Value $zeroDigit,
        Value $nulByte
    ): void {
        $head = $fn->appendBasicBlock('stripslashes_write_head');
        $body = $fn->appendBasicBlock('stripslashes_write_body');
        $done = $fn->appendBasicBlock('stripslashes_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $len);
        $isBackslash = $context->builder->icmp(Builder::INT_EQ, $ch, $backslash);
        $canUnescape = $context->builder->and($isBackslash, $hasNext);

        $unescapeBlock = $fn->appendBasicBlock('stripslashes_write_unescape');
        $plainBlock = $fn->appendBasicBlock('stripslashes_write_plain');
        $context->builder->branchIf($canUnescape, $unescapeBlock, $plainBlock);

        $context->builder->positionAtEnd($unescapeBlock);
        $nextCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $isZeroDigit = $context->builder->icmp(Builder::INT_EQ, $nextCh, $zeroDigit);
        $unescapedByte = $context->builder->select($isZeroDigit, $nulByte, $nextCh);
        $context->builder->store($unescapedByte, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
