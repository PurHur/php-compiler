<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Native {@see __hashtable__*} list probe for array_is_list() (#6229).
 *
 * Mirrors exportNumericKeys + empty strKeys chain — matches inline registry
 * tables from hash_hmac_algos / hash_algos without nested PHP iterateKeyed().
 * php-src: ext/standard/array.c — php_array_is_list()
 */
final class ArrayIsListNativeLlvm
{
    public static function isList(Context $context, Value $ht): Value
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $trueVal = $i1->constInt(1, false);
        $falseVal = $i1->constInt(0, false);

        $numElements = $context->builder->load(
            $context->builder->structGep($ht, $htMap['numElements'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $numElements, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'array_is_list_empty');
        $checkStrBb = BasicBlockHelper::append($context, 'array_is_list_check_str');
        $context->builder->branchIf($isEmpty, $emptyBb, $checkStrBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($trueVal);

        $context->builder->positionAtEnd($checkStrBb);
        $headNode = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $hasStrKeys = $context->builder->icmp(Builder::INT_NE, $headNode, $nodePtrTy->constNull());
        $falseStrBb = BasicBlockHelper::append($context, 'array_is_list_false_str');
        $scanBb = BasicBlockHelper::append($context, 'array_is_list_scan');
        $context->builder->branchIf($hasStrKeys, $falseStrBb, $scanBb);

        $context->builder->positionAtEnd($falseStrBb);
        $context->builder->returnValue($falseVal);

        $context->builder->positionAtEnd($scanBb);
        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $expectedSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $foundSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $limit = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $context->builder->store($zero, $indexSlot);
        $context->builder->store($zero, $expectedSlot);
        $context->builder->store($zero, $foundSlot);

        $head = BasicBlockHelper::append($context, 'array_is_list_num_head');
        $body = BasicBlockHelper::append($context, 'array_is_list_num_body');
        $done = BasicBlockHelper::append($context, 'array_is_list_num_done');
        $falseGapBb = BasicBlockHelper::append($context, 'array_is_list_false_gap');
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
        $skipBb = BasicBlockHelper::append($context, 'array_is_list_num_skip');
        $haveBb = BasicBlockHelper::append($context, 'array_is_list_num_have');
        $context->builder->branchIf($isNull, $skipBb, $haveBb);

        $context->builder->positionAtEnd($haveBb);
        $expected = $context->builder->load($expectedSlot);
        $keyMismatch = $context->builder->icmp(Builder::INT_NE, $idx, $expected);
        $countBb = BasicBlockHelper::append($context, 'array_is_list_num_count');
        $context->builder->branchIf($keyMismatch, $falseGapBb, $countBb);

        $context->builder->positionAtEnd($falseGapBb);
        $context->builder->returnValue($falseVal);

        $context->builder->positionAtEnd($countBb);
        $found = $context->builder->load($foundSlot);
        $context->builder->store($context->builder->addNoSignedWrap($found, $one), $foundSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($expected, $one),
            $expectedSlot
        );
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $foundFinal = $context->builder->load($foundSlot);

        return $context->builder->icmp(Builder::INT_EQ, $foundFinal, $numElements);
    }
}
