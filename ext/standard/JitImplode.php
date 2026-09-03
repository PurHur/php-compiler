<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for implode() — glue plus __hashtable__ values (#24010, #26970).
 *
 * Walks packed slots (TYPE_UNDEFINED holes only — keep TYPE_NULL, #33710) then
 * string-key values — php_implode uses ZEND_HASH_FOREACH_VAL (insertion order).
 * Pure string-keyed tables (array_flip) must not be read as packed indices
 * 0..numElements-1 (#26970).
 *
 * Elements are coerced with strval() (php-src php_implode); do not assume __string__*.
 * Rest parts grow via String_::appendInPlace (smart_str_appendl), not full concat (#36386).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitImplode
{
    private static int $seq = 0;

    public static function implode(Context $context, Value $glue, Value $haystack): Value
    {
        $tag = 'im'.(string) ++self::$seq;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroSize = $sizeT->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $strval = new strval();

        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $startedSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int1'));
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $startedSlot);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zeroI64);
        $context->builder->store($emptyStr, $resultSlot);

        self::appendPackedValues($context, $haystack, $glue, $resultSlot, $startedSlot, $strval, $tag);
        self::appendStringKeyValues($context, $haystack, $glue, $resultSlot, $startedSlot, $strval, $tag);

        $result = $context->builder->load($resultSlot);
        BasicBlockHelper::branchToFreshContinue($context, 'implode_continue_'.$tag);

        return $result;
    }

    private static function appendPackedValues(
        Context $context,
        Value $haystack,
        Value $glue,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        string $tag
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($haystack, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'implode_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'implode_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'implode_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'implode_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'implode_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        // Skip TYPE_UNDEFINED holes only — TYPE_NULL is a real value (#33710 / #33705).
        $isUndef = HashTableHelper::packedIndexIsUndefined($context, $haystack, $idx);
        $context->builder->branchIf($isUndef, $next, $take);

        $context->builder->positionAtEnd($take);
        $partBox = HashTableHelper::readIndexedToValueBox($context, $haystack, $idx);
        self::appendPart($context, $glue, $resultSlot, $startedSlot, $strval, $partBox);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendStringKeyValues(
        Context $context,
        Value $haystack,
        Value $glue,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        string $tag
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($haystack, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'implode_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'implode_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'implode_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'implode_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $partBox = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::appendPart($context, $glue, $resultSlot, $startedSlot, $strval, $partBox);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendPart(
        Context $context,
        Value $glue,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        Variable $partBox
    ): void {
        $tag = 'ap'.(string) ++self::$seq;
        $i1 = $context->getTypeFromString('int1');
        $part = $strval->valueToString($context, JitValueBox::pointer($context, $partBox->value));

        $firstBb = BasicBlockHelper::append($context, 'implode_first_'.$tag);
        $restBb = BasicBlockHelper::append($context, 'implode_rest_'.$tag);
        $done = BasicBlockHelper::append($context, 'implode_part_done_'.$tag);

        $started = $context->builder->load($startedSlot);
        $context->builder->branchIf($started, $restBb, $firstBb);

        $glueVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $glue);
        $partVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $part);
        // Skip appendInPlace RHS pin/delref: glue/parts are interned literals or
        // strval temps that must outlive the accumulator grow (pin delref of an
        // interned literal frees it — segfault on implode of ['a','b']) (#36386).
        $glueVar->compileTimeString = '';
        $partVar->compileTimeString = '';
        // Distinct Variable wrappers: appendInPlace names blocks by spl_object_id.
        $destFirst = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VARIABLE, $resultSlot);
        $destGlue = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VARIABLE, $resultSlot);
        $destPart = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VARIABLE, $resultSlot);

        $context->builder->positionAtEnd($firstBb);
        // Grow the malloc'd empty accumulator. Do not __string__separate interned
        // literals into resultSlot — malloc_usable_size on non-heap is UB (#36386).
        $context->type->string->appendInPlace($destFirst, $partVar);
        $context->builder->store($i1->constInt(1, false), $startedSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($restBb);
        // php-src ext/standard/string.c php_implode: smart_str_appendl(delim) then
        // smart_str_appendl(tmp) — geometric growth, not alloc+memcpy of the whole
        // accumulator per element (O(n²) via JitStringConcat).
        $context->type->string->appendInPlace($destGlue, $glueVar);
        $context->type->string->appendInPlace($destPart, $partVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
