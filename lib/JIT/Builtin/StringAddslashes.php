<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__addslashes (mirrors VmString::addslashes).
 */
final class StringAddslashes
{
    /** @var list<int> */
    private const ESCAPE_ORDS = [92, 39, 34, 0];

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__addslashes');
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

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $i64, $zero, $one, $two);

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
        Value $two
    ): void {
        $head = $fn->appendBasicBlock('addslashes_count_head');
        $body = $fn->appendBasicBlock('addslashes_count_body');
        $done = $fn->appendBasicBlock('addslashes_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $escape = self::shouldEscape($context, $chI64);
        $add = $context->builder->select($escape, $two, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $add), $outLenSlot);
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
        $head = $fn->appendBasicBlock('addslashes_write_head');
        $body = $fn->appendBasicBlock('addslashes_write_body');
        $done = $fn->appendBasicBlock('addslashes_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $escape = self::shouldEscape($context, $chI64);

        $escapedBlock = $fn->appendBasicBlock('addslashes_write_escaped');
        $plainBlock = $fn->appendBasicBlock('addslashes_write_plain');
        $afterBlock = $fn->appendBasicBlock('addslashes_write_after');
        $context->builder->branchIf($escape, $escapedBlock, $plainBlock);

        $context->builder->positionAtEnd($escapedBlock);
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $nulByte);
        $escapedByte = $context->builder->select($isNul, $zeroDigit, $ch);
        $context->builder->store($backslash, $destAt);
        $context->builder->store($escapedByte, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $two), $posSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($afterBlock);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function shouldEscape(Context $context, Value $chI64): Value
    {
        $i64 = $chI64->typeOf();
        $escape = $context->getTypeFromString('int1')->constInt(0, false);
        foreach (self::ESCAPE_ORDS as $ord) {
            $match = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false));
            $escape = $context->builder->or($escape, $match);
        }

        return $escape;
    }
}
