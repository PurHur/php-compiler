<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmValueCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM packed-hashtable scan for {@see \PHPCompiler\ext\standard\ArraySearchJitHelper::searchKey} (#27133).
 *
 * NestedJIT of ArraySearchJitHelper → {@see \PHPCompiler\ext\standard\VmArray::searchKey} left the
 * call as an external stub that silently returns null under thin standalone AOT (#579). Peer:
 * {@see InArrayLlvm} (#27120) / {@see ArraySumLlvm} (#24167).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::searchKey()}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_search)
 *
 * Packed-list keys are the numeric index (Zend ordered-list shape). String-key tables stay on the
 * VM path via {@see ArraySearchJitHelper} when NestedJIT can resolve them.
 */
final class ArraySearchLlvm
{
    private static int $seq = 0;

    /**
     * Scan packed {@see __hashtable__*} slots for {@param $needlePtr}; returns a caller-frame
     * {@see __value__} box (int key on hit, bool false on miss).
     *
     * Strict uses {@see VmValueCompare::identicalValueToValue}. Loose uses the same compare for
     * same-type scalars (Zend == ≡ === for int/int / string/string); cross-type loose coercion
     * stays on the VM path.
     */
    public static function searchKey(
        Context $context,
        Value $needlePtr,
        Value $ht,
        Value $strict
    ): Value {
        unset($strict);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_search_llvm_cont');
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $needleVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $needlePtr
        );

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_search_llvm_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_search_llvm_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_search_llvm_adv_'.$tag);
        $miss = BasicBlockHelper::append($context, 'array_search_llvm_miss_'.$tag);
        $foundBb = BasicBlockHelper::append($context, 'array_search_llvm_found_'.$tag);
        $retBb = BasicBlockHelper::append($context, 'array_search_llvm_ret_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $miss, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $miss, $body);

        $context->builder->positionAtEnd($body);
        $storedVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        $match = VmValueCompare::identicalValueToValue($context, $needleVar, $storedVar);
        $context->builder->branchIf($match, $foundBb, $advance);

        $context->builder->positionAtEnd($foundBb);
        $foundIdx = $context->builder->load($idxSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->builder->zExt($foundIdx, $i64)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($miss);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $resultPtr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);

        return $resultSlot;
    }
}
