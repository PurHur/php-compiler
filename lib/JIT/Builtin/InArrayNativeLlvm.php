<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\VmValueCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Native {@see __hashtable__*} scan for in_array() (#6229).
 *
 * Iterates packed slots via nextFreeElement — matches inline registry tables
 * from hash_hmac_algos / hash_algos. SSOT compare: {@see VmValueCompare}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayNativeLlvm
{
    public static function contains(
        Context $context,
        Value $needlePtr,
        Value $ht,
        Value $strict
    ): Value {
        unset($strict);
        $htMap = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
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

        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $limit = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $context->builder->store($zero, $indexSlot);

        $head = BasicBlockHelper::append($context, 'in_array_scan_head');
        $body = BasicBlockHelper::append($context, 'in_array_scan_body');
        $done = BasicBlockHelper::append($context, 'in_array_scan_done');
        $foundBb = BasicBlockHelper::append($context, 'in_array_scan_found');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $limit);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $entry = $context->builder->gep($valuesBase, $idx);
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $skipBb = BasicBlockHelper::append($context, 'in_array_scan_skip');
        $compareBb = BasicBlockHelper::append($context, 'in_array_scan_compare');
        $context->builder->branchIf($isNull, $skipBb, $compareBb);

        $context->builder->positionAtEnd($compareBb);
        $storedVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        $match = VmValueCompare::identicalValueToValue($context, $needleVar, $storedVar);
        $context->builder->branchIf($match, $foundBb, $skipBb);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->returnValue($trueVal);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $falseVal;
    }
}
