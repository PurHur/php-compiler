<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__preg_quote (mirrors VmString::pregQuote).
 */
final class StringPregQuote
{
    /** @var list<int> */
    private const ESCAPE_ORDS = [
        46, 92, 43, 42, 63, 91, 94, 93, 40, 41, 36, 61, 123, 125, 45, 124, 33, 60, 62, 58,
    ];

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__preg_quote');
        $strPtr = $context->getTypeFromString('__string__*');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $delimiter = $fn->getParam(1);

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $backslash = $i8->constInt(92, false);
        $zeroChar = $i8->constInt(48, false);

        $i1 = $context->getTypeFromString('int1');
        $delimNull = $context->builder->icmp(Builder::INT_EQ, $delimiter, $strPtr->constNull());
        $noDelimBlock = $fn->appendBasicBlock('preg_quote_no_delim');
        $checkDelimLenBlock = $fn->appendBasicBlock('preg_quote_check_delim_len');
        $useDelimBlock = $fn->appendBasicBlock('preg_quote_use_delim');
        $delimReady = $fn->appendBasicBlock('preg_quote_delim_ready');
        $context->builder->branchIf($delimNull, $noDelimBlock, $checkDelimLenBlock);

        $context->builder->positionAtEnd($noDelimBlock);
        $context->builder->branch($delimReady);

        $context->builder->positionAtEnd($checkDelimLenBlock);
        $delimLenVal = $context->builder->load($context->builder->structGep($delimiter, $map['length']));
        $delimEmpty = $context->builder->icmp(Builder::INT_EQ, $delimLenVal, $zero);
        $context->builder->branchIf($delimEmpty, $noDelimBlock, $useDelimBlock);

        $context->builder->positionAtEnd($useDelimBlock);
        $delimChLoaded = $context->builder->load($context->builder->structGep($delimiter, $map['value']));
        $context->builder->branch($delimReady);

        $context->builder->positionAtEnd($delimReady);
        $hasDelim = $context->builder->phi($i1);
        $hasDelim->addIncoming($i1->constInt(0, false), $noDelimBlock);
        $hasDelim->addIncoming($i1->constInt(1, false), $useDelimBlock);

        $delimCh = $context->builder->phi($i8);
        $delimCh->addIncoming($i8->constInt(0, false), $noDelimBlock);
        $delimCh->addIncoming($delimChLoaded, $useDelimBlock);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $hasDelim, $delimCh, $i64, $zero, $one, $two, $four);

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
            $hasDelim,
            $delimCh,
            $i64,
            $zero,
            $one,
            $two,
            $three,
            $four,
            $backslash,
            $zeroChar
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
        Value $hasDelim,
        Value $delimCh,
        $i64,
        Value $zero,
        Value $one,
        Value $two,
        Value $four
    ): void {
        $head = $fn->appendBasicBlock('preg_quote_count_head');
        $body = $fn->appendBasicBlock('preg_quote_count_body');
        $done = $fn->appendBasicBlock('preg_quote_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $ch->typeOf()->constInt(0, false));
        $nulBlock = $fn->appendBasicBlock('preg_quote_count_nul');
        $metaBlock = $fn->appendBasicBlock('preg_quote_count_meta');
        $afterBlock = $fn->appendBasicBlock('preg_quote_count_after');
        $context->builder->branchIf($isNul, $nulBlock, $metaBlock);

        $context->builder->positionAtEnd($nulBlock);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $four), $outLenSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($metaBlock);
        $chI64 = $context->builder->zExt($ch, $i64);
        $escape = self::shouldEscape($context, $chI64, $ch, $hasDelim, $delimCh);
        $add = $context->builder->select($escape, $two, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $add), $outLenSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($afterBlock);
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
        Value $hasDelim,
        Value $delimCh,
        $i64,
        Value $zero,
        Value $one,
        Value $two,
        Value $three,
        Value $four,
        Value $backslash,
        Value $zeroChar
    ): void {
        $head = $fn->appendBasicBlock('preg_quote_write_head');
        $body = $fn->appendBasicBlock('preg_quote_write_body');
        $done = $fn->appendBasicBlock('preg_quote_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $ch->typeOf()->constInt(0, false));
        $nulBlock = $fn->appendBasicBlock('preg_quote_write_nul');
        $metaBlock = $fn->appendBasicBlock('preg_quote_write_meta');
        $afterBlock = $fn->appendBasicBlock('preg_quote_write_after');
        $context->builder->branchIf($isNul, $nulBlock, $metaBlock);

        $context->builder->positionAtEnd($nulBlock);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($backslash, $context->builder->gep($destChars, $pos));
        $context->builder->store($zeroChar, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($zeroChar, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $two)));
        $context->builder->store($zeroChar, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $three)));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $four), $posSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($metaBlock);
        $chI64 = $context->builder->zExt($ch, $i64);
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $escape = self::shouldEscape($context, $chI64, $ch, $hasDelim, $delimCh);

        $escapedBlock = $fn->appendBasicBlock('preg_quote_write_escaped');
        $plainBlock = $fn->appendBasicBlock('preg_quote_write_plain');
        $metaAfterBlock = $fn->appendBasicBlock('preg_quote_write_meta_after');
        $context->builder->branchIf($escape, $escapedBlock, $plainBlock);

        $context->builder->positionAtEnd($escapedBlock);
        $context->builder->store($backslash, $destAt);
        $context->builder->store($ch, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $two), $posSlot);
        $context->builder->branch($metaAfterBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($metaAfterBlock);

        $context->builder->positionAtEnd($metaAfterBlock);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($afterBlock);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function shouldEscape(
        Context $context,
        Value $chI64,
        Value $ch,
        Value $hasDelim,
        Value $delimCh
    ): Value {
        $i64 = $chI64->typeOf();
        $escape = $context->getTypeFromString('int1')->constInt(0, false);
        foreach (self::ESCAPE_ORDS as $ord) {
            $match = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false));
            $escape = $context->builder->or($escape, $match);
        }
        $delimMatch = $context->builder->icmp(Builder::INT_EQ, $ch, $delimCh);
        $escape = $context->builder->or($escape, $context->builder->and($hasDelim, $delimMatch));

        return $escape;
    }
}
