<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for thin-standalone AOT htmlspecialchars — escape loop (#19389, #20141).
 *
 * Used when {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()} so nested
 * {@see HtmlspecialcharsJitHelper} is not ExternalMethod-stubbed under minimal init (#16075).
 * Mirrors {@see HtmlspecialcharsJitHelper} ASCII specials (&amp; &lt; &gt; &quot; &#039;/&apos;).
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars)
 */
final class JitHtmlspecialcharsKernel
{
    /**
     * Emit htmlspecialchars escape loop; builder must be positioned at the entry block.
     *
     * ABI: __string__* (__string__* str, int64 flags)
     */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $input = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $lenI64 = $context->builder->zExt($len, $i64);
        // Worst case: each byte → 6-char entity (&#039;)
        $cap = $context->builder->mul($lenI64, $i64->constInt(6, false));
        $outStr = $context->builder->call($context->lookupFunction('__string__alloc'), $cap);
        $srcPtr = $context->builder->structGep($input, $map['value']);
        $destPtr = $context->builder->structGep($outStr, $map['value']);

        $ampLit = $context->builder->pointerCast($context->constantFromString('&amp;'), $charPtr);
        $ltLit = $context->builder->pointerCast($context->constantFromString('&lt;'), $charPtr);
        $gtLit = $context->builder->pointerCast($context->constantFromString('&gt;'), $charPtr);
        $quotLit = $context->builder->pointerCast($context->constantFromString('&quot;'), $charPtr);
        $aposLit = $context->builder->pointerCast($context->constantFromString('&apos;'), $charPtr);
        $hashLit = $context->builder->pointerCast($context->constantFromString('&#039;'), $charPtr);

        $entQuotes = $i64->constInt(3, false); // ENT_QUOTES
        $entCompat = $i64->constInt(2, false); // ENT_COMPAT
        $entHtml5 = $i64->constInt(48, false); // ENT_HTML5
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $flagsAndQuotes = $context->builder->bitwiseAnd($flags, $entQuotes);
        $quoteBoth = $context->builder->icmp(Builder::INT_EQ, $flagsAndQuotes, $entQuotes);
        $flagsAndCompat = $context->builder->bitwiseAnd($flags, $entCompat);

        $flagsAndHtml5 = $context->builder->bitwiseAnd($flags, $entHtml5);
        $useApos = $context->builder->icmp(Builder::INT_NE, $flagsAndHtml5, $zero);

        $idxSlot = $context->builder->alloca($i64, 1, 'hs_idx');
        $outSlot = $context->builder->alloca($i64, 1, 'hs_out');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outSlot);

        $loopHead = $fn->appendBasicBlock('hs_kernel_head');
        $loopBody = $fn->appendBasicBlock('hs_kernel_body');
        $loopDone = $fn->appendBasicBlock('hs_kernel_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $lenI64);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($srcPtr, $idx));
        $byteI64 = $context->builder->zExt($byte, $i64);
        $outPos = $context->builder->load($outSlot);

        $isAmp = $context->builder->icmp(Builder::INT_EQ, $byteI64, $i64->constInt(0x26, false));
        $isLt = $context->builder->icmp(Builder::INT_EQ, $byteI64, $i64->constInt(0x3C, false));
        $isGt = $context->builder->icmp(Builder::INT_EQ, $byteI64, $i64->constInt(0x3E, false));
        $isQuot = $context->builder->icmp(Builder::INT_EQ, $byteI64, $i64->constInt(0x22, false));
        $isAposCh = $context->builder->icmp(Builder::INT_EQ, $byteI64, $i64->constInt(0x27, false));

        $bbAmp = $fn->appendBasicBlock('hs_amp');
        $bbNotAmp = $fn->appendBasicBlock('hs_not_amp');
        $bbLt = $fn->appendBasicBlock('hs_lt');
        $bbNotLt = $fn->appendBasicBlock('hs_not_lt');
        $bbGt = $fn->appendBasicBlock('hs_gt');
        $bbNotGt = $fn->appendBasicBlock('hs_not_gt');
        $bbQuot = $fn->appendBasicBlock('hs_quot');
        $bbNotQuot = $fn->appendBasicBlock('hs_not_quot');
        $bbApos = $fn->appendBasicBlock('hs_apos');
        $bbRaw = $fn->appendBasicBlock('hs_raw');
        $bbNext = $fn->appendBasicBlock('hs_next');

        $context->builder->branchIf($isAmp, $bbAmp, $bbNotAmp);

        $context->builder->positionAtEnd($bbAmp);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $ampLit, 5, $outSlot, $bbNext);

        $context->builder->positionAtEnd($bbNotAmp);
        $context->builder->branchIf($isLt, $bbLt, $bbNotLt);

        $context->builder->positionAtEnd($bbLt);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $ltLit, 4, $outSlot, $bbNext);

        $context->builder->positionAtEnd($bbNotLt);
        $context->builder->branchIf($isGt, $bbGt, $bbNotGt);

        $context->builder->positionAtEnd($bbGt);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $gtLit, 4, $outSlot, $bbNext);

        $context->builder->positionAtEnd($bbNotGt);
        $context->builder->branchIf($isQuot, $bbQuot, $bbNotQuot);

        $context->builder->positionAtEnd($bbQuot);
        $bbQuotEsc = $fn->appendBasicBlock('hs_quot_esc');
        $bbQuotRaw = $fn->appendBasicBlock('hs_quot_raw');
        // Escape " when ENT_COMPAT bit set (covers ENT_COMPAT and ENT_QUOTES)
        $escQuot = $context->builder->icmp(Builder::INT_NE, $flagsAndCompat, $zero);
        $context->builder->branchIf($escQuot, $bbQuotEsc, $bbQuotRaw);
        $context->builder->positionAtEnd($bbQuotEsc);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $quotLit, 6, $outSlot, $bbNext);
        $context->builder->positionAtEnd($bbQuotRaw);
        self::emitCopyByte($context, $destPtr, $outPos, $byte, $outSlot, $one, $bbNext);

        $context->builder->positionAtEnd($bbNotQuot);
        $context->builder->branchIf($isAposCh, $bbApos, $bbRaw);

        $context->builder->positionAtEnd($bbApos);
        $bbAposEsc = $fn->appendBasicBlock('hs_apos_esc');
        $bbAposRaw = $fn->appendBasicBlock('hs_apos_raw');
        $bbAposHtml5 = $fn->appendBasicBlock('hs_apos_html5');
        $bbAposHash = $fn->appendBasicBlock('hs_apos_hash');
        $context->builder->branchIf($quoteBoth, $bbAposEsc, $bbAposRaw);
        $context->builder->positionAtEnd($bbAposEsc);
        $context->builder->branchIf($useApos, $bbAposHtml5, $bbAposHash);
        $context->builder->positionAtEnd($bbAposHtml5);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $aposLit, 6, $outSlot, $bbNext);
        $context->builder->positionAtEnd($bbAposHash);
        self::emitCopyLit($context, $fn, $destPtr, $outPos, $hashLit, 6, $outSlot, $bbNext);
        $context->builder->positionAtEnd($bbAposRaw);
        self::emitCopyByte($context, $destPtr, $outPos, $byte, $outSlot, $one, $bbNext);

        $context->builder->positionAtEnd($bbRaw);
        self::emitCopyByte($context, $destPtr, $outPos, $byte, $outSlot, $one, $bbNext);

        $context->builder->positionAtEnd($bbNext);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $finalLen = $context->builder->load($outSlot);
        $context->builder->store($finalLen, $context->builder->structGep($outStr, $map['length']));
        $context->builder->returnValue($outStr);
    }

    private static function emitCopyLit(
        Context $context,
        LlvmFunction $fn,
        Value $destPtr,
        Value $outPos,
        Value $lit,
        int $litLen,
        Value $outSlot,
        $nextBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $n = $i64->constInt($litLen, false);
        $copyIdx = $context->builder->alloca($i64, 1, 'hs_lit_i');
        $context->builder->store($i64->constInt(0, false), $copyIdx);
        $head = $fn->appendBasicBlock('hs_lit_head');
        $body = $fn->appendBasicBlock('hs_lit_body');
        $done = $fn->appendBasicBlock('hs_lit_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($copyIdx);
        $stop = $context->builder->icmp(Builder::INT_SGE, $i, $n);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($lit, $i));
        $destAt = $context->builder->add($outPos, $i);
        $context->builder->store($ch, $context->builder->gep($destPtr, $destAt));
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $copyIdx);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($context->builder->add($outPos, $n), $outSlot);
        $context->builder->branch($nextBb);
    }

    private static function emitCopyByte(
        Context $context,
        Value $destPtr,
        Value $outPos,
        Value $byte,
        Value $outSlot,
        Value $one,
        $nextBb
    ): void {
        $context->builder->store($byte, $context->builder->gep($destPtr, $outPos));
        $context->builder->store($context->builder->add($outPos, $one), $outSlot);
        $context->builder->branch($nextBb);
    }
}
