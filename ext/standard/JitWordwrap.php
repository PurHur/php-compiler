<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT lowering for wordwrap() (issues #975, #3774, #5209).
 *
 * Mirrors ext/standard/VmString.php — no compiler_wordwrap.c.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitWordwrap
{
    private static int $blockSerial = 0;

    public static function wrap(
        Context $context,
        Value $strPtr,
        Value $width,
        Value $breakPtr,
        Value $cutI8,
        ?JITVariable $strArg = null,
        ?JITVariable $widthArg = null,
        ?JITVariable $breakArg = null,
        ?JITVariable $cutArg = null
    ): Value {
        if (null !== $strArg) {
            $literal = JitStringArg::compileTimeLiteral($strArg);
            if (null !== $literal) {
                $w = self::compileTimeInt($widthArg, 75);
                $brk = null !== $breakArg ? JitStringArg::compileTimeLiteral($breakArg) : "\n";
                $cut = self::compileTimeBool($cutArg, false);
                if (null !== $w && null !== $brk) {
                    return $context->builder->load(
                        $context->constantStringFromString(VmString::wordwrap($literal, $w, $brk, $cut))
                    );
                }
            }
        }

        return self::emitWordwrap($context, $strPtr, $width, $breakPtr, $cutI8);
    }

    private static function compileTimeInt(?JITVariable $arg, int $default): ?int
    {
        if (null === $arg) {
            return $default;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return (int) $const->constInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return VmMath::floatToZendLong((float) $const->constDouble());
            }
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal && is_numeric($literal)) {
            return (int) $literal;
        }

        return null;
    }

    private static function compileTimeBool(?JITVariable $arg, bool $default): bool
    {
        if (null === $arg) {
            return $default;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        return $default;
    }

    private static function emitWordwrap(
        Context $context,
        Value $strPtr,
        Value $width,
        Value $breakPtr,
        Value $cutI8
    ): Value {
        $id = (string) (++self::$blockSerial);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $len = self::stringLen($context, $strPtr);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));

        $emptyRet = BasicBlockHelper::append($context, 'ww_empty_'.$id);
        $afterEmpty = BasicBlockHelper::append($context, 'ww_after_empty_'.$id);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($isEmpty, $emptyRet, $afterEmpty);

        $context->builder->positionAtEnd($emptyRet);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__alloc'), $zero),
            $resultSlot
        );
        $done = BasicBlockHelper::append($context, 'ww_done_'.$id);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterEmpty);
        $brkLen = self::stringLen($context, $breakPtr);
        $unchanged = BasicBlockHelper::append($context, 'ww_unchanged_'.$id);
        $afterBrk = BasicBlockHelper::append($context, 'ww_after_brk_'.$id);
        $isBrkEmpty = $context->builder->icmp(Builder::INT_EQ, $brkLen, $zero);
        $context->builder->branchIf($isBrkEmpty, $unchanged, $afterBrk);

        $context->builder->positionAtEnd($unchanged);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__separate'), $strPtr),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBrk);
        $cutOn = $context->builder->icmp(
            Builder::INT_NE,
            $cutI8,
            $i8->constInt(0, false)
        );
        $widthZero = $context->builder->icmp(Builder::INT_EQ, $width, $zero);
        $badCut = $context->builder->and($widthZero, $cutOn);
        $afterCut = BasicBlockHelper::append($context, 'ww_after_cut_'.$id);
        $context->builder->branchIf($badCut, $unchanged, $afterCut);
        $cutBlock = BasicBlockHelper::append($context, 'ww_cut_'.$id);
        $nonCutBlock = BasicBlockHelper::append($context, 'ww_noncut_'.$id);

        $context->builder->positionAtEnd($afterCut);
        $context->builder->branchIf($cutOn, $cutBlock, $nonCutBlock);

        $context->builder->positionAtEnd($cutBlock);
        $context->builder->store(
            self::emitGeneral($context, $strPtr, $width, $breakPtr, $len, $brkLen, $id, true),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nonCutBlock);
        $fastBlock = BasicBlockHelper::append($context, 'ww_fast_'.$id);
        $generalBlock = BasicBlockHelper::append($context, 'ww_general_'.$id);
        $isSingleBreak = $context->builder->icmp(Builder::INT_EQ, $brkLen, $one);
        $context->builder->branchIf($isSingleBreak, $fastBlock, $generalBlock);

        $context->builder->positionAtEnd($fastBlock);
        $brkByte = $context->builder->load(
            $context->builder->inBoundsGEP(self::stringCharsPtr($context, $breakPtr), $zero)
        );
        $context->builder->store(
            self::emitFastSingleByteBreak($context, $strPtr, $len, $width, $brkByte, $id),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($generalBlock);
        $context->builder->store(
            self::emitGeneral($context, $strPtr, $width, $breakPtr, $len, $brkLen, $id),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function emitFastSingleByteBreak(
        Context $context,
        Value $strPtr,
        Value $len,
        Value $width,
        Value $brkByte,
        string $id
    ): Value {
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $data = self::stringCharsPtr($context, $copy);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $space = $i8->constInt(ord(' '), false);

        $currentSlot = $context->builder->alloca($i64, 1, 'ww_fast_cur_'.$id);
        $laststartSlot = $context->builder->alloca($i64, 1, 'ww_fast_ls_'.$id);
        $lastspaceSlot = $context->builder->alloca($i64, 1, 'ww_fast_lsp_'.$id);
        $context->builder->store($zero, $currentSlot);
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);

        $head = BasicBlockHelper::append($context, 'ww_fast_head_'.$id);
        $body = BasicBlockHelper::append($context, 'ww_fast_body_'.$id);
        $isBrk = BasicBlockHelper::append($context, 'ww_fast_brk_'.$id);
        $isSpace = BasicBlockHelper::append($context, 'ww_fast_space_'.$id);
        $isWord = BasicBlockHelper::append($context, 'ww_fast_word_'.$id);
        $wrapAtSpace = BasicBlockHelper::append($context, 'ww_fast_swrap_'.$id);
        $wrapAtWord = BasicBlockHelper::append($context, 'ww_fast_wwrap_'.$id);
        $inc = BasicBlockHelper::append($context, 'ww_fast_inc_'.$id);
        $done = BasicBlockHelper::append($context, 'ww_fast_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $current = $context->builder->load($currentSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $current, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($data, $current));
        $matchesBrk = $context->builder->icmp(Builder::INT_EQ, $ch, $brkByte);
        $context->builder->branchIf($matchesBrk, $isBrk, $isSpace);

        $context->builder->positionAtEnd($isBrk);
        $nextStart = $context->builder->addNoSignedWrap($current, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($nextStart, $lastspaceSlot);
        $context->builder->branch($inc);

        $context->builder->positionAtEnd($isSpace);
        $isSpaceCh = $context->builder->icmp(Builder::INT_EQ, $ch, $space);
        $context->builder->branchIf($isSpaceCh, $wrapAtSpace, $isWord);

        $context->builder->positionAtEnd($wrapAtSpace);
        $laststart = $context->builder->load($laststartSlot);
        $span = $context->builder->subNoSignedWrap($current, $laststart);
        $needsWrap = $context->builder->icmp(Builder::INT_SGE, $span, $width);
        $doSpaceWrap = BasicBlockHelper::append($context, 'ww_fast_do_swrap_'.$id);
        $afterSpaceWrap = BasicBlockHelper::append($context, 'ww_fast_after_swrap_'.$id);
        $context->builder->branchIf($needsWrap, $doSpaceWrap, $afterSpaceWrap);

        $context->builder->positionAtEnd($doSpaceWrap);
        $context->builder->store($brkByte, $context->builder->inBoundsGEP($data, $current));
        $context->builder->store($context->builder->addNoSignedWrap($current, $one), $laststartSlot);
        $context->builder->branch($afterSpaceWrap);

        $context->builder->positionAtEnd($afterSpaceWrap);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($inc);

        $context->builder->positionAtEnd($isWord);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $span = $context->builder->subNoSignedWrap($current, $laststart);
        $tooWide = $context->builder->icmp(Builder::INT_SGE, $span, $width);
        $lsNe = $context->builder->icmp(Builder::INT_NE, $laststart, $lastspace);
        $context->builder->branchIf(
            $context->builder->and($tooWide, $lsNe),
            $wrapAtWord,
            $inc
        );

        $context->builder->positionAtEnd($wrapAtWord);
        $ls = $context->builder->load($lastspaceSlot);
        $context->builder->store($brkByte, $context->builder->inBoundsGEP($data, $ls));
        $context->builder->store($context->builder->addNoSignedWrap($ls, $one), $laststartSlot);
        $context->builder->branch($inc);

        $context->builder->positionAtEnd($inc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($currentSlot), $one),
            $currentSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $copy;
    }

    private static function emitGeneral(
        Context $context,
        Value $strPtr,
        Value $width,
        Value $breakPtr,
        Value $len,
        Value $brkLen,
        string $id,
        bool $cut = false
    ): Value {
        $textPtr = self::stringCharsPtr($context, $strPtr);
        $brkPtr = self::stringCharsPtr($context, $breakPtr);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $space = $i8->constInt(ord(' '), false);
        $widthSafe = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $width, $zero),
            $width,
            $one
        );
        $extra = $context->builder->addNoSignedWrap(
            $context->builder->signedDiv($len, $widthSafe),
            $context->builder->mulNoSignedWrap($i64->constInt(2, false), $brkLen)
        );
        $outCap = $context->builder->addNoSignedWrap(
            $len,
            $context->builder->mulNoSignedWrap($extra, $brkLen)
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outCap);
        $destPtr = self::stringCharsPtr($context, $dest);

        $currentSlot = $context->builder->alloca($i64, 1, 'ww_gen_cur_'.$id);
        $laststartSlot = $context->builder->alloca($i64, 1, 'ww_gen_ls_'.$id);
        $lastspaceSlot = $context->builder->alloca($i64, 1, 'ww_gen_lsp_'.$id);
        $posSlot = $context->builder->alloca($i64, 1, 'ww_gen_pos_'.$id);
        $context->builder->store($zero, $currentSlot);
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $posSlot);

        $head = BasicBlockHelper::append($context, 'ww_gen_head_'.$id);
        $body = BasicBlockHelper::append($context, 'ww_gen_body_'.$id);
        $matchBrk = BasicBlockHelper::append($context, 'ww_gen_mbrk_'.$id);
        $isSpace = BasicBlockHelper::append($context, 'ww_gen_space_'.$id);
        $forceWrap = BasicBlockHelper::append($context, 'ww_gen_fwrap_'.$id);
        $plainInc = BasicBlockHelper::append($context, 'ww_gen_inc_'.$id);
        $loopDone = BasicBlockHelper::append($context, 'ww_gen_loop_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $current = $context->builder->load($currentSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $current, $len);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $canMatch = $context->builder->icmp(
            Builder::INT_SLE,
            $context->builder->addNoSignedWrap($current, $brkLen),
            $len
        );
        $firstBrk = $context->builder->load($context->builder->inBoundsGEP($brkPtr, $zero));
        $firstText = $context->builder->load($context->builder->inBoundsGEP($textPtr, $current));
        $firstEq = $context->builder->icmp(Builder::INT_EQ, $firstText, $firstBrk);
        $mightMatch = $context->builder->and($canMatch, $firstEq);
        $context->builder->branchIf($mightMatch, $matchBrk, $isSpace);

        $context->builder->positionAtEnd($matchBrk);
        $matches = self::emitBytesEqual($context, $textPtr, $current, $brkPtr, $zero, $brkLen, 'ww_gen_eq_'.$id);
        $afterMatch = BasicBlockHelper::append($context, 'ww_gen_after_mbrk_'.$id);
        $context->builder->branchIf($matches, $afterMatch, $isSpace);

        $context->builder->positionAtEnd($afterMatch);
        $laststart = $context->builder->load($laststartSlot);
        $segLen = $context->builder->addNoSignedWrap(
            $context->builder->subNoSignedWrap($current, $laststart),
            $brkLen
        );
        $pos = $context->builder->load($posSlot);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $context->builder->inBoundsGEP($textPtr, $laststart),
            $segLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $segLen);
        $context->builder->store($pos, $posSlot);
        $nextCur = $context->builder->addNoSignedWrap($current, $brkLen);
        $context->builder->store($nextCur, $currentSlot);
        $context->builder->store($nextCur, $laststartSlot);
        $context->builder->store($nextCur, $lastspaceSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($isSpace);
        $ch = $context->builder->load($context->builder->inBoundsGEP($textPtr, $current));
        $isSpaceCh = $context->builder->icmp(Builder::INT_EQ, $ch, $space);
        $context->builder->branchIf($isSpaceCh, $forceWrap, $plainInc);

        $context->builder->positionAtEnd($forceWrap);
        $laststart = $context->builder->load($laststartSlot);
        $span = $context->builder->subNoSignedWrap($current, $laststart);
        $needsBreak = $context->builder->icmp(Builder::INT_SGE, $span, $width);
        $withBreak = BasicBlockHelper::append($context, 'ww_gen_wbrk_'.$id);
        $noBreak = BasicBlockHelper::append($context, 'ww_gen_nobrk_'.$id);
        $afterSpace = BasicBlockHelper::append($context, 'ww_gen_after_space_'.$id);
        $context->builder->branchIf($needsBreak, $withBreak, $noBreak);

        $context->builder->positionAtEnd($withBreak);
        $pos = $context->builder->load($posSlot);
        $segLen = $context->builder->subNoSignedWrap($current, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $context->builder->inBoundsGEP($textPtr, $laststart),
            $segLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $brkPtr,
            $brkLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $brkLen);
        $context->builder->store($pos, $posSlot);
        $nextStart = $context->builder->addNoSignedWrap($current, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->branch($afterSpace);

        $context->builder->positionAtEnd($noBreak);
        $context->builder->branch($afterSpace);

        $context->builder->positionAtEnd($afterSpace);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->addNoSignedWrap($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($plainInc);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $span = $context->builder->subNoSignedWrap($current, $laststart);
        $tooWide = $context->builder->icmp(Builder::INT_SGE, $span, $width);
        $canWrap = $context->builder->icmp(Builder::INT_SLT, $laststart, $lastspace);
        $doWrap = BasicBlockHelper::append($context, 'ww_gen_dowrap_'.$id);
        $forceCut = BasicBlockHelper::append($context, 'ww_gen_fcut_'.$id);
        $skipWrap = BasicBlockHelper::append($context, 'ww_gen_skipwrap_'.$id);
        if ($cut) {
            $noSpaceWrap = $context->builder->icmp(Builder::INT_SGE, $laststart, $lastspace);
            $forcedCut = $context->builder->and($tooWide, $noSpaceWrap);
            $maybeWrap = $context->builder->and($tooWide, $canWrap);
            $afterBranch = BasicBlockHelper::append($context, 'ww_gen_after_plain_'.$id);
            $context->builder->branchIf($forcedCut, $forceCut, $afterBranch);
            $context->builder->positionAtEnd($afterBranch);
            $context->builder->branchIf($maybeWrap, $doWrap, $skipWrap);
        } else {
            $context->builder->branchIf(
                $context->builder->and($tooWide, $canWrap),
                $doWrap,
                $skipWrap
            );
        }

        $context->builder->positionAtEnd($forceCut);
        $pos = $context->builder->load($posSlot);
        $segLen = $context->builder->subNoSignedWrap($current, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $context->builder->inBoundsGEP($textPtr, $laststart),
            $segLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $brkPtr,
            $brkLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $brkLen);
        $context->builder->store($pos, $posSlot);
        $context->builder->store($current, $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->addNoSignedWrap($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doWrap);
        $pos = $context->builder->load($posSlot);
        $segLen = $context->builder->subNoSignedWrap($lastspace, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $context->builder->inBoundsGEP($textPtr, $laststart),
            $segLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $brkPtr,
            $brkLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $brkLen);
        $context->builder->store($pos, $posSlot);
        $nextStart = $context->builder->addNoSignedWrap($lastspace, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($nextStart, $lastspaceSlot);
        $context->builder->store($context->builder->addNoSignedWrap($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($skipWrap);
        $context->builder->store($context->builder->addNoSignedWrap($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $laststart = $context->builder->load($laststartSlot);
        $hasTail = $context->builder->icmp(Builder::INT_SLT, $laststart, $len);
        $tailBlock = BasicBlockHelper::append($context, 'ww_gen_tail_'.$id);
        $finish = BasicBlockHelper::append($context, 'ww_gen_finish_'.$id);
        $context->builder->branchIf($hasTail, $tailBlock, $finish);

        $context->builder->positionAtEnd($tailBlock);
        $pos = $context->builder->load($posSlot);
        $tailLen = $context->builder->subNoSignedWrap($len, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->inBoundsGEP($destPtr, $pos),
            $context->builder->inBoundsGEP($textPtr, $laststart),
            $tailLen,
            false
        );
        $pos = $context->builder->addNoSignedWrap($pos, $tailLen);
        $context->builder->store($pos, $posSlot);
        $context->builder->branch($finish);

        $context->builder->positionAtEnd($finish);
        $outLen = $context->builder->load($posSlot);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));

        return $dest;
    }

    private static function emitBytesEqual(
        Context $context,
        Value $aPtr,
        Value $aOff,
        Value $bPtr,
        Value $bOff,
        Value $n,
        string $tag
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $iSlot = $context->builder->alloca($i64, 1, $tag.'_i');
        $context->builder->store($zero, $iSlot);

        $head = BasicBlockHelper::append($context, $tag.'_head');
        $body = BasicBlockHelper::append($context, $tag.'_body');
        $next = BasicBlockHelper::append($context, $tag.'_next');
        $fail = BasicBlockHelper::append($context, $tag.'_fail');
        $ok = BasicBlockHelper::append($context, $tag.'_ok');
        $merge = BasicBlockHelper::append($context, $tag.'_merge');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $n);
        $context->builder->branchIf($atEnd, $ok, $body);

        $context->builder->positionAtEnd($body);
        $aCh = $context->builder->load($context->builder->inBoundsGEP($aPtr, $context->builder->addNoSignedWrap($aOff, $i)));
        $bCh = $context->builder->load($context->builder->inBoundsGEP($bPtr, $context->builder->addNoSignedWrap($bOff, $i)));
        $eq = $context->builder->icmp(Builder::INT_EQ, $aCh, $bCh);
        $context->builder->branchIf($eq, $next, $fail);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($fail);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $ok);
        $phi->addIncoming($i1->constInt(0, false), $fail);

        return $phi;
    }

    private static function stringLen(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
    }

    private static function stringCharsPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
