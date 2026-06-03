<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__nl2br (mirrors VmString::nl2br, php-src string.c).
 */
final class StringNl2br
{
    private const ORD_CR = 13;
    private const ORD_LF = 10;

    /** @var list<int> */
    private const BR_XHTML = [60, 98, 114, 32, 47, 62];

    /** @var list<int> */
    private const BR_HTML = [60, 98, 114, 62];

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__nl2br');
        $entry = $fn->appendBasicBlock('nl2br_main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $useXhtml = $fn->getParam(1);

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $cr = $i8->constInt(self::ORD_CR, false);
        $lf = $i8->constInt(self::ORD_LF, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $emptyBlock = $fn->appendBasicBlock('nl2br_empty');
        $countBlock = $fn->appendBasicBlock('nl2br_count');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($isEmpty, $emptyBlock, $countBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->returnValue($src);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($countBlock);
        $replSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $replSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $replSlot, $i64, $i8, $cr, $lf, $zero, $one, $two);

        $unchangedBlock = $fn->appendBasicBlock('nl2br_unchanged');
        $buildBlock = $fn->appendBasicBlock('nl2br_build');
        $replCount = $context->builder->load($replSlot);
        $noRepl = $context->builder->icmp(Builder::INT_EQ, $replCount, $zero);
        $context->builder->branchIf($noRepl, $unchangedBlock, $buildBlock);

        $context->builder->positionAtEnd($unchangedBlock);
        $context->builder->returnValue($src);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBlock);
        $brLen = self::brLength($context, $useXhtml, $i64);
        $outLen = $context->builder->addNoSignedWrap(
            $len,
            $context->builder->mulNoSignedWrap($replCount, $brLen)
        );
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
            $useXhtml,
            $iSlot,
            $posSlot,
            $i64,
            $i8,
            $i1,
            $cr,
            $lf,
            $zero,
            $one,
            $two
        );

        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }

    private static function brLength(Context $context, Value $useXhtml, $i64): Value
    {
        $six = $i64->constInt(6, false);
        $four = $i64->constInt(4, false);
        $use = $context->builder->icmp(Builder::INT_NE, $useXhtml, $context->getTypeFromString('int8')->constInt(0, false));

        return $context->builder->select($use, $six, $four);
    }

    private static function countLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $replSlot,
        $i64,
        $i8,
        Value $cr,
        Value $lf,
        Value $zero,
        Value $one,
        Value $two
    ): void {
        $head = $fn->appendBasicBlock('nl2br_count_head');
        $body = $fn->appendBasicBlock('nl2br_count_body');
        $nlBody = $fn->appendBasicBlock('nl2br_count_nl');
        $plainInc = $fn->appendBasicBlock('nl2br_count_plain_inc');
        $done = $fn->appendBasicBlock('nl2br_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isNl = self::isNewline($context, $ch, $cr, $lf);
        $context->builder->branchIf($isNl, $nlBody, $plainInc);

        $context->builder->positionAtEnd($plainInc);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($nlBody);
        $repl = $context->builder->load($replSlot);
        $context->builder->store($context->builder->addNoSignedWrap($repl, $one), $replSlot);
        $nextI = $context->builder->addNoSignedWrap($i, $one);
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $nextI, $len);
        $nextCh = $context->builder->load($context->builder->gep($srcChars, $nextI));
        $hasPair = self::hasCrlfPair($context, $ch, $nextCh, $hasNext, $cr, $lf);
        $iNext = $context->builder->select($hasPair, $context->builder->addNoSignedWrap($i, $two), $context->builder->addNoSignedWrap($i, $one));
        $context->builder->store($iNext, $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function writeLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $useXhtml,
        Value $iSlot,
        Value $posSlot,
        $i64,
        $i8,
        $i1,
        Value $cr,
        Value $lf,
        Value $zero,
        Value $one,
        Value $two
    ): void {
        $head = $fn->appendBasicBlock('nl2br_write_head');
        $body = $fn->appendBasicBlock('nl2br_write_body');
        $nlBody = $fn->appendBasicBlock('nl2br_write_nl');
        $plainBody = $fn->appendBasicBlock('nl2br_write_plain');
        $afterNl = $fn->appendBasicBlock('nl2br_write_after_nl');
        $done = $fn->appendBasicBlock('nl2br_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isNl = self::isNewline($context, $ch, $cr, $lf);
        $context->builder->branchIf($isNl, $nlBody, $plainBody);

        $context->builder->positionAtEnd($plainBody);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($ch, $context->builder->gep($destChars, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($nlBody);
        $posAfterBr = self::appendBr(
            $context,
            $destChars,
            $context->builder->load($posSlot),
            $useXhtml,
            $i8
        );
        $nextI = $context->builder->addNoSignedWrap($i, $one);
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $nextI, $len);
        $nextCh = $context->builder->load($context->builder->gep($srcChars, $nextI));
        $hasPair = self::hasCrlfPair($context, $ch, $nextCh, $hasNext, $cr, $lf);

        $pairBlock = $fn->appendBasicBlock('nl2br_write_pair');
        $noPairBlock = $fn->appendBasicBlock('nl2br_write_no_pair');
        $context->builder->branchIf($hasPair, $pairBlock, $noPairBlock);

        $context->builder->positionAtEnd($pairBlock);
        $context->builder->store($ch, $context->builder->gep($destChars, $posAfterBr));
        $posPair = $context->builder->addNoSignedWrap($posAfterBr, $one);
        $iNextPair = $context->builder->addNoSignedWrap($i, $two);
        $context->builder->branch($afterNl);

        $context->builder->positionAtEnd($noPairBlock);
        $iNextSingle = $context->builder->addNoSignedWrap($i, $one);
        $context->builder->branch($afterNl);

        $context->builder->positionAtEnd($afterNl);
        $posPhi = $context->builder->phi($i64);
        $posPhi->addIncoming($posPair, $pairBlock);
        $posPhi->addIncoming($posAfterBr, $noPairBlock);
        $chPhi = $context->builder->phi($i8);
        $chPhi->addIncoming($nextCh, $pairBlock);
        $chPhi->addIncoming($ch, $noPairBlock);
        $iNext = $context->builder->phi($i64);
        $iNext->addIncoming($iNextPair, $pairBlock);
        $iNext->addIncoming($iNextSingle, $noPairBlock);

        $context->builder->store($chPhi, $context->builder->gep($destChars, $posPhi));
        $posEnd = $context->builder->addNoSignedWrap($posPhi, $one);
        $context->builder->store($posEnd, $posSlot);
        $context->builder->store($iNext, $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendBr(Context $context, Value $destChars, Value $pos, Value $useXhtml, $i8): Value
    {
        $use = $context->builder->icmp(
            Builder::INT_NE,
            $useXhtml,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $xhtmlBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('nl2br_br_xhtml');
        $htmlBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('nl2br_br_html');
        $merge = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('nl2br_br_merge');

        $context->builder->branchIf($use, $xhtmlBlock, $htmlBlock);

        $context->builder->positionAtEnd($xhtmlBlock);
        $posX = self::storeBytes($context, $destChars, $pos, self::BR_XHTML, $i8);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($htmlBlock);
        $posH = self::storeBytes($context, $destChars, $pos, self::BR_HTML, $i8);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($pos->typeOf());
        $phi->addIncoming($posX, $xhtmlBlock);
        $phi->addIncoming($posH, $htmlBlock);

        return $phi;
    }

    /**
     * @param list<int> $bytes
     */
    private static function storeBytes(Context $context, Value $destChars, Value $pos, array $bytes, $i8): Value
    {
        foreach ($bytes as $idx => $ord) {
            $at = $context->builder->addNoSignedWrap($pos, $context->getTypeFromString('int64')->constInt($idx, false));
            $context->builder->store($i8->constInt($ord, false), $context->builder->gep($destChars, $at));
        }

        return $context->builder->addNoSignedWrap($pos, $context->getTypeFromString('int64')->constInt(\count($bytes), false));
    }

    private static function isNewline(Context $context, Value $ch, Value $cr, Value $lf): Value
    {
        $isCr = $context->builder->icmp(Builder::INT_EQ, $ch, $cr);
        $isLf = $context->builder->icmp(Builder::INT_EQ, $ch, $lf);

        return $context->builder->or($isCr, $isLf);
    }

    private static function hasCrlfPair(
        Context $context,
        Value $ch,
        Value $nextCh,
        Value $hasNext,
        Value $cr,
        Value $lf
    ): Value {
        $pairCrLf = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $ch, $cr),
            $context->builder->icmp(Builder::INT_EQ, $nextCh, $lf)
        );
        $pairLfCr = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $ch, $lf),
            $context->builder->icmp(Builder::INT_EQ, $nextCh, $cr)
        );
        $pair = $context->builder->or($pairCrLf, $pairLfCr);

        return $context->builder->and($hasNext, $pair);
    }
}
