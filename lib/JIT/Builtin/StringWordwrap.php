<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__wordwrap (mirrors VmString::wordwrap).
 */
final class StringWordwrap
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__wordwrap');
        $entry = $fn->appendBasicBlock('wordwrap_main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $width = $fn->getParam(1);
        $break = $fn->getParam(2);
        $cutI8 = $fn->getParam(3);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $spaceOrd = $i64->constInt(32, false);

        $inLen = $context->builder->load($context->builder->structGep($string, $map['length']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $inLen, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'wordwrap_empty');
        $workBlock = BasicBlockHelper::append($context, 'wordwrap_work');
        $doneBlock = BasicBlockHelper::append($context, 'wordwrap_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $breakLen = $context->builder->load($context->builder->structGep($break, $map['length']));
        $cut = $context->builder->icmp(Builder::INT_NE, $cutI8, $i8->constInt(0, false));
        $useFast = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $breakLen, $one),
            $context->builder->not($cut)
        );
        $fastBlock = BasicBlockHelper::append($context, 'wordwrap_fast');
        $generalBlock = BasicBlockHelper::append($context, 'wordwrap_general');
        $context->builder->branchIf($useFast, $fastBlock, $generalBlock);

        $context->builder->positionAtEnd($fastBlock);
        [$fastResult, $fastDone] = self::fastPath($context, $string, $width, $break, $map, $i64, $zero, $one, $spaceOrd);
        $context->builder->positionAtEnd($fastDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($generalBlock);
        [$generalResult, $generalDone] = self::generalPath(
            $context,
            $string,
            $width,
            $break,
            $cut,
            $map,
            $i64,
            $zero,
            $one,
            $spaceOrd
        );
        $context->builder->positionAtEnd($generalDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyStr->typeOf());
        $phi->addIncoming($emptyStr, $emptyBlock);
        $phi->addIncoming($fastResult, $fastDone);
        $phi->addIncoming($generalResult, $generalDone);
        $context->builder->returnValue($phi);
        $context->builder->clearInsertionPosition();
    }

    private static function fastPath(
        Context $context,
        Value $string,
        Value $width,
        Value $break,
        array $map,
        $i64,
        Value $zero,
        Value $one,
        Value $spaceOrd
    ): array {
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($copy, $map['length']));
        $chars = $context->builder->structGep($copy, $map['value']);
        $breakByte = $context->builder->load($context->builder->structGep($break, $map['value']));

        $laststartSlot = $context->builder->alloca($i64, 1, 'ww_fast_laststart');
        $lastspaceSlot = $context->builder->alloca($i64, 1);
        $currentSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $currentSlot);

        $head = BasicBlockHelper::append($context, 'ww_fast_head');
        $body = BasicBlockHelper::append($context, 'ww_fast_body');
        $done = BasicBlockHelper::append($context, 'ww_fast_done');
        $onBreak = BasicBlockHelper::append($context, 'ww_fast_on_break');
        $classify = BasicBlockHelper::append($context, 'ww_fast_classify');
        $onSpace = BasicBlockHelper::append($context, 'ww_fast_on_space');
        $spaceNoWrap = BasicBlockHelper::append($context, 'ww_fast_space_nowrap');
        $spaceWrap = BasicBlockHelper::append($context, 'ww_fast_space_wrap');
        $onOther = BasicBlockHelper::append($context, 'ww_fast_on_other');
        $doWrap = BasicBlockHelper::append($context, 'ww_fast_do_wrap');
        $next = BasicBlockHelper::append($context, 'ww_fast_next');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $current = $context->builder->load($currentSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $current, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $current = $context->builder->load($currentSlot);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $ch = $context->builder->load($context->builder->gep($chars, $current));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isBreak = $context->builder->icmp(Builder::INT_EQ, $ch, $breakByte);
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $chI64, $spaceOrd);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $hasSpace = $context->builder->icmp(Builder::INT_NE, $laststart, $lastspace);

        $context->builder->branchIf($isBreak, $onBreak, $classify);

        $context->builder->positionAtEnd($onBreak);
        $nextStart = $context->builder->add($current, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($nextStart, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($classify);
        $context->builder->branchIf($isSpace, $onSpace, $onOther);

        $context->builder->positionAtEnd($onSpace);
        $context->builder->branchIf($atWidth, $spaceWrap, $spaceNoWrap);

        $context->builder->positionAtEnd($spaceNoWrap);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($spaceWrap);
        $context->builder->store($breakByte, $context->builder->gep($chars, $current));
        $context->builder->store($context->builder->add($current, $one), $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($onOther);
        $needWrap = $context->builder->and($atWidth, $hasSpace);
        $context->builder->branchIf($needWrap, $doWrap, $next);

        $context->builder->positionAtEnd($doWrap);
        $context->builder->store($breakByte, $context->builder->gep($chars, $lastspace));
        $context->builder->store($context->builder->add($lastspace, $one), $laststartSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return [$copy, $done];
    }

    private static function generalPath(
        Context $context,
        Value $string,
        Value $width,
        Value $break,
        Value $cut,
        array $map,
        $i64,
        Value $zero,
        Value $one,
        Value $spaceOrd
    ): array {
        $inLen = $context->builder->load($context->builder->structGep($string, $map['length']));
        $breakLen = $context->builder->load($context->builder->structGep($break, $map['length']));
        $widthSafe = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $width, $zero),
            $width,
            $one
        );
        $chunks = $context->builder->unsignedDiv($inLen, $widthSafe);
        $outCap = $context->builder->add(
            $inLen,
            $context->builder->mul(
                $context->builder->add($chunks, $i64->constInt(2, false)),
                $breakLen
            )
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outCap);
        $destChars = $context->builder->structGep($dest, $map['value']);
        $inChars = $context->builder->structGep($string, $map['value']);
        $breakChars = $context->builder->structGep($break, $map['value']);
        $breakFirst = $context->builder->load($breakChars);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $laststartSlot = $context->builder->alloca($i64, 1);
        $lastspaceSlot = $context->builder->alloca($i64, 1);
        $currentSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $context->builder->store($zero, $laststartSlot);
        $context->builder->store($zero, $lastspaceSlot);
        $context->builder->store($zero, $currentSlot);

        $head = BasicBlockHelper::append($context, 'ww_gen_head');
        $body = BasicBlockHelper::append($context, 'ww_gen_body');
        $loopDone = BasicBlockHelper::append($context, 'ww_gen_loop_done');
        $onBreak = BasicBlockHelper::append($context, 'ww_gen_on_break');
        $classify = BasicBlockHelper::append($context, 'ww_gen_classify');
        $onSpace = BasicBlockHelper::append($context, 'ww_gen_on_space');
        $spaceNoWrap = BasicBlockHelper::append($context, 'ww_gen_space_nowrap');
        $spaceWrap = BasicBlockHelper::append($context, 'ww_gen_space_wrap');
        $onOther = BasicBlockHelper::append($context, 'ww_gen_on_other');
        $cutWrap = BasicBlockHelper::append($context, 'ww_gen_cut_wrap');
        $wordWrap = BasicBlockHelper::append($context, 'ww_gen_word_wrap');
        $next = BasicBlockHelper::append($context, 'ww_gen_next');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $current = $context->builder->load($currentSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $current, $inLen);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $current = $context->builder->load($currentSlot);
        $laststart = $context->builder->load($laststartSlot);
        $lastspace = $context->builder->load($lastspaceSlot);
        $outLen = $context->builder->load($outLenSlot);
        $ch = $context->builder->load($context->builder->gep($inChars, $current));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isBreakFirst = $context->builder->icmp(Builder::INT_EQ, $ch, $breakFirst);
        $roomForBreak = $context->builder->icmp(
            Builder::INT_SLT,
            $context->builder->add($current, $breakLen),
            $inLen
        );
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $chI64, $spaceOrd);
        $lineLen = $context->builder->sub($current, $laststart);
        $atWidth = $context->builder->icmp(Builder::INT_SGE, $lineLen, $width);
        $hasSpace = $context->builder->icmp(Builder::INT_SLT, $laststart, $lastspace);

        $context->builder->branchIf(
            $context->builder->and($isBreakFirst, $roomForBreak),
            $onBreak,
            $classify
        );

        $context->builder->positionAtEnd($onBreak);
        $segLen = $context->builder->add($context->builder->sub($current, $laststart), $breakLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outLen),
            $context->builder->gep($inChars, $laststart),
            $segLen,
            false
        );
        $outAfter = $context->builder->add($outLen, $segLen);
        $context->builder->store($outAfter, $outLenSlot);
        $newCurrent = $context->builder->add($current, $breakLen);
        $context->builder->store($newCurrent, $currentSlot);
        $context->builder->store($newCurrent, $laststartSlot);
        $context->builder->store($newCurrent, $lastspaceSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($classify);
        $context->builder->branchIf($isSpace, $onSpace, $onOther);

        $context->builder->positionAtEnd($onSpace);
        $context->builder->branchIf($atWidth, $spaceWrap, $spaceNoWrap);

        $context->builder->positionAtEnd($spaceNoWrap);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($spaceWrap);
        $segLen = $context->builder->sub($current, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outLen),
            $context->builder->gep($inChars, $laststart),
            $segLen,
            false
        );
        $outAfter = $context->builder->add($outLen, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outAfter),
            $breakChars,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outAfter, $breakLen), $outLenSlot);
        $nextStart = $context->builder->add($current, $one);
        $context->builder->store($nextStart, $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($onOther);
        $cutNow = $context->builder->and(
            $atWidth,
            $cut,
            $context->builder->icmp(Builder::INT_SGE, $laststart, $lastspace)
        );
        $context->builder->branchIf($cutNow, $cutWrap, $wordWrap);

        $context->builder->positionAtEnd($cutWrap);
        $segLen = $context->builder->sub($current, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outLen),
            $context->builder->gep($inChars, $laststart),
            $segLen,
            false
        );
        $outAfter = $context->builder->add($outLen, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outAfter),
            $breakChars,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outAfter, $breakLen), $outLenSlot);
        $context->builder->store($current, $laststartSlot);
        $context->builder->store($current, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($wordWrap);
        $doWrap = $context->builder->and($atWidth, $hasSpace);
        $wrapBlock = BasicBlockHelper::append($context, 'ww_gen_do_word_wrap');
        $context->builder->branchIf($doWrap, $wrapBlock, $next);

        $context->builder->positionAtEnd($wrapBlock);
        $segLen = $context->builder->sub($lastspace, $laststart);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outLen),
            $context->builder->gep($inChars, $laststart),
            $segLen,
            false
        );
        $outAfter = $context->builder->add($outLen, $segLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outAfter),
            $breakChars,
            $breakLen,
            false
        );
        $context->builder->store($context->builder->add($outAfter, $breakLen), $outLenSlot);
        $newStart = $context->builder->add($lastspace, $one);
        $context->builder->store($newStart, $laststartSlot);
        $context->builder->store($newStart, $lastspaceSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($current, $one), $currentSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $laststart = $context->builder->load($laststartSlot);
        $current = $context->builder->load($currentSlot);
        $outLen = $context->builder->load($outLenSlot);
        $remain = $context->builder->sub($current, $laststart);
        $needTail = $context->builder->icmp(Builder::INT_NE, $laststart, $current);
        $tailBlock = BasicBlockHelper::append($context, 'ww_gen_tail');
        $retBlock = BasicBlockHelper::append($context, 'ww_gen_ret');
        $context->builder->branchIf($needTail, $tailBlock, $retBlock);

        $context->builder->positionAtEnd($tailBlock);
        $context->intrinsic->memcpy(
            $context->builder->gep($destChars, $outLen),
            $context->builder->gep($inChars, $laststart),
            $remain,
            false
        );
        $context->builder->store($context->builder->add($outLen, $remain), $outLenSlot);
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        $finalLen = $context->builder->load($outLenSlot);
        $context->builder->store($finalLen, $context->builder->structGep($dest, $map['length']));

        return [$dest, $retBlock];
    }
}
