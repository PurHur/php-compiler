<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native thin-AOT htmlspecialchars — no NestedJIT HtmlspecialcharsJitHelper (#36388).
 *
 * Stale helper-runtime TUs still NestedJIT {@code __string__htmlspecialchars} (leaky
 * recursive FUNCCALL temps under thin AOT — ~21 MB / 2k short-lived results). Call
 * sites use {@code phpc_htmlspecialchars_r1} / {@code _ex_r1} so this body always
 * wins — peer {@see ChunkSplitRuntime} / {@see StrPadRuntime}.
 *
 * Always returns a freshly allocated {@code __string__*} so FUNCCALL temps free
 * on unset. Escape subset mirrors {@see \PHPCompiler\ext\standard\HtmlspecialcharsJitHelper}
 * / php-src ext/standard/html.c — PHP_FUNCTION(htmlspecialchars).
 */
final class HtmlspecialcharsRuntime
{
    public const ABI = 'phpc_htmlspecialchars_r1';

    public const ABI_EX = 'phpc_htmlspecialchars_ex_r1';

    public const BRIDGE_ENTRY = 'htmlspecialchars_r1_bridge_entry';

    public const BRIDGE_ENTRY_EX = 'htmlspecialchars_ex_r1_bridge_entry';

    private const ENT_COMPAT = 2;

    private const ENT_QUOTES = 3;

    private const ENT_IGNORE = 4;

    private const ENT_HTML5 = 48;

    private const ENT_XML1 = 16;

    private const ENT_XHTML = 32;

    private const ENT_DISALLOWED = 128;

    private static int $seq = 0;

    /**
     * Emit full htmlspecialchars bridge body (builder already at entry). Ends with returnValue.
     *
     * Params: (input, flags) or (input, flags, double_encode) when $withDoubleEncode.
     */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn, bool $withDoubleEncode): void
    {
        self::ensureDecls($context);
        ++self::$seq;
        $tag = ($withDoubleEncode ? 'hsx_' : 'hs_').(string) self::$seq;

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $six = $i64->constInt(6, false);

        $posPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $outOffPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $widthPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $entLenPtr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);

        $input = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $doubleEncode = $withDoubleEncode ? $fn->getParam(2) : $one;

        $inputLen = $context->builder->load($context->builder->structGep($input, $map['length']));
        $inputData = $context->builder->structGep($input, $map['value']);

        $emptyBb = $fn->appendBasicBlock($tag.'_empty');
        $workBb = $fn->appendBasicBlock($tag.'_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $inputLen, $zero),
            $emptyBb,
            $workBb
        );

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__alloc'), $zero)
        );

        $context->builder->positionAtEnd($workBb);
        $cap = $context->builder->mul($inputLen, $six);
        $out = $context->builder->call($context->lookupFunction('__string__alloc'), $cap);
        $context->intrinsic->builder = $context->builder;
        $outData = $context->builder->structGep($out, $map['value']);
        $context->builder->store($zero, $posPtr);
        $context->builder->store($zero, $outOffPtr);

        $head = $fn->appendBasicBlock($tag.'_h');
        $body = $fn->appendBasicBlock($tag.'_b');
        $done = $fn->appendBasicBlock($tag.'_d');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $pos, $inputLen),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($inputData, $pos));
        $chU = $context->builder->zExt($ch, $i64);

        $ampBb = $fn->appendBasicBlock($tag.'_amp');
        $ltBb = $fn->appendBasicBlock($tag.'_lt');
        $gtBb = $fn->appendBasicBlock($tag.'_gt');
        $quotBb = $fn->appendBasicBlock($tag.'_quot');
        $aposBb = $fn->appendBasicBlock($tag.'_apos');
        $otherBb = $fn->appendBasicBlock($tag.'_oth');
        $afterBb = $fn->appendBasicBlock($tag.'_aft');

        $sw1 = $fn->appendBasicBlock($tag.'_sw1');
        $sw2 = $fn->appendBasicBlock($tag.'_sw2');
        $sw3 = $fn->appendBasicBlock($tag.'_sw3');
        $sw4 = $fn->appendBasicBlock($tag.'_sw4');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(38, false)),
            $ampBb,
            $sw1
        );
        $context->builder->positionAtEnd($sw1);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(60, false)),
            $ltBb,
            $sw2
        );
        $context->builder->positionAtEnd($sw2);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(62, false)),
            $gtBb,
            $sw3
        );
        $context->builder->positionAtEnd($sw3);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(34, false)),
            $quotBb,
            $sw4
        );
        $context->builder->positionAtEnd($sw4);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(39, false)),
            $aposBb,
            $otherBb
        );

        self::emitAmp($context, $fn, $tag, $inputData, $inputLen, $pos, $posPtr, $outData, $outOffPtr, $entLenPtr, $doubleEncode, $ampBb, $afterBb, $one);
        self::emitLitAdvance($context, $ltBb, $outData, $outOffPtr, $pos, $posPtr, $afterBb, $one, '&lt;');
        self::emitLitAdvance($context, $gtBb, $outData, $outOffPtr, $pos, $posPtr, $afterBb, $one, '&gt;');
        self::emitQuote($context, $fn, $tag, $flags, $ch, $pos, $posPtr, $outData, $outOffPtr, $quotBb, $afterBb, $one, $zero, $i64);
        self::emitApos($context, $fn, $tag, $flags, $ch, $pos, $posPtr, $outData, $outOffPtr, $aposBb, $afterBb, $one, $zero, $i64);
        self::emitOther(
            $context,
            $fn,
            $tag,
            $flags,
            $ch,
            $chU,
            $pos,
            $posPtr,
            $inputData,
            $inputLen,
            $outData,
            $outOffPtr,
            $widthPtr,
            $otherBb,
            $afterBb,
            $one,
            $zero,
            $i64
        );

        $context->builder->positionAtEnd($afterBb);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $finalLen = $context->builder->load($outOffPtr);
        $context->builder->store($finalLen, $context->builder->structGep($out, $map['length']));
        $context->builder->returnValue($out);
    }

    private static function emitAmp(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $inputData,
        Value $inputLen,
        Value $pos,
        Value $posPtr,
        Value $outData,
        Value $outOffPtr,
        Value $entLenPtr,
        Value $doubleEncode,
        $ampBb,
        $afterBb,
        Value $one
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $context->builder->positionAtEnd($ampBb);
        $preserve = $fn->appendBasicBlock($tag.'_amp_p');
        $encode = $fn->appendBasicBlock($tag.'_amp_e');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $doubleEncode, $zero),
            $preserve,
            $encode
        );

        $context->builder->positionAtEnd($preserve);
        self::emitExistingEntityLen($context, $fn, $tag.'_el', $inputData, $inputLen, $pos, $entLenPtr);
        $entLen = $context->builder->load($entLenPtr);
        $hit = $fn->appendBasicBlock($tag.'_amp_hit');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $entLen, $zero),
            $hit,
            $encode
        );
        $context->builder->positionAtEnd($hit);
        self::emitCopyBytes($context, $inputData, $outData, $outOffPtr, $pos, $entLen);
        $context->builder->store($context->builder->add($pos, $entLen), $posPtr);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($encode);
        self::emitWriteLit($context, $outData, $outOffPtr, '&amp;');
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
    }

    private static function emitLitAdvance(
        Context $context,
        $bb,
        Value $outData,
        Value $outOffPtr,
        Value $pos,
        Value $posPtr,
        $afterBb,
        Value $one,
        string $lit
    ): void {
        $context->builder->positionAtEnd($bb);
        self::emitWriteLit($context, $outData, $outOffPtr, $lit);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
    }

    private static function emitQuote(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $flags,
        Value $ch,
        Value $pos,
        Value $posPtr,
        Value $outData,
        Value $outOffPtr,
        $quotBb,
        $afterBb,
        Value $one,
        Value $zero,
        Type $i64
    ): void {
        $context->builder->positionAtEnd($quotBb);
        $flagsAndQuotes = $context->builder->and($flags, $i64->constInt(self::ENT_QUOTES, false));
        $quoteBoth = $context->builder->icmp(
            Builder::INT_EQ,
            $flagsAndQuotes,
            $i64->constInt(self::ENT_QUOTES, false)
        );
        $flagsAndCompat = $context->builder->and($flags, $i64->constInt(self::ENT_COMPAT, false));
        $hasCompat = $context->builder->icmp(Builder::INT_NE, $flagsAndCompat, $zero);
        $quoteDouble = $context->builder->and($context->builder->not($quoteBoth), $hasCompat);
        $escapeQuot = $context->builder->or($quoteBoth, $quoteDouble);
        $esc = $fn->appendBasicBlock($tag.'_q_esc');
        $raw = $fn->appendBasicBlock($tag.'_q_raw');
        $context->builder->branchIf($escapeQuot, $esc, $raw);
        $context->builder->positionAtEnd($esc);
        self::emitWriteLit($context, $outData, $outOffPtr, '&quot;');
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($raw);
        self::emitWriteByte($context, $outData, $outOffPtr, $ch);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
    }

    private static function emitApos(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $flags,
        Value $ch,
        Value $pos,
        Value $posPtr,
        Value $outData,
        Value $outOffPtr,
        $aposBb,
        $afterBb,
        Value $one,
        Value $zero,
        Type $i64
    ): void {
        $context->builder->positionAtEnd($aposBb);
        $flagsAndQuotes = $context->builder->and($flags, $i64->constInt(self::ENT_QUOTES, false));
        $quoteBoth = $context->builder->icmp(
            Builder::INT_EQ,
            $flagsAndQuotes,
            $i64->constInt(self::ENT_QUOTES, false)
        );
        $esc = $fn->appendBasicBlock($tag.'_a_esc');
        $raw = $fn->appendBasicBlock($tag.'_a_raw');
        $context->builder->branchIf($quoteBoth, $esc, $raw);
        $context->builder->positionAtEnd($esc);
        $flagsAndHtml5 = $context->builder->and($flags, $i64->constInt(self::ENT_HTML5, false));
        $isHtml5 = $context->builder->icmp(Builder::INT_NE, $flagsAndHtml5, $zero);
        $h5 = $fn->appendBasicBlock($tag.'_a_h5');
        $leg = $fn->appendBasicBlock($tag.'_a_leg');
        $context->builder->branchIf($isHtml5, $h5, $leg);
        $context->builder->positionAtEnd($h5);
        self::emitWriteLit($context, $outData, $outOffPtr, '&apos;');
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($leg);
        self::emitWriteLit($context, $outData, $outOffPtr, '&#039;');
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($raw);
        self::emitWriteByte($context, $outData, $outOffPtr, $ch);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
    }

    private static function emitOther(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $flags,
        Value $ch,
        Value $chU,
        Value $pos,
        Value $posPtr,
        Value $inputData,
        Value $inputLen,
        Value $outData,
        Value $outOffPtr,
        Value $widthPtr,
        $otherBb,
        $afterBb,
        Value $one,
        Value $zero,
        Type $i64
    ): void {
        $context->builder->positionAtEnd($otherBb);
        self::emitUtf8ValidWidth($context, $fn, $tag.'_uw', $inputData, $inputLen, $pos, $chU, $widthPtr);
        $width = $context->builder->load($widthPtr);

        $ignoreBit = $context->builder->and($flags, $i64->constInt(self::ENT_IGNORE, false));
        $hasIgnore = $context->builder->icmp(Builder::INT_NE, $ignoreBit, $zero);
        $disBit = $context->builder->and($flags, $i64->constInt(self::ENT_DISALLOWED, false));
        $hasDis = $context->builder->icmp(Builder::INT_NE, $disBit, $zero);

        $invBb = $fn->appendBasicBlock($tag.'_inv');
        $validBb = $fn->appendBasicBlock($tag.'_val');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $width, $zero),
            $invBb,
            $validBb
        );

        // Invalid UTF-8 lead
        $context->builder->positionAtEnd($invBb);
        $invSkip = $fn->appendBasicBlock($tag.'_inv_sk');
        $invMore = $fn->appendBasicBlock($tag.'_inv_mo');
        $invFffd = $fn->appendBasicBlock($tag.'_inv_ff');
        $invCopy = $fn->appendBasicBlock($tag.'_inv_cp');
        $context->builder->branchIf($hasIgnore, $invSkip, $invMore);
        $context->builder->positionAtEnd($invSkip);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($invMore);
        $context->builder->branchIf($hasDis, $invFffd, $invCopy);
        $context->builder->positionAtEnd($invFffd);
        self::emitWriteLit($context, $outData, $outOffPtr, "\xEF\xBF\xBD");
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($invCopy);
        self::emitWriteByte($context, $outData, $outOffPtr, $ch);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);

        // Valid ASCII (width=1) or multi-byte
        $context->builder->positionAtEnd($validBb);
        $asciiBb = $fn->appendBasicBlock($tag.'_asc');
        $multiBb = $fn->appendBasicBlock($tag.'_mul');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $width, $one),
            $asciiBb,
            $multiBb
        );

        $context->builder->positionAtEnd($asciiBb);
        $ascDis = $fn->appendBasicBlock($tag.'_asc_di');
        $ascCopy = $fn->appendBasicBlock($tag.'_asc_cp');
        $ascFffd = $fn->appendBasicBlock($tag.'_asc_ff');
        $context->builder->branchIf($hasDis, $ascDis, $ascCopy);
        $context->builder->positionAtEnd($ascDis);
        // unicode_cp_is_allowed for single-byte: ENT_HTML5 allows 0x20-0x7E, 0x09-0x0D (!0x0B), 0xA0+
        // Simplified: disallow C0 controls except TAB/LF/CR (matches helper for ENT_HTML5|ENT_DISALLOWED).
        $cp = $chU;
        $allowed = self::emitUnicodeCpAllowed($context, $fn, $tag.'_ua', $cp, $flags, $zero, $i64);
        $context->builder->branchIf($allowed, $ascCopy, $ascFffd);
        $context->builder->positionAtEnd($ascFffd);
        self::emitWriteLit($context, $outData, $outOffPtr, "\xEF\xBF\xBD");
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($ascCopy);
        self::emitWriteByte($context, $outData, $outOffPtr, $ch);
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($multiBb);
        // Multi-byte: copy through unless ENT_DISALLOWED rejects the codepoint.
        $mulDis = $fn->appendBasicBlock($tag.'_mul_di');
        $mulCopy = $fn->appendBasicBlock($tag.'_mul_cp');
        $mulFffd = $fn->appendBasicBlock($tag.'_mul_ff');
        $context->builder->branchIf($hasDis, $mulDis, $mulCopy);
        $context->builder->positionAtEnd($mulDis);
        $cp2Ptr = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        self::emitUtf8CodePointStore($context, $fn, $tag.'_cp', $inputData, $pos, $width, $chU, $cp2Ptr);
        $cp2 = $context->builder->load($cp2Ptr);
        $allowed2 = self::emitUnicodeCpAllowed($context, $fn, $tag.'_um', $cp2, $flags, $zero, $i64);
        $context->builder->branchIf($allowed2, $mulCopy, $mulFffd);
        $context->builder->positionAtEnd($mulFffd);
        self::emitWriteLit($context, $outData, $outOffPtr, "\xEF\xBF\xBD");
        $context->builder->store($context->builder->add($pos, $width), $posPtr);
        $context->builder->branch($afterBb);
        $context->builder->positionAtEnd($mulCopy);
        self::emitCopyBytes($context, $inputData, $outData, $outOffPtr, $pos, $width);
        $context->builder->store($context->builder->add($pos, $width), $posPtr);
        $context->builder->branch($afterBb);
    }

    /** Returns i1 whether codepoint is allowed under flags (php-src unicode_cp_is_allowed). */
    private static function emitUnicodeCpAllowed(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $cp,
        Value $flags,
        Value $zero,
        Type $i64
    ): Value {
        $docType = $context->builder->and($flags, $i64->constInt(self::ENT_HTML5, false));
        $isHtml5 = $context->builder->icmp(
            Builder::INT_EQ,
            $docType,
            $i64->constInt(self::ENT_HTML5, false)
        );
        $isXml = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $docType, $i64->constInt(self::ENT_XML1, false)),
            $context->builder->icmp(Builder::INT_EQ, $docType, $i64->constInt(self::ENT_XHTML, false))
        );

        $resultPtr = BasicBlockHelper::entryAllocaForFunction(
            $context,
            $fn,
            $context->getTypeFromString('int1')
        );
        $html5Bb = $fn->appendBasicBlock($tag.'_h5');
        $xmlBb = $fn->appendBasicBlock($tag.'_xml');
        $defBb = $fn->appendBasicBlock($tag.'_def');
        $joinBb = $fn->appendBasicBlock($tag.'_j');
        $s1 = $fn->appendBasicBlock($tag.'_s1');
        $context->builder->branchIf($isHtml5, $html5Bb, $s1);
        $context->builder->positionAtEnd($s1);
        $context->builder->branchIf($isXml, $xmlBb, $defBb);

        $context->builder->positionAtEnd($html5Bb);
        // (cp >= 0x20 && cp <= 0x7E) || (cp >= 0x09 && cp <= 0x0D && cp != 0x0B)
        // || (cp >= 0xA0 && cp <= 0xD7FF) || (cp >= 0xE000 && cp <= 0x10FFFF && ...)
        $ge20 = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0x20, false));
        $le7e = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x7E, false));
        $print = $context->builder->and($ge20, $le7e);
        $ge09 = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0x09, false));
        $le0d = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x0D, false));
        $ne0b = $context->builder->icmp(Builder::INT_NE, $cp, $i64->constInt(0x0B, false));
        $ws = $context->builder->and($context->builder->and($ge09, $le0d), $ne0b);
        $geA0 = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0xA0, false));
        $leD7 = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0xD7FF, false));
        $bmp = $context->builder->and($geA0, $leD7);
        $geE0 = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0xE000, false));
        $le10 = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x10FFFF, false));
        $low = $context->builder->and($cp, $i64->constInt(0xFFFF, false));
        $notFffe = $context->builder->icmp(Builder::INT_ULT, $low, $i64->constInt(0xFFFE, false));
        $ltFdd0 = $context->builder->icmp(Builder::INT_ULT, $cp, $i64->constInt(0xFDD0, false));
        $gtFdef = $context->builder->icmp(Builder::INT_UGT, $cp, $i64->constInt(0xFDEF, false));
        $notFdd = $context->builder->or($ltFdd0, $gtFdef);
        $hi = $context->builder->and(
            $context->builder->and($geE0, $le10),
            $context->builder->and($notFffe, $notFdd)
        );
        $ok5 = $context->builder->or($context->builder->or($print, $ws), $context->builder->or($bmp, $hi));
        $context->builder->store($ok5, $resultPtr);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($xmlBb);
        $ge20x = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0x20, false));
        $leD7x = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0xD7FF, false));
        $range1 = $context->builder->and($ge20x, $leD7x);
        $is09 = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x09, false));
        $is0a = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x0A, false));
        $is0d = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x0D, false));
        $wsx = $context->builder->or($context->builder->or($is09, $is0a), $is0d);
        $geE0x = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0xE000, false));
        $le10x = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x10FFFF, false));
        $neFffe = $context->builder->icmp(Builder::INT_NE, $cp, $i64->constInt(0xFFFE, false));
        $neFfff = $context->builder->icmp(Builder::INT_NE, $cp, $i64->constInt(0xFFFF, false));
        $hix = $context->builder->and(
            $context->builder->and($geE0x, $le10x),
            $context->builder->and($neFffe, $neFfff)
        );
        $okx = $context->builder->or($context->builder->or($range1, $wsx), $hix);
        $context->builder->store($okx, $resultPtr);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($defBb);
        $ge20d = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0x20, false));
        $le7ed = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x7E, false));
        $printd = $context->builder->and($ge20d, $le7ed);
        $is09d = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x09, false));
        $is0ad = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x0A, false));
        $is0dd = $context->builder->icmp(Builder::INT_EQ, $cp, $i64->constInt(0x0D, false));
        $wsd = $context->builder->or($context->builder->or($is09d, $is0ad), $is0dd);
        $geA0d = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0xA0, false));
        $leD7d = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0xD7FF, false));
        $bmpd = $context->builder->and($geA0d, $leD7d);
        $geE0d = $context->builder->icmp(Builder::INT_UGE, $cp, $i64->constInt(0xE000, false));
        $le10d = $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0x10FFFF, false));
        $hid = $context->builder->and($geE0d, $le10d);
        $okd = $context->builder->or($context->builder->or($printd, $wsd), $context->builder->or($bmpd, $hid));
        $context->builder->store($okd, $resultPtr);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

        return $context->builder->load($resultPtr);
    }

    private static function emitUtf8CodePointStore(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $data,
        Value $pos,
        Value $width,
        Value $b0,
        Value $cpPtr
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);

        $w2 = $fn->appendBasicBlock($tag.'_w2');
        $w3 = $fn->appendBasicBlock($tag.'_w3');
        $w4 = $fn->appendBasicBlock($tag.'_w4');
        $wd = $fn->appendBasicBlock($tag.'_wd');
        $join = $fn->appendBasicBlock($tag.'_j');
        $s1 = $fn->appendBasicBlock($tag.'_s1');
        $s2 = $fn->appendBasicBlock($tag.'_s2');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $width, $two),
            $w2,
            $s1
        );
        $context->builder->positionAtEnd($s1);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $width, $three),
            $w3,
            $s2
        );
        $context->builder->positionAtEnd($s2);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $width, $four),
            $w4,
            $wd
        );

        $context->builder->positionAtEnd($w2);
        $b1 = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $one))),
            $i64
        );
        $cp2 = $context->builder->or(
            $context->builder->shl($context->builder->and($b0, $i64->constInt(0x1F, false)), $i64->constInt(6, false)),
            $context->builder->and($b1, $i64->constInt(0x3F, false))
        );
        $context->builder->store($cp2, $cpPtr);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($w3);
        $b1b = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $one))),
            $i64
        );
        $b2 = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $two))),
            $i64
        );
        $cp3 = $context->builder->or(
            $context->builder->or(
                $context->builder->shl($context->builder->and($b0, $i64->constInt(0x0F, false)), $i64->constInt(12, false)),
                $context->builder->shl($context->builder->and($b1b, $i64->constInt(0x3F, false)), $i64->constInt(6, false))
            ),
            $context->builder->and($b2, $i64->constInt(0x3F, false))
        );
        $context->builder->store($cp3, $cpPtr);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($w4);
        $b1c = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $one))),
            $i64
        );
        $b2c = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $two))),
            $i64
        );
        $b3 = $context->builder->zExt(
            $context->builder->load($context->builder->gep($data, $context->builder->add($pos, $three))),
            $i64
        );
        $cp4 = $context->builder->or(
            $context->builder->or(
                $context->builder->or(
                    $context->builder->shl($context->builder->and($b0, $i64->constInt(0x07, false)), $i64->constInt(18, false)),
                    $context->builder->shl($context->builder->and($b1c, $i64->constInt(0x3F, false)), $i64->constInt(12, false))
                ),
                $context->builder->shl($context->builder->and($b2c, $i64->constInt(0x3F, false)), $i64->constInt(6, false))
            ),
            $context->builder->and($b3, $i64->constInt(0x3F, false))
        );
        $context->builder->store($cp4, $cpPtr);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($wd);
        $context->builder->store($b0, $cpPtr);
        $context->builder->branch($join);
        $context->builder->positionAtEnd($join);
    }

    private static function ensureDecls(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        LibcExtern::ensureMemcpyImplemented($context);
        foreach (
            [
                '__string__alloc' => [$strPtr, false, [$i64]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            $decl = $context->module->addFunction(
                $name,
                $context->context->functionType($ret, $vararg, ...$params)
            );
            $context->registerFunction($name, $decl);
        }
    }

    private static function emitWriteLit(Context $context, Value $outData, Value $outOffPtr, string $lit): void
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $litLen = $i64->constInt(\strlen($lit), false);
        $litStr = $context->builder->load($context->constantStringFromString($lit));
        $litData = $context->builder->structGep($litStr, $map['value']);
        $outOff = $context->builder->load($outOffPtr);
        $dest = $context->builder->gep($outData, $outOff);
        $context->intrinsic->builder = $context->builder;
        $context->intrinsic->memcpy($dest, $litData, $litLen, false);
        $context->builder->store($context->builder->add($outOff, $litLen), $outOffPtr);
    }

    private static function emitWriteByte(Context $context, Value $outData, Value $outOffPtr, Value $ch): void
    {
        $i64 = $context->getTypeFromString('int64');
        $outOff = $context->builder->load($outOffPtr);
        $dest = $context->builder->gep($outData, $outOff);
        $context->builder->store($ch, $dest);
        $context->builder->store($context->builder->add($outOff, $i64->constInt(1, false)), $outOffPtr);
    }

    private static function emitCopyBytes(
        Context $context,
        Value $srcData,
        Value $outData,
        Value $outOffPtr,
        Value $srcOff,
        Value $len
    ): void {
        $outOff = $context->builder->load($outOffPtr);
        $dest = $context->builder->gep($outData, $outOff);
        $src = $context->builder->gep($srcData, $srcOff);
        $context->intrinsic->builder = $context->builder;
        $context->intrinsic->memcpy($dest, $src, $len, false);
        $context->builder->store($context->builder->add($outOff, $len), $outOffPtr);
    }

    /**
     * Store existing-entity length at $pos into $entLenPtr (0 if none).
     */
    private static function emitExistingEntityLen(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $data,
        Value $len,
        Value $pos,
        Value $entLenPtr
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $context->builder->store($zero, $entLenPtr);
        $join = $fn->appendBasicBlock($tag.'_join');

        $entities = [
            ['&amp;', 5],
            ['&lt;', 4],
            ['&gt;', 4],
            ['&quot;', 6],
            ['&#039;', 6],
            ['&#39;', 5],
        ];
        $cur = null;
        foreach ($entities as $idx => [$ent, $n]) {
            $try = $fn->appendBasicBlock($tag.'_t'.$idx);
            $hit = $fn->appendBasicBlock($tag.'_h'.$idx);
            if (0 === $idx) {
                $context->builder->branch($try);
            } else {
                $context->builder->positionAtEnd($cur);
                $context->builder->branch($try);
            }
            $context->builder->positionAtEnd($try);
            $ok = self::emitPrefixMatch($context, $data, $len, $pos, $ent);
            $cur = $fn->appendBasicBlock($tag.'_n'.$idx);
            $context->builder->branchIf($ok, $hit, $cur);
            $context->builder->positionAtEnd($hit);
            $context->builder->store($i64->constInt($n, false), $entLenPtr);
            $context->builder->branch($join);
        }
        $context->builder->positionAtEnd($cur);
        $context->builder->branch($join);
        $context->builder->positionAtEnd($join);
        // Caller continues; miss path uses entLen==0.
    }

    private static function emitPrefixMatch(
        Context $context,
        Value $data,
        Value $len,
        Value $pos,
        string $ent
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $n = \strlen($ent);
        $end = $context->builder->add($pos, $i64->constInt($n, false));
        $ok = $context->builder->icmp(Builder::INT_ULE, $end, $len);
        for ($j = 0; $j < $n; ++$j) {
            $byte = $context->builder->load(
                $context->builder->gep($data, $context->builder->add($pos, $i64->constInt($j, false)))
            );
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $byte,
                $i8->constInt(\ord($ent[$j]), false)
            );
            $ok = $context->builder->and($ok, $eq);
        }

        return $ok;
    }

    private static function emitUtf8ValidWidth(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $data,
        Value $len,
        Value $pos,
        Value $chU,
        Value $widthPtr
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $context->builder->store($zero, $widthPtr);

        $ascii = $fn->appendBasicBlock($tag.'_asc');
        $mb = $fn->appendBasicBlock($tag.'_mb');
        $end = $fn->appendBasicBlock($tag.'_end');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $chU, $i64->constInt(0x80, false)),
            $ascii,
            $mb
        );
        $context->builder->positionAtEnd($ascii);
        $context->builder->store($one, $widthPtr);
        $context->builder->branch($end);

        $context->builder->positionAtEnd($mb);
        $is2 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($chU, $i64->constInt(0xE0, false)),
            $i64->constInt(0xC0, false)
        );
        $is3 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($chU, $i64->constInt(0xF0, false)),
            $i64->constInt(0xE0, false)
        );
        $is4 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($chU, $i64->constInt(0xF8, false)),
            $i64->constInt(0xF0, false)
        );
        $b2 = $fn->appendBasicBlock($tag.'_b2');
        $b3 = $fn->appendBasicBlock($tag.'_b3');
        $b4 = $fn->appendBasicBlock($tag.'_b4');
        $bad = $fn->appendBasicBlock($tag.'_bad');
        $s1 = $fn->appendBasicBlock($tag.'_s1');
        $s2 = $fn->appendBasicBlock($tag.'_s2');
        $context->builder->branchIf($is2, $b2, $s1);
        $context->builder->positionAtEnd($s1);
        $context->builder->branchIf($is3, $b3, $s2);
        $context->builder->positionAtEnd($s2);
        $context->builder->branchIf($is4, $b4, $bad);

        $context->builder->positionAtEnd($b2);
        self::emitCheckCont($context, $fn, $tag.'_c2', $data, $len, $pos, 1, 2, $widthPtr, $end, $bad);
        $context->builder->positionAtEnd($b3);
        self::emitCheckCont($context, $fn, $tag.'_c3', $data, $len, $pos, 2, 3, $widthPtr, $end, $bad);
        $context->builder->positionAtEnd($b4);
        self::emitCheckCont($context, $fn, $tag.'_c4', $data, $len, $pos, 3, 4, $widthPtr, $end, $bad);

        $context->builder->positionAtEnd($bad);
        $context->builder->store($zero, $widthPtr);
        $context->builder->branch($end);
        $context->builder->positionAtEnd($end);
    }

    private static function emitCheckCont(
        Context $context,
        LlvmFunction $fn,
        string $tag,
        Value $data,
        Value $len,
        Value $pos,
        int $contCount,
        int $width,
        Value $widthPtr,
        $endBb,
        $badBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $last = $context->builder->add($pos, $i64->constInt($contCount, false));
        $fits = $context->builder->icmp(Builder::INT_ULT, $last, $len);
        $okBb = $fn->appendBasicBlock($tag.'_ok');
        $context->builder->branchIf($fits, $okBb, $badBb);
        $context->builder->positionAtEnd($okBb);
        for ($j = 1; $j <= $contCount; ++$j) {
            $byte = $context->builder->load(
                $context->builder->gep($data, $context->builder->add($pos, $i64->constInt($j, false)))
            );
            $byteU = $context->builder->zExt($byte, $i64);
            $isCont = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->and($byteU, $i64->constInt(0xC0, false)),
                $i64->constInt(0x80, false)
            );
            $next = $fn->appendBasicBlock($tag.'_j'.$j);
            $context->builder->branchIf($isCont, $next, $badBb);
            $context->builder->positionAtEnd($next);
        }
        $context->builder->store($i64->constInt($width, false), $widthPtr);
        $context->builder->branch($endBb);
    }
}
