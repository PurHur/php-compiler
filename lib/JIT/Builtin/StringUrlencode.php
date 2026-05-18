<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__urlencode and __string__rawurlencode (RFC 3986 subset).
 */
final class StringUrlencode
{
    public static function implement(Context $context): void
    {
        self::implementFunction($context, '__string__urlencode', true);
        self::implementFunction($context, '__string__rawurlencode', false);
    }

    private static function implementFunction(Context $context, string $name, bool $formEncoding): void
    {
        $fn = $context->lookupFunction($name);
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop(
            $context,
            $fn,
            $srcChars,
            $len,
            $iSlot,
            $outLenSlot,
            $i64,
            $i8,
            $zero,
            $one,
            $three,
            $formEncoding
        );

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
            $i8,
            $zero,
            $one,
            $three,
            $charPtr,
            $formEncoding
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
        $i8,
        Value $zero,
        Value $one,
        Value $three,
        bool $formEncoding
    ): void {
        $head = $fn->appendBasicBlock('urlencode_count_head');
        $body = $fn->appendBasicBlock('urlencode_count_body');
        $done = $fn->appendBasicBlock('urlencode_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $add = self::encodedLen($context, $ch, $one, $three, $i64, $i8, $formEncoding);
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
        $i8,
        Value $zero,
        Value $one,
        Value $three,
        $charPtr,
        bool $formEncoding
    ): void {
        $head = $fn->appendBasicBlock('urlencode_write_head');
        $body = $fn->appendBasicBlock('urlencode_write_body');
        $done = $fn->appendBasicBlock('urlencode_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $step = self::writeEncoded(
            $context,
            $fn,
            $destAt,
            $ch,
            $one,
            $three,
            $i8,
            $i64,
            $charPtr,
            $formEncoding
        );
        $context->builder->store($context->builder->addNoSignedWrap($pos, $step), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function encodedLen(
        Context $context,
        Value $ch,
        Value $one,
        Value $three,
        $i64,
        $i8,
        bool $formEncoding
    ): Value {
        $unreserved = self::isUnreserved($context, $ch, $i64);
        $len = $context->builder->select($unreserved, $one, $three);

        if ($formEncoding) {
            $space = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $i8->constInt(ord(' '), false)
            );
            $len = $context->builder->select($space, $one, $len);
        }

        return $len;
    }

    private static function writeEncoded(
        Context $context,
        Value $fn,
        Value $destAt,
        Value $ch,
        Value $one,
        Value $three,
        $i8,
        $i64,
        $charPtr,
        bool $formEncoding
    ): Value {
        $unreserved = self::isUnreserved($context, $ch, $i64);
        $plainBlock = $fn->appendBasicBlock('urlencode_plain');
        $encodedBlock = $fn->appendBasicBlock('urlencode_encoded');
        $mergeBlock = $fn->appendBasicBlock('urlencode_merge');

        if ($formEncoding) {
            $space = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $i8->constInt(ord(' '), false)
            );
            $spaceBlock = $fn->appendBasicBlock('urlencode_space');
            $notSpaceBlock = $fn->appendBasicBlock('urlencode_not_space');
            $context->builder->branchIf($unreserved, $plainBlock, $notSpaceBlock);

            $context->builder->positionAtEnd($notSpaceBlock);
            $context->builder->branchIf($space, $spaceBlock, $encodedBlock);

            $context->builder->positionAtEnd($spaceBlock);
            $context->builder->store($i8->constInt(ord('+'), false), $destAt);
            $context->builder->branch($mergeBlock);
        } else {
            $context->builder->branchIf($unreserved, $plainBlock, $encodedBlock);
        }

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($encodedBlock);
        $context->builder->store($i8->constInt(ord('%'), false), $destAt);
        $hexAt = $context->builder->gep($destAt, $one);
        self::writeHexByte($context, $ch, $hexAt, $i8, $i64, $charPtr);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return self::encodedLen($context, $ch, $one, $three, $i64, $i8, $formEncoding);
    }

    private static function writeHexByte(
        Context $context,
        Value $ch,
        Value $destAt,
        $i8,
        $i64,
        $charPtr
    ): void {
        $ord = $context->builder->zExt($ch, $i64);
        $high = $context->builder->lShr($ord, $i64->constInt(4, false));
        $low = $context->builder->and($ord, $i64->constInt(0x0F, false));
        $hexTable = $context->constantFromString('0123456789ABCDEF');
        $hexPtr = $context->builder->pointerCast($hexTable, $charPtr);
        $hiCh = $context->builder->load($context->builder->gep($hexPtr, $high));
        $loCh = $context->builder->load($context->builder->gep($hexPtr, $low));
        $context->builder->store($hiCh, $destAt);
        $context->builder->store($loCh, $context->builder->gep($destAt, $i64->constInt(1, false)));
    }

    private static function isUnreserved(Context $context, Value $ch, $i64): Value
    {
        $ord = $context->builder->zExt($ch, $i64);
        $zero = $i64->constInt(ord('0'), false);
        $nine = $i64->constInt(ord('9'), false);
        $upperA = $i64->constInt(ord('A'), false);
        $upperZ = $i64->constInt(ord('Z'), false);
        $lowerA = $i64->constInt(ord('a'), false);
        $lowerZ = $i64->constInt(ord('z'), false);

        $digit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $zero),
            $context->builder->icmp(Builder::INT_SLE, $ord, $nine)
        );
        $upper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $upperA),
            $context->builder->icmp(Builder::INT_SLE, $ord, $upperZ)
        );
        $lower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $lowerA),
            $context->builder->icmp(Builder::INT_SLE, $ord, $lowerZ)
        );
        $alnum = $context->builder->or(
            $context->builder->or($digit, $upper),
            $lower
        );

        $dash = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('-'), false));
        $under = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('_'), false));
        $dot = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('.'), false));
        $tilde = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('~'), false));

        return $context->builder->or(
            $context->builder->or(
                $context->builder->or($alnum, $dash),
                $under
            ),
            $context->builder->or($dot, $tilde)
        );
    }
}
