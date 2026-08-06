<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\ArrayFillKeysJitHelper::fillKeysCopy()} (#27127).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFillKeysJitHelper}
 * failed link (`__compiler_is_resource`) or segfault / return `{}` under
 * `PHP_COMPILER_HELPER_RUNTIME_O=0` (peer {@see HashTableFillLlvm} / #27073,
 * {@see HashTableCombineLlvm} / #27132).
 *
 * Values of {@param $keysHt} become result keys (Zend iteration order); {@param $valuePtr}
 * is the uniform fill — key coercion via {@see HashTableCombineLlvm::storeCombineKey()}.
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::fillKeys()} /
 * {@see \PHPCompiler\ext\standard\ArrayFillKeysJitHelper}.
 * php-src: ext/standard/array.c — php_array_fill_keys()
 */
final class HashTableFillKeysLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $keysHt   __hashtable__* — values become result keys
     * @param Value $valuePtr __value__* — uniform fill value
     *
     * @return Value __hashtable__*
     */
    public static function fillKeys(Context $context, Value $keysHt, Value $valuePtr): Value
    {
        $tag = (string) self::nextSeq();
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $dest = HashTableHelper::alloc($context);
        $fillVar = self::valueVar($context, $valuePtr);
        // Zend iterateKeyed values — same order as VmArray::fillKeys().
        $keyList = HashTableValuesLlvm::values($context, $keysHt);
        $nKeys = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $keyList
        );

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_fill_keys_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_fill_keys_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_fill_keys_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $nKeys);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $keyList, $idx);
        HashTableCombineLlvm::storeCombineKey($context, $dest, $keyVar, $fillVar);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function valueVar(Context $context, Value $valuePtr): Variable
    {
        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valuePtr);
    }
}
