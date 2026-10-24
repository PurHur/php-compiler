<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * php-src's zend_sort (Zend/zend_sort.c, PHP 8.2) emitted as LLVM IR.
 *
 * Reproduces the exact comparison sequence — zend_insert_sort for <= 16 elements (sorting
 * networks for 2..5, guarded insertion with 2-step probing above that), median-of-3/5
 * hybrid quicksort above — together with php-src's stable-sort tiebreak
 * (ext/standard/array.c RETURN_STABLE_SORT: equal elements compare by original position,
 * zend_hash_sort_internal's Z_EXTRA). Matching the algorithm, not just "a stable sort",
 * is what makes non-transitive comparisons (mixed-type sort(), inconsistent usort
 * callbacks) and comparator side effects produce php-src's exact output.
 *
 * The array is sorted through an index permutation, so swaps are 8-byte moves and the
 * element type does not matter. The comparator is its own internal function
 * (`i64 cmp(T* a, T* b, i8* extra)`), so the sort's CFG stays reducible (a shared
 * comparator block with a continuation switch crashed LLVM 9's register allocator).
 * The recursion on the smaller partition is an explicit stack processed
 * smaller-part-first (the same order as php-src's recursion).
 */
final class ZendSortLlvm
{
    private Context $context;
    private Builder $b;
    private Value\Function_ $fn;
    private string $tag;
    /** @var \PHPLLVM\Type */
    private $i64;
    private Value $perm;
    private Value $values;
    private ?Value\Function_ $cmpFn = null;
    /** @var callable|null inline comparator (compare emitted at each comparison site) */
    private $inlineCompare = null;
    private Value $extra;

    /**
     * @param Value                          $values   pointer to element 0 (T*)
     * @param Value                          $count    element count (size_t / i64)
     * @param callable(Value, Value, Value): Value $compare emits, inside the comparator
     *        function, the three-way compare of element pointers a, b (with the caller's
     *        $extra pointer as i8*) as an integer whose sign matters
     * @param Value|null $extra  any pointer the comparator needs from the caller's frame
     * @param bool $inline  emit the comparator at every comparison site in the caller's
     *        function instead of a separate function — required when it references the
     *        caller's frame (closures with captured variables); scratch slots it needs must
     *        be entry allocas
     */
    public static function emit(
        Context $context,
        Value $values,
        Value $count,
        callable $compare,
        string $tag,
        ?Value $extra = null,
        bool $inline = false
    ): void {
        (new self($context, $tag))->emitSort($values, $count, $compare, $extra, $inline);
    }

    private function __construct(Context $context, string $tag)
    {
        $this->context = $context;
        $this->b = $context->builder;
        $this->fn = BasicBlockHelper::parentFunction($context);
        $this->tag = $tag.'_zsort';
        $this->i64 = $context->getTypeFromString('int64');
    }

    private function block(string $name): \PHPLLVM\BasicBlock
    {
        return $this->fn->appendBasicBlock($this->tag.'_'.$name);
    }

    private function c(int $v): Value
    {
        return $this->i64->constInt($v, $v < 0);
    }

    private function slot(?Value $init = null): Value
    {
        $s = $this->b->alloca($this->i64);
        if (null !== $init) {
            $this->b->store($init, $s);
        }

        return $s;
    }

    private function emitSort(Value $values, Value $countIn, callable $compare, ?Value $extra, bool $inline): void
    {
        $b = $this->b;
        $i8p = $this->context->getTypeFromString('int8*');
        $this->values = $values;
        $this->extra = null === $extra ? $i8p->constNull() : $b->pointerCast($extra, $i8p);
        if ($inline) {
            $this->inlineCompare = $compare;
        } else {
            $this->cmpFn = $this->emitComparator($values->typeOf(), $compare);
        }
        $i64 = $this->i64;
        $count = $b->intCast($countIn, $i64);
        $done = $this->block('done');
        $work = $this->block('work');
        $b->branchIf($b->icmp(Builder::INT_SLT, $count, $this->c(2)), $done, $work);

        $b->positionAtEnd($work);
        $i64p = $this->context->getTypeFromString('int64*');
        $malloc = $this->context->lookupFunction('__mm__malloc');
        $free = $this->context->lookupFunction('__mm__free');
        $this->perm = $b->pointerCast($b->call($malloc, $b->mul($count, $this->c(8))), $i64p);
        $base = $this->slot($this->c(0));
        $nmemb = $this->slot($count);
        $stack = $b->pointerCast($b->call($malloc, $this->c(2 * 8 * 128)), $i64p);
        $sp = $this->slot($this->c(0));
        $i = $this->slot();
        $j = $this->slot();
        $k = $this->slot();
        $pivot = $this->slot();

        // perm[x] = x
        $this->loop('init', $this->c(0), $count, function (Value $x): void {
            $this->b->store($x, $this->b->gep($this->perm, $x));
        });

        $loopTop = $this->block('loop_top');
        $b->branch($loopTop);

        // zend_sort main loop
        $b->positionAtEnd($loopTop);
        $small = $this->block('small');
        $partition = $this->block('partition');
        $pop = $this->block('pop');
        $b->branchIf($b->icmp(Builder::INT_SLE, $b->load($nmemb), $this->c(16)), $small, $partition);

        $b->positionAtEnd($small);
        $this->insertSort($b->load($base), $b->load($nmemb), $i, $j, $k);
        $b->branch($pop);

        $b->positionAtEnd($partition);
        $start = $b->load($base);
        $n = $b->load($nmemb);
        $end = $b->add($start, $n);
        $offset = $b->lShr($n, $this->c(1));
        $piv = $b->add($start, $offset);
        $big = $this->block('median5');
        $med3 = $this->block('median3');
        $afterMed = $this->block('after_median');
        $b->branchIf($b->icmp(Builder::INT_NE, $b->lShr($n, $this->c(10)), $this->c(0)), $big, $med3);
        $b->positionAtEnd($big);
        $delta = $b->lShr($offset, $this->c(1));
        $this->sort5($start, $b->add($start, $delta), $piv, $b->add($piv, $delta), $b->sub($end, $this->c(1)));
        $b->branch($afterMed);
        $b->positionAtEnd($med3);
        $this->sort3($start, $piv, $b->sub($end, $this->c(1)));
        $b->branch($afterMed);

        $b->positionAtEnd($afterMed);
        $start = $b->load($base);
        $end = $b->add($start, $b->load($nmemb));
        $piv = $b->add($start, $b->lShr($b->load($nmemb), $this->c(1)));
        $this->swap($b->add($start, $this->c(1)), $piv);
        $b->store($b->add($start, $this->c(1)), $pivot);
        $b->store($b->add($start, $this->c(2)), $i);
        $b->store($b->sub($end, $this->c(1)), $j);
        $scanI = $this->block('scan_i');
        $scanIStep = $this->block('scan_i_step');
        $afterI = $this->block('after_i');
        $scanJ = $this->block('scan_j');
        $scanJStep = $this->block('scan_j_step');
        $afterJ = $this->block('after_j');
        $partDone = $this->block('part_done');
        $b->branch($scanI);

        // while (cmp(pivot, i) > 0) { i++; if (i == j) goto done; }
        $b->positionAtEnd($scanI);
        $this->gtThen($b->load($pivot), $b->load($i), $scanIStep, $afterI);
        $b->positionAtEnd($scanIStep);
        $b->store($b->add($b->load($i), $this->c(1)), $i);
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($i), $b->load($j)), $partDone, $scanI);

        // j--; if (j == i) goto done;
        $b->positionAtEnd($afterI);
        $b->store($b->sub($b->load($j), $this->c(1)), $j);
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($j), $b->load($i)), $partDone, $scanJ);

        // while (cmp(j, pivot) > 0) { j--; if (j == i) goto done; }
        $b->positionAtEnd($scanJ);
        $this->gtThen($b->load($j), $b->load($pivot), $scanJStep, $afterJ);
        $b->positionAtEnd($scanJStep);
        $b->store($b->sub($b->load($j), $this->c(1)), $j);
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($j), $b->load($i)), $partDone, $scanJ);

        // swp(i, j); i++; if (i == j) goto done;
        $b->positionAtEnd($afterJ);
        $this->swap($b->load($i), $b->load($j));
        $b->store($b->add($b->load($i), $this->c(1)), $i);
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($i), $b->load($j)), $partDone, $scanI);

        // swp(pivot, i - 1); recurse on the smaller side first, loop on the larger.
        $b->positionAtEnd($partDone);
        $iv = $b->load($i);
        $this->swap($b->load($pivot), $b->sub($iv, $this->c(1)));
        $start = $b->load($base);
        $end = $b->add($start, $b->load($nmemb));
        $leftN = $b->sub($b->sub($iv, $start), $this->c(1));
        $rightN = $b->sub($end, $iv);
        $leftFirst = $b->icmp(Builder::INT_SLT, $b->sub($b->sub($iv, $this->c(1)), $start), $rightN);
        // push the deferred part, continue with the one php-src recurses into
        $deferBase = $b->select($leftFirst, $iv, $start);
        $deferN = $b->select($leftFirst, $rightN, $leftN);
        $nowBase = $b->select($leftFirst, $start, $iv);
        $nowN = $b->select($leftFirst, $leftN, $rightN);
        $top = $b->load($sp);
        $b->store($deferBase, $b->gep($stack, $b->mul($top, $this->c(2))));
        $b->store($deferN, $b->gep($stack, $b->add($b->mul($top, $this->c(2)), $this->c(1))));
        $b->store($b->add($top, $this->c(1)), $sp);
        $b->store($nowBase, $base);
        $b->store($nowN, $nmemb);
        $b->branch($loopTop);

        $b->positionAtEnd($pop);
        $finish = $this->block('finish');
        $popOne = $this->block('pop_one');
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($sp), $this->c(0)), $finish, $popOne);
        $b->positionAtEnd($popOne);
        $top = $b->sub($b->load($sp), $this->c(1));
        $b->store($top, $sp);
        $b->store($b->load($b->gep($stack, $b->mul($top, $this->c(2)))), $base);
        $b->store($b->load($b->gep($stack, $b->add($b->mul($top, $this->c(2)), $this->c(1)))), $nmemb);
        $b->branch($loopTop);

        // Apply the permutation through a scratch copy.
        $b->positionAtEnd($finish);
        $elemSize = $b->ptrToInt($b->gep($values->typeOf()->constNull(), $this->c(1)), $i64);
        $bytes = $b->mul($count, $elemSize);
        $scratch = $b->pointerCast($b->call($malloc, $bytes), $values->typeOf());
        LibcExtern::ensureMemcpyDecl($this->context);
        $memcpy = $this->context->lookupFunction('memcpy');
        $this->loop('gather', $this->c(0), $count, function (Value $x) use ($values, $scratch, $memcpy, $elemSize, $i8p): void {
            $src = $this->b->gep($values, $this->b->load($this->b->gep($this->perm, $x)));
            $this->b->call(
                $memcpy,
                $this->b->pointerCast($this->b->gep($scratch, $x), $i8p),
                $this->b->pointerCast($src, $i8p),
                $elemSize
            );
        });
        $b->call($memcpy, $b->pointerCast($values, $i8p), $b->pointerCast($scratch, $i8p), $bytes);
        $b->call($free, $b->pointerCast($scratch, $i8p));
        $b->call($free, $b->pointerCast($this->perm, $i8p));
        $b->call($free, $b->pointerCast($stack, $i8p));
        $b->branch($done);

        $b->positionAtEnd($done);
    }

    /** Branch to $then if cmp(x, y) > 0 (with tiebreak), else to $else. */
    private function gtThen(Value $x, Value $y, \PHPLLVM\BasicBlock $then, \PHPLLVM\BasicBlock $else): void
    {
        $this->b->branchIf($this->gt($x, $y), $then, $else);
    }

    /** i1: cmp(values[perm[x]], values[perm[y]]) > 0, ties by original position. */
    private function gt(Value $x, Value $y): Value
    {
        $b = $this->b;
        $ix = $b->load($b->gep($this->perm, $x));
        $iy = $b->load($b->gep($this->perm, $y));
        $res = null !== $this->cmpFn
            ? $b->call($this->cmpFn, $b->gep($this->values, $ix), $b->gep($this->values, $iy), $this->extra)
            : $b->intCast(($this->inlineCompare)($b->gep($this->values, $ix), $b->gep($this->values, $iy), $this->extra), $this->i64);

        return $b->bitwiseOr(
            $b->icmp(Builder::INT_SGT, $res, $this->c(0)),
            $b->bitwiseAnd($b->icmp(Builder::INT_EQ, $res, $this->c(0)), $b->icmp(Builder::INT_SGT, $ix, $iy))
        );
    }

    /** `internal i64 <tag>_cmp(T* a, T* b, i8* extra)` around the caller's compare emitter. */
    private function emitComparator(\PHPLLVM\Type $elemPtrTy, callable $compare): Value\Function_
    {
        static $seq = 0;
        $name = '__phpc_zsort_cmp_'.$this->tag.'_'.(++$seq);
        $fn = $this->context->module->addFunction($name, $this->context->context->functionType(
            $this->i64,
            false,
            $elemPtrTy,
            $elemPtrTy,
            $this->context->getTypeFromString('int8*')
        ));
        $this->context->llvm->lib->LLVMSetLinkage($fn->value, \llvm\llvm::LLVMInternalLinkage);
        $saved = BasicBlockHelper::tryGetInsertBlock($this->context);
        // Scope lowering to the comparator: lazily materialized helpers (first use of
        // __value__copy etc.) restore the insert point relative to loweringLlvmFunction.
        BasicBlockHelper::scopeLoweringToFunction($this->context, $fn, $name, function () use ($fn, $compare): void {
            $this->b->positionAtEnd($fn->appendBasicBlock('entry'));
            $res = $compare($fn->getParam(0), $fn->getParam(1), $fn->getParam(2));
            $this->b->returnValue($this->b->intCast($res, $this->i64));
        });
        if (null !== $saved) {
            $this->b->positionAtEnd($saved);
        }

        return $fn;
    }

    private function swap(Value $x, Value $y): void
    {
        $b = $this->b;
        $px = $b->gep($this->perm, $x);
        $py = $b->gep($this->perm, $y);
        $vx = $b->load($px);
        $vy = $b->load($py);
        $b->store($vy, $px);
        $b->store($vx, $py);
    }

    /** if (gt(x, y)) swp(x, y) */
    private function sort2(Value $x, Value $y): void
    {
        $sw = $this->block('s2_swap');
        $out = $this->block('s2_out');
        $this->gtThen($x, $y, $sw, $out);
        $this->b->positionAtEnd($sw);
        $this->swap($x, $y);
        $this->b->branch($out);
        $this->b->positionAtEnd($out);
    }

    private function sort3(Value $a, Value $bb, Value $c): void
    {
        $b = $this->b;
        $out = $this->block('s3_out');
        $abGt = $this->block('s3_ab_gt');
        $abLe = $this->block('s3_ab_le');
        $this->gtThen($a, $bb, $abGt, $abLe);

        // !(a > b): if !(b > c) return; swp(b,c); if (a > b) swp(a,b)
        $b->positionAtEnd($abLe);
        $bcGt = $this->block('s3_bc_gt');
        $this->gtThen($bb, $c, $bcGt, $out);
        $b->positionAtEnd($bcGt);
        $this->swap($bb, $c);
        $this->sort2($a, $bb);
        $b->branch($out);

        // a > b: if !(c > b) { swp(a,c); return; } swp(a,b); if (b > c) swp(b,c)
        $b->positionAtEnd($abGt);
        $cbGt = $this->block('s3_cb_gt');
        $cbLe = $this->block('s3_cb_le');
        $this->gtThen($c, $bb, $cbGt, $cbLe);
        $b->positionAtEnd($cbLe);
        $this->swap($a, $c);
        $b->branch($out);
        $b->positionAtEnd($cbGt);
        $this->swap($a, $bb);
        $this->sort2($bb, $c);
        $b->branch($out);

        $b->positionAtEnd($out);
    }

    /** zend_sort_4 / zend_sort_5 tail: bubble the new last element down while out of order. */
    private function sinkLast(array $pos): void
    {
        $b = $this->b;
        $out = $this->block('sink_out');
        for ($m = \count($pos) - 1; $m >= 1; --$m) {
            $sw = $this->block('sink_swap');
            $this->gtThen($pos[$m - 1], $pos[$m], $sw, $out);
            $b->positionAtEnd($sw);
            $this->swap($pos[$m - 1], $pos[$m]);
        }
        $b->branch($out);
        $b->positionAtEnd($out);
    }

    private function sort4(Value $a, Value $bb, Value $c, Value $d): void
    {
        $this->sort3($a, $bb, $c);
        $this->sinkLast([$a, $bb, $c, $d]);
    }

    private function sort5(Value $a, Value $bb, Value $c, Value $d, Value $e): void
    {
        $this->sort4($a, $bb, $c, $d);
        $this->sinkLast([$a, $bb, $c, $d, $e]);
    }

    /** zend_insert_sort(base, n) */
    private function insertSort(Value $start, Value $n, Value $i, Value $j, Value $k): void
    {
        $b = $this->b;
        $out = $this->block('ins_out');
        $general = $this->block('ins_general');
        $cases = [];
        foreach ([2, 3, 4, 5] as $size) {
            $cases[$size] = $this->block('ins_n'.$size);
        }
        $sw = $b->branchSwitch($n, $general, 6);
        $sw->addCase($this->c(0), $out);
        $sw->addCase($this->c(1), $out);
        foreach ($cases as $size => $blk) {
            $sw->addCase($this->c($size), $blk);
        }
        $p = fn (int $o): Value => $b->add($start, $this->c($o));
        $b->positionAtEnd($cases[2]);
        $this->sort2($p(0), $p(1));
        $b->branch($out);
        $b->positionAtEnd($cases[3]);
        $this->sort3($p(0), $p(1), $p(2));
        $b->branch($out);
        $b->positionAtEnd($cases[4]);
        $this->sort4($p(0), $p(1), $p(2), $p(3));
        $b->branch($out);
        $b->positionAtEnd($cases[5]);
        $this->sort5($p(0), $p(1), $p(2), $p(3), $p(4));
        $b->branch($out);

        $b->positionAtEnd($general);
        $end = $b->add($start, $n);
        $sentry = $b->add($start, $this->c(6));

        // first pass: i in [start+1, sentry), linear probe
        $b->store($b->add($start, $this->c(1)), $i);
        $h1 = $this->block('ins1_head');
        $body1 = $this->block('ins1_body');
        $next1 = $this->block('ins1_next');
        $probe1 = $this->block('ins1_probe');
        $probeStep1 = $this->block('ins1_probe_step');
        $placed1 = $this->block('ins1_placed');
        $pass2 = $this->block('ins2_init');
        $b->branch($h1);
        $b->positionAtEnd($h1);
        $b->branchIf($b->icmp(Builder::INT_SLT, $b->load($i), $sentry), $body1, $pass2);
        $b->positionAtEnd($body1);
        $b->store($b->sub($b->load($i), $this->c(1)), $j);
        $this->gtThen($b->load($j), $b->load($i), $probe1, $next1);
        // while (j != start) { j--; if (!(cmp(j,i) > 0)) { j++; break; } }
        $b->positionAtEnd($probe1);
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($j), $start), $placed1, $probeStep1);
        $b->positionAtEnd($probeStep1);
        $b->store($b->sub($b->load($j), $this->c(1)), $j);
        $stop1 = $this->block('ins1_stop');
        $this->gtThen($b->load($j), $b->load($i), $probe1, $stop1);
        $b->positionAtEnd($stop1);
        $b->store($b->add($b->load($j), $this->c(1)), $j);
        $b->branch($placed1);
        $b->positionAtEnd($placed1);
        $this->rotateDown($i, $j, $k);
        $b->branch($next1);
        $b->positionAtEnd($next1);
        $b->store($b->add($b->load($i), $this->c(1)), $i);
        $b->branch($h1);

        // second pass: i in [sentry, end), 2-step probe
        $b->positionAtEnd($pass2);
        $b->store($sentry, $i);
        $h2 = $this->block('ins2_head');
        $body2 = $this->block('ins2_body');
        $next2 = $this->block('ins2_next');
        $probe2 = $this->block('ins2_probe');
        $placed2 = $this->block('ins2_placed');
        $b->branch($h2);
        $b->positionAtEnd($h2);
        $b->branchIf($b->icmp(Builder::INT_SLT, $b->load($i), $end), $body2, $out);
        $b->positionAtEnd($body2);
        $b->store($b->sub($b->load($i), $this->c(1)), $j);
        $this->gtThen($b->load($j), $b->load($i), $probe2, $next2);

        // do { j -= 2; ... } while (1)
        $b->positionAtEnd($probe2);
        $b->store($b->sub($b->load($j), $this->c(2)), $j);
        $le = $this->block('ins2_le');
        $chkStart = $this->block('ins2_chk_start');
        $this->gtThen($b->load($j), $b->load($i), $chkStart, $le);
        // !(cmp(j,i) > 0): j++; if (!(cmp(j,i) > 0)) j++; break;
        $b->positionAtEnd($le);
        $b->store($b->add($b->load($j), $this->c(1)), $j);
        $bump = $this->block('ins2_bump');
        $this->gtThen($b->load($j), $b->load($i), $placed2, $bump);
        $b->positionAtEnd($bump);
        $b->store($b->add($b->load($j), $this->c(1)), $j);
        $b->branch($placed2);
        // if (j == start) break;
        $b->positionAtEnd($chkStart);
        $chkStart1 = $this->block('ins2_chk_start1');
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($j), $start), $placed2, $chkStart1);
        // if (j == start + 1) { j--; if (cmp(i, j) > 0) j++; break; }
        $b->positionAtEnd($chkStart1);
        $atOne = $this->block('ins2_at_one');
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($j), $b->add($start, $this->c(1))), $atOne, $probe2);
        $b->positionAtEnd($atOne);
        $b->store($b->sub($b->load($j), $this->c(1)), $j);
        $inc = $this->block('ins2_at_one_inc');
        $this->gtThen($b->load($i), $b->load($j), $inc, $placed2);
        $b->positionAtEnd($inc);
        $b->store($b->add($b->load($j), $this->c(1)), $j);
        $b->branch($placed2);

        $b->positionAtEnd($placed2);
        $this->rotateDown($i, $j, $k);
        $b->branch($next2);
        $b->positionAtEnd($next2);
        $b->store($b->add($b->load($i), $this->c(1)), $i);
        $b->branch($h2);

        $b->positionAtEnd($out);
    }

    /** for (k = i; k > j; k--) swp(k, k - 1); */
    private function rotateDown(Value $i, Value $j, Value $k): void
    {
        $b = $this->b;
        $b->store($b->load($i), $k);
        $head = $this->block('rot_head');
        $body = $this->block('rot_body');
        $out = $this->block('rot_out');
        $b->branch($head);
        $b->positionAtEnd($head);
        $b->branchIf($b->icmp(Builder::INT_SGT, $b->load($k), $b->load($j)), $body, $out);
        $b->positionAtEnd($body);
        $kv = $b->load($k);
        $this->swap($kv, $b->sub($kv, $this->c(1)));
        $b->store($b->sub($kv, $this->c(1)), $k);
        $b->branch($head);
        $b->positionAtEnd($out);
    }

    /** for (x = from; x < to; x++) $body(x) */
    private function loop(string $name, Value $from, Value $to, callable $body): void
    {
        $b = $this->b;
        $x = $this->slot($from);
        $head = $this->block($name.'_head');
        $bodyBlock = $this->block($name.'_body');
        $out = $this->block($name.'_out');
        $b->branch($head);
        $b->positionAtEnd($head);
        $b->branchIf($b->icmp(Builder::INT_SLT, $b->load($x), $to), $bodyBlock, $out);
        $b->positionAtEnd($bodyBlock);
        $xv = $b->load($x);
        $body($xv);
        $b->store($b->add($xv, $this->c(1)), $x);
        $b->branch($head);
        $b->positionAtEnd($out);
    }
}
