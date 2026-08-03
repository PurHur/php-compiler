<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmValueCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM packed-hashtable scan for {@see \PHPCompiler\ext\standard\InArrayJitHelper::contains} (#27120).
 *
 * NestedJIT of InArrayJitHelper → {@see \PHPCompiler\ext\standard\VmArray::contains} left the
 * call as an external stub that silently returns null/false under thin standalone AOT (#579).
 * Peer: {@see ArraySumLlvm} (inline fold; NestedJIT Variable ABI was wrong under AOT).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::contains()}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayLlvm
{
    private static int $seq = 0;

    /**
     * Scan packed {@see __hashtable__*} slots for {@param $needlePtr}; returns i1.
     *
     * Strict uses {@see VmValueCompare::identicalValueToValue}. Loose uses the same compare for
     * same-type scalars (Zend == ≡ === for int/int); cross-type loose coercion stays on the VM path.
     */
    public static function contains(
        Context $context,
        Value $needlePtr,
        Value $ht,
        Value $strict
    ): Value {
        unset($strict);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'in_array_llvm_cont');
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);

        $needleVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $needlePtr
        );

        $foundSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $foundSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'in_array_llvm_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'in_array_llvm_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'in_array_llvm_adv_'.$tag);
        $done = BasicBlockHelper::append($context, 'in_array_llvm_done_'.$tag);
        $foundBb = BasicBlockHelper::append($context, 'in_array_llvm_found_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $done, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $storedVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        $match = VmValueCompare::identicalValueToValue($context, $needleVar, $storedVar);
        $context->builder->branchIf($match, $foundBb, $advance);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->store($trueVal, $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }
}
