<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for wordwrap() (mirrors VmString::wordwrap). */
final class JitWordwrap
{
    private static int $blockSerial = 0;

    public static function resetBlockSerial(): void
    {
        self::$blockSerial = 0;
    }

    private static function freshBlockName(string $base): string
    {
        return $base.'_'.(string) (self::$blockSerial++);
    }
    public static function wrap(
        Context $context,
        Value $input,
        Value $width,
        Value $break,
        Value $cutI8
    ): Value {
        return $context->builder->call(
            $context->lookupFunction('__string__wordwrap'),
            $input,
            $width,
            $break,
            $cutI8
        );
    }

  /** Build wordwrap IR body (used by {@see \PHPCompiler\JIT\Builtin\StringWordwrap}). */
    public static function buildWordwrapBody(
        Context $context,
        Value $input,
        Value $width,
        Value $break,
        Value $cutI8
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $inLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inPtr = $context->builder->structGep($input, $map['value']);
        $breakLen = $context->builder->load(
            $context->builder->structGep($break, $map['length'])
        );
        $breakPtr = $context->builder->structGep($break, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $doneBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_done'));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $inLen, $zero);
        $emptyBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_empty'));
        $workBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_work'));
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $cutZero = $context->builder->icmp(Builder::INT_EQ, $cutI8, $context->getTypeFromString('int8')->constInt(0, false));
        $multiBreak = $context->builder->icmp(Builder::INT_UGT, $breakLen, $one);
        $singleBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single'));
        $generalBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_general'));
        $pickPath = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_pick'));
        $context->builder->branchIf($cutZero, $pickPath, $generalBlock);
        $context->builder->positionAtEnd($pickPath);
        $context->builder->branchIf($multiBreak, $generalBlock, $singleBlock);

        $context->builder->positionAtEnd($singleBlock);
        $singleResult = self::wrapSingleBreak($context, $input, $inLen, $inPtr, $width, $breakPtr);
        $singleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($generalBlock);
        $generalResult = self::wrapGeneral($context, $input, $inLen, $inPtr, $width, $break, $breakLen, $breakPtr, $cutI8);
        $generalEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($emptyStr->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($singleResult, $singleEnd);
        $result->addIncoming($generalResult, $generalEnd);

        return $result;
    }

    private static function wrapSingleBreak(
        Context $context,
        Value $input,
        Value $inLen,
        Value $inPtr,
        Value $width,
        Value $breakPtr
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $dest = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $breakChar = $context->builder->load($breakPtr);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $space = $context->getTypeFromString('int8')->constInt(ord(' '), false);

        $laststartSlot = $context->builder->alloca($i64, 1, 'wordwrap_laststart');
        $lastspaceSlot = $context->builder->alloca($i64, 1, 'wordwrap_lastspace');
        $currentSlot = $context->builder->alloca($i64, 1, 'wordwrap_current');
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $currentSlot);

        $loopHead = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_head'));
        $loopBody = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_body'));
        $loopDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_done'));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $current = $context->builder->load($currentSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $current, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $current = $context->builder->load($currentSlot);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $ch = $context->builder->load($context->builder->gep($inPtr, $current));
        $isBreak = $context->builder->icmp(Builder::INT_EQ, $ch, $breakChar);
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $ch, $space);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $hasSpace = $context->builder->icmp(Builder::INT_NE, $laststart, $lastspace);

        $afterBreak = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_after_break'));
        $checkSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_check_space'));
        $context->builder->branchIf($isBreak, $afterBreak, $checkSpace);

        $context->builder->positionAtEnd($checkSpace);
        $spaceBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_space'));
        $wordBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_word'));
        $context->builder->branchIf($isSpace, $spaceBlock, $wordBlock);

        $context->builder->positionAtEnd($spaceBlock);
        $storeBreakAtSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_store_space'));
        $spaceAfter = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_space_after'));
        $context->builder->branchIf($atWidth, $storeBreakAtSpace, $spaceAfter);
        $context->builder->positionAtEnd($storeBreakAtSpace);
        $context->builder->store($breakChar, $context->builder->gep($destPtr, $current));
        $context->builder->store($context->builder->add($current, $one), $laststartSlot);
        $context->builder->branch($spaceAfter);
        $context->builder->positionAtEnd($spaceAfter);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($wordBlock);
        $storeBreakAtWord = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_store_word'));
        $wordAfter = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_single_word_after'));
        $needWordBreak = $context->builder->and($atWidth, $hasSpace);
        $context->builder->branchIf($needWordBreak, $storeBreakAtWord, $wordAfter);
        $context->builder->positionAtEnd($storeBreakAtWord);
        $context->builder->store($breakChar, $context->builder->gep($destPtr, $lastspace));
        $context->builder->store($context->builder->add($lastspace, $one), $laststartSlot);
        $context->builder->branch($wordAfter);
        $context->builder->positionAtEnd($wordAfter);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterBreak);
        $next = $context->builder->add($current, $one);
        $context->builder->store($next, $laststartSlot);
        $context->builder->store($next, $lastspaceSlot);
        $context->builder->store($next, $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

        return $dest;
    }

    private static function wrapGeneral(
        Context $context,
        Value $input,
        Value $inLen,
        Value $inPtr,
        Value $width,
        Value $break,
        Value $breakLen,
        Value $breakPtr,
        Value $cutI8
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $cutOn = $context->builder->icmp(
            Builder::INT_NE,
            $cutI8,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $cutBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_chunk'));
        $fullBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_general_full'));
        $cutDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_general_join'));
        $context->builder->branchIf($cutOn, $cutBlock, $fullBlock);

        $context->builder->positionAtEnd($cutBlock);
        $cutResult = self::wrapHardCut($context, $inLen, $inPtr, $width, $breakLen, $breakPtr);
        $cutEnd = $context->builder->getInsertBlock();
        $context->builder->branch($cutDone);

        $context->builder->positionAtEnd($fullBlock);
        $fullResult = self::wrapGeneralFull(
            $context,
            $inLen,
            $inPtr,
            $width,
            $break,
            $breakLen,
            $breakPtr
        );
        $fullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($cutDone);

        $context->builder->positionAtEnd($cutDone);
        $joined = $context->builder->phi($cutResult->typeOf());
        $joined->addIncoming($cutResult, $cutEnd);
        $joined->addIncoming($fullResult, $fullEnd);

        return $joined;
    }

    private static function wrapHardCut(
        Context $context,
        Value $inLen,
        Value $inPtr,
        Value $width,
        Value $breakLen,
        Value $breakPtr
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $numBreaks = $context->builder->unsignedDiv(
            $context->builder->sub($inLen, $one),
            $width
        );
        $outLen = $context->builder->add(
            $inLen,
            $context->builder->mul($numBreaks, $breakLen)
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));

        $inPosSlot = $context->builder->alloca($i64, 1);
        $outPosSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $inPosSlot);
        $context->builder->store($zero, $outPosSlot);

        $loopHead = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_head'));
        $loopBody = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_body'));
        $loopDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_done'));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $inPos = $context->builder->load($inPosSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $inPos, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $inPos = $context->builder->load($inPosSlot);
        $outPos = $context->builder->load($outPosSlot);
        $remain = $context->builder->sub($inLen, $inPos);
        $chunkLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $width, $remain),
            $remain,
            $width
        );
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $inPos),
            $chunkLen,
            false
        );
        $outAfterChunk = $context->builder->add($outPos, $chunkLen);
        $inAfterChunk = $context->builder->add($inPos, $chunkLen);
        $hasMore = $context->builder->icmp(Builder::INT_SLT, $inAfterChunk, $inLen);
        $insertBreak = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_break'));
        $afterBreak = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_after_break'));
        $context->builder->branchIf($hasMore, $insertBreak, $afterBreak);
        $context->builder->positionAtEnd($insertBreak);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outAfterChunk),
            $breakPtr,
            $breakLen,
            false
        );
        $context->builder->store(
            $context->builder->add($outAfterChunk, $breakLen),
            $outPosSlot
        );
        $context->builder->store($inAfterChunk, $inPosSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($afterBreak);
        $context->builder->store($outAfterChunk, $outPosSlot);
        $context->builder->store($inAfterChunk, $inPosSlot);
        $context->builder->branch($loopHead);

        $cutExit = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_cut_exit'));
        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($cutExit);
        $context->builder->positionAtEnd($cutExit);

        return $dest;
    }

    private static function wrapGeneralFull(
        Context $context,
        Value $inLen,
        Value $inPtr,
        Value $width,
        Value $break,
        Value $breakLen,
        Value $breakPtr
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $space = $context->getTypeFromString('int8')->constInt(ord(' '), false);
        $cutI8 = $context->getTypeFromString('int8')->constInt(0, false);

        $outLenSlot = $context->builder->alloca($i64, 1, 'wordwrap_out_len');
        $context->builder->store($zero, $outLenSlot);

        $laststartSlot = $context->builder->alloca($i64, 1, 'wordwrap_g_laststart');
        $lastspaceSlot = $context->builder->alloca($i64, 1, 'wordwrap_g_lastspace');
        $currentSlot = $context->builder->alloca($i64, 1, 'wordwrap_g_current');
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $currentSlot);

        self::generalCountLoop(
            $context,
            $inLen,
            $inPtr,
            $width,
            $breakLen,
            $breakPtr,
            $cutI8,
            $outLenSlot,
            $laststartSlot,
            $lastspaceSlot,
            $currentSlot,
            $space
        );

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));

        $outPosSlot = $context->builder->alloca($i64, 1, 'wordwrap_out_pos');
        $context->builder->store($zero, $outPosSlot);
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $currentSlot);

        self::generalWriteLoop(
            $context,
            $inLen,
            $inPtr,
            $destPtr,
            $width,
            $break,
            $breakLen,
            $breakPtr,
            $cutI8,
            $outPosSlot,
            $laststartSlot,
            $lastspaceSlot,
            $currentSlot,
            $space
        );

        $laststart = $context->builder->load($laststartSlot);
        $tailLen = $context->builder->sub($inLen, $laststart);
        $hasTail = $context->builder->icmp(Builder::INT_NE, $laststart, $inLen);
        $tailBlock = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_tail'));
        $tailDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_tail_done'));
        $context->builder->branchIf($hasTail, $tailBlock, $tailDone);
        $context->builder->positionAtEnd($tailBlock);
        $outPos = $context->builder->load($outPosSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $laststart),
            $tailLen,
            false
        );
        $context->builder->positionAtEnd($tailDone);

        return $dest;
    }

    private static function generalCountLoop(
        Context $context,
        Value $inLen,
        Value $inPtr,
        Value $width,
        Value $breakLen,
        Value $breakPtr,
        Value $cutI8,
        Value $outLenSlot,
        Value $laststartSlot,
        Value $lastspaceSlot,
        Value $currentSlot,
        Value $space
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);

        $loopHead = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_head'));
        $loopBody = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_body'));
        $loopDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_done'));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $current = $context->builder->load($currentSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $current, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $current = $context->builder->load($currentSlot);
        $matched = self::breakMatchesAt($context, $inPtr, $inLen, $current, $breakPtr, $breakLen);
        $onBreak = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_on_break'));
        $onChar = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_on_char'));
        $context->builder->branchIf($matched, $onBreak, $onChar);

        $context->builder->positionAtEnd($onBreak);
        $chunkLen = $context->builder->add(
            $context->builder->sub($current, $context->builder->load($laststartSlot)),
            $breakLen
        );
        self::addToOutLen($context, $outLenSlot, $chunkLen);
        $next = $context->builder->add($current, $breakLen);
        $context->builder->store($next, $laststartSlot);
        $context->builder->store($next, $lastspaceSlot);
        $context->builder->store($next, $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($onChar);
        $ch = $context->builder->load($context->builder->gep($inPtr, $current));
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $ch, $space);
        $spacePath = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_space'));
        $wordPath = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_word'));
        $context->builder->branchIf($isSpace, $spacePath, $wordPath);

        $context->builder->positionAtEnd($spacePath);
        $laststart = $context->builder->load($laststartSlot);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $wrapSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_wrap_space'));
        $afterSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_after_space'));
        $context->builder->branchIf($atWidth, $wrapSpace, $afterSpace);
        $context->builder->positionAtEnd($wrapSpace);
        self::addToOutLen($context, $outLenSlot, $lineLen);
        self::addToOutLen($context, $outLenSlot, $breakLen);
        $context->builder->store($context->builder->add($current, $one), $laststartSlot);
        $context->builder->branch($afterSpace);
        $context->builder->positionAtEnd($afterSpace);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($wordPath);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $cutOn = $context->builder->icmp(
            Builder::INT_NE,
            $cutI8,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $laststartGteSpace = $context->builder->icmp(Builder::INT_SGE, $laststart, $lastspace);
        $laststartLtSpace = $context->builder->icmp(Builder::INT_SLT, $laststart, $lastspace);
        $cutWrap = $context->builder->and($atWidth, $context->builder->and($cutOn, $laststartGteSpace));
        $wordWrap = $context->builder->and($atWidth, $laststartLtSpace);
        $doCut = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_cut'));
        $doWord = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_word_wrap'));
        $skipWord = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_skip_word'));
        $context->builder->branchIf($cutWrap, $doCut, $skipWord);
        $context->builder->positionAtEnd($skipWord);
        $noWrap = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_no_wrap'));
        $context->builder->branchIf($wordWrap, $doWord, $noWrap);
        $context->builder->positionAtEnd($noWrap);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($doCut);
        self::addToOutLen($context, $outLenSlot, $lineLen);
        self::addToOutLen($context, $outLenSlot, $breakLen);
        $context->builder->store($current, $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($doWord);
        $wordChunk = $context->builder->sub($lastspace, $laststart);
        self::addToOutLen($context, $outLenSlot, $wordChunk);
        self::addToOutLen($context, $outLenSlot, $breakLen);
        $nextStart = $context->builder->add($lastspace, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($nextStart, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $countExit = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_count_exit'));
        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($countExit);
        $context->builder->positionAtEnd($countExit);
    }

    private static function generalWriteLoop(
        Context $context,
        Value $inLen,
        Value $inPtr,
        Value $destPtr,
        Value $width,
        Value $break,
        Value $breakLen,
        Value $breakPtr,
        Value $cutI8,
        Value $outPosSlot,
        Value $laststartSlot,
        Value $lastspaceSlot,
        Value $currentSlot,
        Value $space
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $map = $context->structFieldMap['__string__'];

        $loopHead = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_head'));
        $loopBody = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_body'));
        $loopDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_done'));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $current = $context->builder->load($currentSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $current, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $current = $context->builder->load($currentSlot);
        $matched = self::breakMatchesAt($context, $inPtr, $inLen, $current, $breakPtr, $breakLen);
        $onBreak = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_on_break'));
        $onChar = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_on_char'));
        $context->builder->branchIf($matched, $onBreak, $onChar);

        $context->builder->positionAtEnd($onBreak);
        $laststart = $context->builder->load($laststartSlot);
        $chunkLen = $context->builder->add($context->builder->sub($current, $laststart), $breakLen);
        $outPos = $context->builder->load($outPosSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $laststart),
            $chunkLen,
            false
        );
        $context->builder->store($context->builder->add($outPos, $chunkLen), $outPosSlot);
        $next = $context->builder->add($current, $breakLen);
        $context->builder->store($next, $laststartSlot);
        $context->builder->store($next, $lastspaceSlot);
        $context->builder->store($next, $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($onChar);
        $ch = $context->builder->load($context->builder->gep($inPtr, $current));
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $ch, $space);
        $spacePath = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_space'));
        $wordPath = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_word'));
        $context->builder->branchIf($isSpace, $spacePath, $wordPath);

        $context->builder->positionAtEnd($spacePath);
        $laststart = $context->builder->load($laststartSlot);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $wrapSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_wrap_space'));
        $afterSpace = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_after_space'));
        $context->builder->branchIf($atWidth, $wrapSpace, $afterSpace);
        $context->builder->positionAtEnd($wrapSpace);
        $outPos = $context->builder->load($outPosSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $laststart),
            $lineLen,
            false
        );
        $outPos = $context->builder->add($outPos, $lineLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $breakPtr,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outPos, $breakLen), $outPosSlot);
        $context->builder->store($context->builder->add($current, $one), $laststartSlot);
        $context->builder->branch($afterSpace);
        $context->builder->positionAtEnd($afterSpace);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($wordPath);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $cutOn = $context->builder->icmp(
            Builder::INT_NE,
            $cutI8,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $laststartGteSpace = $context->builder->icmp(Builder::INT_SGE, $laststart, $lastspace);
        $laststartLtSpace = $context->builder->icmp(Builder::INT_SLT, $laststart, $lastspace);
        $cutWrap = $context->builder->and($atWidth, $context->builder->and($cutOn, $laststartGteSpace));
        $wordWrap = $context->builder->and($atWidth, $laststartLtSpace);
        $doCut = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_cut'));
        $doWord = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_word_wrap'));
        $skipWord = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_skip_word'));
        $context->builder->branchIf($cutWrap, $doCut, $skipWord);
        $context->builder->positionAtEnd($skipWord);
        $noWrap = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_no_wrap'));
        $context->builder->branchIf($wordWrap, $doWord, $noWrap);
        $context->builder->positionAtEnd($noWrap);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($doCut);
        $outPos = $context->builder->load($outPosSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $laststart),
            $lineLen,
            false
        );
        $outPos = $context->builder->add($outPos, $lineLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $breakPtr,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outPos, $breakLen), $outPosSlot);
        $context->builder->store($current, $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($doWord);
        $wordChunk = $context->builder->sub($lastspace, $laststart);
        $outPos = $context->builder->load($outPosSlot);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $laststart),
            $wordChunk,
            false
        );
        $outPos = $context->builder->add($outPos, $wordChunk);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $breakPtr,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outPos, $breakLen), $outPosSlot);
        $nextStart = $context->builder->add($lastspace, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($nextStart, $lastspaceSlot);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($loopHead);

        $writeExit = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_g_write_exit'));
        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($writeExit);
        $context->builder->positionAtEnd($writeExit);
    }

    private static function breakMatchesAt(
        Context $context,
        Value $inPtr,
        Value $inLen,
        Value $offset,
        Value $breakPtr,
        Value $breakLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $endOk = $context->builder->icmp(Builder::INT_SLT, $context->builder->add($offset, $breakLen), $inLen);
        $first = $context->builder->load($context->builder->gep($inPtr, $offset));
        $breakFirst = $context->builder->load($breakPtr);
        $firstOk = $context->builder->icmp(Builder::INT_EQ, $first, $breakFirst);

        $idxSlot = $context->builder->alloca($i64, 1);
        $matchSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($one, $idxSlot);
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(1, false), $matchSlot);

        $loopHead = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_break_head'));
        $loopBody = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_break_body'));
        $loopDone = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_break_done'));
        $continue = BasicBlockHelper::append($context, self::freshBlockName('wordwrap_break_continue'));
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $breakLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $a = $context->builder->load($context->builder->gep($inPtr, $context->builder->add($offset, $idx)));
        $b = $context->builder->load($context->builder->gep($breakPtr, $idx));
        $eq = $context->builder->icmp(Builder::INT_EQ, $a, $b);
        $still = $context->builder->load($matchSlot);
        $context->builder->store($context->builder->and($still, $eq), $matchSlot);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $allMatch = $context->builder->load($matchSlot);
        $matched = $context->builder->and($endOk, $context->builder->and($firstOk, $allMatch));
        $context->builder->branch($continue);

        $context->builder->positionAtEnd($continue);

        return $matched;
    }

    private static function addToOutLen(Context $context, Value $outLenSlot, Value $add): void
    {
        $cur = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->add($cur, $add), $outLenSlot);
    }
}
