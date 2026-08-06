<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\ArrayFillJitHelper::fillCopy()} (#27073).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFillJitHelper} bitcasts the
 * `__value__*` fill value to `__object__*` (Variable) via {@see JitNestedHelperCoerce} and
 * stores garbage object slots — gettype object / segfault after `c:main_before_php`
 * (peer {@see HashTablePadLlvm} / #26971, {@see HashTableReverseLlvm} / #27067).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\array_fill} /
 * {@see \PHPCompiler\ext\standard\ArrayFillJitHelper}.
 * php-src: ext/standard/array.c — php_array_fill()
 */
final class HashTableFillLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $startIndex int64
     * @param Value $count      int64 (already validated >= 0 at call site)
     * @param Value $valuePtr   __value__*
     *
     * @return Value __hashtable__*
     */
    public static function fill(Context $context, Value $startIndex, Value $count, Value $valuePtr): Value
    {
        $tag = (string) self::nextSeq();
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $dest = HashTableHelper::alloc($context);
        $fillVar = self::valueVar($context, $valuePtr);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero64, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_fill_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_fill_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_fill_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_fill_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $key = $context->builder->addNoSignedWrap($startIndex, $idx);
        // Packed set*At APIs take size_t; non-negative start_index matches php-src packed fill.
        $keySize = $context->builder->truncOrBitCast($key, $sizeT);
        HashTableHelper::setAtIndex($context, $dest, $keySize, $fillVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one64), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function valueVar(Context $context, Value $valuePtr): Variable
    {
        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valuePtr);
    }
}
