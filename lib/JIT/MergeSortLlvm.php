<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Stable O(n log n) in-place sort of a contiguous element array (bottom-up merge sort with
 * a heap scratch buffer).
 *
 * Replaces the bubble sorts the sort()/rsort()/usort()/… lowerings used to emit, which were
 * O(n²) — sorting 1.5M ints never finished. Stability matters for php-src parity: since PHP
 * 8.0 sort() is stable (zend_sort + bucket-order tiebreak), so equal elements keep their
 * relative order; taking the left element unless it is strictly greater preserves that.
 *
 * The comparator is emitted inline once, in the merge loop, by the caller's callback.
 */
final class MergeSortLlvm
{
    /**
     * @param Value                          $base       pointer to the first element (T*)
     * @param Value                          $count      element count (size_t)
     * @param callable(Value, Value): Value  $outOfOrder emits `a > b` (i1) for element pointers;
     *                                                   may append blocks, must leave the builder
     *                                                   in the block where the i1 is available
     */
    public static function emit(Context $context, Value $base, Value $count, callable $outOfOrder, string $tag): void
    {
        $b = $context->builder;
        $fn = BasicBlockHelper::parentFunction($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $elemPtrTy = $base->typeOf();
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);

        $elemSize = $b->ptrToInt(
            $b->gep($elemPtrTy->constNull(), $one),
            $sizeT
        );
        $bytes = $b->mul($count, $elemSize);

        $done = $fn->appendBasicBlock($tag.'_msort_done');
        $work = $fn->appendBasicBlock($tag.'_msort_work');
        $b->branchIf($b->icmp(Builder::INT_ULT, $count, $two), $done, $work);

        $b->positionAtEnd($work);
        $scratch = $b->pointerCast($b->call($context->lookupFunction('__mm__malloc'), $bytes), $elemPtrTy);
        $srcSlot = $b->alloca($elemPtrTy);
        $dstSlot = $b->alloca($elemPtrTy);
        $widthSlot = $b->alloca($sizeT);
        $loSlot = $b->alloca($sizeT);
        $iSlot = $b->alloca($sizeT);
        $jSlot = $b->alloca($sizeT);
        $kSlot = $b->alloca($sizeT);
        $b->store($base, $srcSlot);
        $b->store($scratch, $dstSlot);
        $b->store($one, $widthSlot);

        $passHead = $fn->appendBasicBlock($tag.'_msort_pass_head');
        $passBody = $fn->appendBasicBlock($tag.'_msort_pass_body');
        $finish = $fn->appendBasicBlock($tag.'_msort_finish');
        $b->branch($passHead);

        // for (width = 1; width < n; width *= 2)
        $b->positionAtEnd($passHead);
        $width = $b->load($widthSlot);
        $b->branchIf($b->icmp(Builder::INT_ULT, $width, $count), $passBody, $finish);

        $b->positionAtEnd($passBody);
        $b->store($zero, $loSlot);
        $runHead = $fn->appendBasicBlock($tag.'_msort_run_head');
        $runBody = $fn->appendBasicBlock($tag.'_msort_run_body');
        $passEnd = $fn->appendBasicBlock($tag.'_msort_pass_end');
        $b->branch($runHead);

        // for (lo = 0; lo < n; lo += 2*width)
        $b->positionAtEnd($runHead);
        $lo = $b->load($loSlot);
        $b->branchIf($b->icmp(Builder::INT_ULT, $lo, $count), $runBody, $passEnd);

        $b->positionAtEnd($runBody);
        $widthR = $b->load($widthSlot);
        $mid = self::umin($context, $b->add($lo, $widthR), $count);
        $hi = self::umin($context, $b->add($lo, $b->mul($widthR, $two)), $count);
        $src = $b->load($srcSlot);
        $dst = $b->load($dstSlot);
        $b->store($lo, $iSlot);
        $b->store($mid, $jSlot);
        $b->store($lo, $kSlot);
        $mergeHead = $fn->appendBasicBlock($tag.'_msort_merge_head');
        $mergeBoth = $fn->appendBasicBlock($tag.'_msort_merge_both');
        $takeLeft = $fn->appendBasicBlock($tag.'_msort_take_left');
        $takeRight = $fn->appendBasicBlock($tag.'_msort_take_right');
        $leftTail = $fn->appendBasicBlock($tag.'_msort_left_tail');
        $b->branch($mergeHead);

        // while (i < mid && j < hi)
        $b->positionAtEnd($mergeHead);
        $i = $b->load($iSlot);
        $j = $b->load($jSlot);
        $bothLeft = $b->bitwiseAnd(
            $b->icmp(Builder::INT_ULT, $i, $mid),
            $b->icmp(Builder::INT_ULT, $j, $hi)
        );
        $b->branchIf($bothLeft, $mergeBoth, $leftTail);

        $b->positionAtEnd($mergeBoth);
        $gt = $outOfOrder($b->inBoundsGep($src, $i), $b->inBoundsGep($src, $j));
        $b->branchIf($gt, $takeRight, $takeLeft);

        $b->positionAtEnd($takeLeft);
        $iL = $b->load($iSlot);
        $kL = $b->load($kSlot);
        $b->store($b->load($b->inBoundsGep($src, $iL)), $b->inBoundsGep($dst, $kL));
        $b->store($b->add($iL, $one), $iSlot);
        $b->store($b->add($kL, $one), $kSlot);
        $b->branch($mergeHead);

        $b->positionAtEnd($takeRight);
        $jR = $b->load($jSlot);
        $kR = $b->load($kSlot);
        $b->store($b->load($b->inBoundsGep($src, $jR)), $b->inBoundsGep($dst, $kR));
        $b->store($b->add($jR, $one), $jSlot);
        $b->store($b->add($kR, $one), $kSlot);
        $b->branch($mergeHead);

        // Tails: copy src[i..mid) then src[j..hi) — at most one is non-empty.
        $b->positionAtEnd($leftTail);
        $iT = $b->load($iSlot);
        $jT = $b->load($jSlot);
        $kT = $b->load($kSlot);
        $memcpy = self::memcpyFn($context);
        $leftLen = $b->sub($mid, $iT);
        $b->call(
            $memcpy,
            $b->pointerCast($b->inBoundsGep($dst, $kT), $i8p),
            $b->pointerCast($b->inBoundsGep($src, $iT), $i8p),
            $b->mul($leftLen, $elemSize)
        );
        $k2 = $b->add($kT, $leftLen);
        $b->call(
            $memcpy,
            $b->pointerCast($b->inBoundsGep($dst, $k2), $i8p),
            $b->pointerCast($b->inBoundsGep($src, $jT), $i8p),
            $b->mul($b->sub($hi, $jT), $elemSize)
        );
        $b->store($hi, $loSlot);
        $b->branch($runHead);

        $b->positionAtEnd($passEnd);
        $s = $b->load($srcSlot);
        $d = $b->load($dstSlot);
        $b->store($d, $srcSlot);
        $b->store($s, $dstSlot);
        $b->store($b->mul($b->load($widthSlot), $two), $widthSlot);
        $b->branch($passHead);

        // Result lives in src; copy back if that is the scratch buffer.
        $b->positionAtEnd($finish);
        $result = $b->load($srcSlot);
        $copyBack = $fn->appendBasicBlock($tag.'_msort_copy_back');
        $freeBlock = $fn->appendBasicBlock($tag.'_msort_free');
        $b->branchIf($b->icmp(Builder::INT_EQ, $result, $base), $freeBlock, $copyBack);
        $b->positionAtEnd($copyBack);
        $b->call($memcpy, $b->pointerCast($base, $i8p), $b->pointerCast($result, $i8p), $bytes);
        $b->branch($freeBlock);
        $b->positionAtEnd($freeBlock);
        $b->call($context->lookupFunction('__mm__free'), $b->pointerCast($scratch, $i8p));
        $b->branch($done);

        $b->positionAtEnd($done);
    }

    private static function umin(Context $context, Value $a, Value $b): Value
    {
        return $context->builder->select($context->builder->icmp(Builder::INT_ULT, $a, $b), $a, $b);
    }

    private static function memcpyFn(Context $context): Value\Function_
    {
        LibcExtern::ensureMemcpyDecl($context);

        return $context->lookupFunction('memcpy');
    }
}
