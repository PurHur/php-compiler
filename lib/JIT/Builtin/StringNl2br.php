<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__nl2br (inserts &lt;br /&gt; or &lt;br&gt; before LF, matching VmString::nl2br).
 */
final class StringNl2br
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__nl2br');
        $entry = $fn->appendBasicBlock('nl2br_main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $useXhtmlI8 = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $newlineOrd = $i64->constInt(10, false);

        $useXhtmlBool = $context->builder->icmp(
            Builder::INT_NE,
            $useXhtmlI8,
            $i8->constInt(0, false)
        );
        $brLen = $context->builder->select(
            $useXhtmlBool,
            $i64->constInt(6, false),
            $i64->constInt(4, false)
        );
        $nlOutStep = $context->builder->addNoSignedWrap($brLen, $one);

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
            $zero,
            $one,
            $newlineOrd,
            $nlOutStep
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
            $zero,
            $one,
            $newlineOrd,
            $brLen,
            $nlOutStep,
            $useXhtmlBool,
            $charPtr
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
        Value $newlineOrd,
        Value $nlOutStep
    ): void {
        $head = $fn->appendBasicBlock('nl2br_count_head');
        $body = $fn->appendBasicBlock('nl2br_count_body');
        $done = $fn->appendBasicBlock('nl2br_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isNl = $context->builder->icmp(Builder::INT_EQ, $chI64, $newlineOrd);
        $add = $context->builder->select($isNl, $nlOutStep, $one);
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
        Value $newlineOrd,
        Value $brLen,
        Value $nlOutStep,
        Value $useXhtmlBool,
        $charPtr
    ): void {
        $head = $fn->appendBasicBlock('nl2br_write_head');
        $body = $fn->appendBasicBlock('nl2br_write_body');
        $done = $fn->appendBasicBlock('nl2br_write_done');

        $nlBody = $fn->appendBasicBlock('nl2br_write_nl');
        $nlXhtml = $fn->appendBasicBlock('nl2br_write_nl_xhtml');
        $nlHtml = $fn->appendBasicBlock('nl2br_write_nl_html');
        $nlAfterMemcpy = $fn->appendBasicBlock('nl2br_write_nl_after_memcpy');
        $plainBody = $fn->appendBasicBlock('nl2br_write_plain');
        $iterEnd = $fn->appendBasicBlock('nl2br_write_iter_end');

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
        $isNl = $context->builder->icmp(Builder::INT_EQ, $chI64, $newlineOrd);
        $context->builder->branchIf($isNl, $nlBody, $plainBody);

        $context->builder->positionAtEnd($nlBody);
        $context->builder->branchIf($useXhtmlBool, $nlXhtml, $nlHtml);

        $context->builder->positionAtEnd($nlXhtml);
        $srcBrX = $context->builder->pointerCast($context->constantFromString('<br />'), $charPtr);
        $context->intrinsic->memcpy($destAt, $srcBrX, $i64->constInt(6, false), false);
        $context->builder->branch($nlAfterMemcpy);

        $context->builder->positionAtEnd($nlHtml);
        $srcBrH = $context->builder->pointerCast($context->constantFromString('<br>'), $charPtr);
        $context->intrinsic->memcpy($destAt, $srcBrH, $i64->constInt(4, false), false);
        $context->builder->branch($nlAfterMemcpy);

        $context->builder->positionAtEnd($nlAfterMemcpy);
        $chWriteAt = $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $brLen));
        $context->builder->store($ch, $chWriteAt);
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $nlOutStep),
            $posSlot
        );
        $context->builder->branch($iterEnd);

        $context->builder->positionAtEnd($plainBody);
        $context->builder->store($ch, $destAt);
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $one),
            $posSlot
        );
        $context->builder->branch($iterEnd);

        $context->builder->positionAtEnd($iterEnd);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
