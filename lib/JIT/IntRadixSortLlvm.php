<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Stable LSD radix sort of signed 64-bit keys (8 passes of 8 bits, constant-byte passes
 * skipped).
 *
 * Used when every element of an array being sorted is an int: integer comparison is a
 * consistent total order, so every correct stable sort yields exactly php-src's result
 * (zend_sort + positional tiebreak), and radix sort does it in O(n) without a single
 * comparator call.
 */
final class IntRadixSortLlvm
{
    /**
     * Sort `$count` i64 keys at `$keys` in place, ascending (or descending when $reverse).
     */
    public static function emit(Context $context, Value $keys, Value $count, bool $reverse, string $tag): void
    {
        $b = $context->builder;
        $fn = BasicBlockHelper::parentFunction($context);
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $i8p = $context->getTypeFromString('int8*');
        $c = static fn (int $v): Value => $i64->constInt($v, $v < 0);
        $malloc = $context->lookupFunction('__mm__malloc');
        $free = $context->lookupFunction('__mm__free');
        LibcExtern::ensureMemcpyDecl($context);
        $memcpy = $context->lookupFunction('memcpy');
        $blk = static fn (string $n) => $fn->appendBasicBlock($tag.'_radix_'.$n);
        $loop = static function (string $name, Value $from, Value $to, callable $body) use ($b, $i64, $c, $blk): void {
            $x = $b->alloca($i64);
            $b->store($from, $x);
            $head = $blk($name.'_head');
            $bodyB = $blk($name.'_body');
            $out = $blk($name.'_out');
            $b->branch($head);
            $b->positionAtEnd($head);
            $b->branchIf($b->icmp(Builder::INT_SLT, $b->load($x), $to), $bodyB, $out);
            $b->positionAtEnd($bodyB);
            $xv = $b->load($x);
            $body($xv);
            $b->store($b->add($xv, $c(1)), $x);
            $b->branch($head);
            $b->positionAtEnd($out);
        };

        $n = $b->intCast($count, $i64);
        // Map signed order to unsigned order (flip the sign bit); descending also inverts.
        $flip = $reverse ? $c(\PHP_INT_MAX) : $c(\PHP_INT_MIN);
        $loop('enc', $c(0), $n, static function (Value $i) use ($b, $keys, $flip): void {
            $p = $b->gep($keys, $i);
            $b->store($b->xor($b->load($p), $flip), $p);
        });
        $tmp = $b->pointerCast($b->call($malloc, $b->mul($n, $c(8))), $i64p);
        $counts = $b->pointerCast($b->call($malloc, $c(256 * 8)), $i64p);
        $src = $b->alloca($i64p);
        $dst = $b->alloca($i64p);
        $b->store($keys, $src);
        $b->store($tmp, $dst);
        LibcExtern::ensureMemsetDecl($context);
        $memset = $context->lookupFunction('memset');

        for ($pass = 0; $pass < 8; ++$pass) {
            $shift = $c(8 * $pass);
            $s = $b->load($src);
            $b->call($memset, $b->pointerCast($counts, $i8p), $context->getTypeFromString('int32')->constInt(0, false), $c(256 * 8));
            $loop('cnt'.$pass, $c(0), $n, static function (Value $i) use ($b, $s, $shift, $counts, $c): void {
                $byte = $b->bitwiseAnd($b->lShr($b->load($b->gep($s, $i)), $shift), $c(255));
                $p = $b->gep($counts, $byte);
                $b->store($b->add($b->load($p), $c(1)), $p);
            });
            // Skip a pass whose byte is the same for every key.
            $first = $b->bitwiseAnd($b->lShr($b->load($b->gep($s, $c(0))), $shift), $c(255));
            $doPass = $blk('do'.$pass);
            $skip = $blk('skip'.$pass);
            $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($b->gep($counts, $first)), $n), $skip, $doPass);
            $b->positionAtEnd($doPass);
            // exclusive prefix sums
            $sum = $b->alloca($i64);
            $b->store($c(0), $sum);
            $loop('pre'.$pass, $c(0), $c(256), static function (Value $k) use ($b, $counts, $sum): void {
                $p = $b->gep($counts, $k);
                $v = $b->load($p);
                $b->store($b->load($sum), $p);
                $b->store($b->add($b->load($sum), $v), $sum);
            });
            $d = $b->load($dst);
            $loop('scat'.$pass, $c(0), $n, static function (Value $i) use ($b, $s, $d, $shift, $counts, $c): void {
                $key = $b->load($b->gep($s, $i));
                $byte = $b->bitwiseAnd($b->lShr($key, $shift), $c(255));
                $p = $b->gep($counts, $byte);
                $pos = $b->load($p);
                $b->store($key, $b->gep($d, $pos));
                $b->store($b->add($pos, $c(1)), $p);
            });
            $b->store($d, $src);
            $b->store($s, $dst);
            $b->branch($skip);
            $b->positionAtEnd($skip);
        }

        $result = $b->load($src);
        $copy = $blk('copy');
        $after = $blk('after');
        $b->branchIf($b->icmp(Builder::INT_EQ, $result, $keys), $after, $copy);
        $b->positionAtEnd($copy);
        $b->call($memcpy, $b->pointerCast($keys, $i8p), $b->pointerCast($result, $i8p), $b->mul($n, $c(8)));
        $b->branch($after);
        $b->positionAtEnd($after);
        $loop('dec', $c(0), $n, static function (Value $i) use ($b, $keys, $flip): void {
            $p = $b->gep($keys, $i);
            $b->store($b->xor($b->load($p), $flip), $p);
        });
        $b->call($free, $b->pointerCast($tmp, $i8p));
        $b->call($free, $b->pointerCast($counts, $i8p));
    }
}
