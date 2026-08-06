<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure-LLVM LEAVES_ONLY flatten for RecursiveIteratorIterator thin AOT (#26775, #27257).
 *
 * Values go into packed `__spl_ht`; original leaf keys into parallel `__spl_keys` so
 * `iterator_to_array()` overwrites match Zend (parent scalar key 0 then child key 0).
 *
 * Host/VM SSOT: {@see \PHPCompiler\VM\RecursiveLeavesFlattenJitHelper}.
 *
 * php-src: ext/spl/spl_iterators.c — LEAVES_ONLY
 */
final class RecursiveLeavesFlattenRuntime
{
    public const ABI = '__compiler_rii_flatten_leaves';

    private const WALK_ABI = '__compiler_rii_flatten_walk';

    public const PROP_KEYS = '__spl_keys';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            $walk = $context->module->getNamedFunction(self::WALK_ABI);
            if (null !== $walk) {
                $context->registerFunction(self::WALK_ABI, $walk);
            }

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->context->voidType();

        $walkProbe = $context->module->getNamedFunction(self::WALK_ABI);
        $walkFt = $context->context->functionType($void, false, $htPtr, $htPtr, $htPtr);
        $walkFn = null !== $walkProbe ? $walkProbe : $context->module->addFunction(self::WALK_ABI, $walkFt);
        $context->registerFunction(self::WALK_ABI, $walkFn);
        self::emitWalk($context, $walkFn);

        // void flatten(src, out_values, out_keys)
        $ft = $context->context->functionType($void, false, $htPtr, $htPtr, $htPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock('rii_flatten_entry');
        $context->registerFunction(self::ABI, $fn);
        $context->activeFunction = self::ABI;
        $context->builder->positionAtEnd($entry);
        $context->builder->call($walkFn, $fn->getParam(0), $fn->getParam(1), $fn->getParam(2));
        $context->builder->returnVoid();

        $context->activeFunction = $savedActive;
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitWalk(Context $context, Value $walkFn): void
    {
        if ($walkFn->countBasicBlocks() > 0) {
            return;
        }
        $savedActive = $context->activeFunction;
        $context->activeFunction = self::WALK_ABI;
        $entry = $walkFn->appendBasicBlock('rii_walk_entry');
        $context->builder->positionAtEnd($entry);

        $src = $walkFn->getParam(0);
        $outValues = $walkFn->getParam(1);
        $outKeys = $walkFn->getParam(2);
        self::walkPacked($context, $walkFn, $src, $outValues, $outKeys);
        self::walkStringKeys($context, $walkFn, $src, $outValues, $outKeys);
        $context->builder->returnVoid();
        $context->activeFunction = $savedActive;
    }

    private static function walkPacked(
        Context $context,
        Value $walkFn,
        Value $src,
        Value $outValues,
        Value $outKeys
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'rii_walk_packed_head');
        $body = BasicBlockHelper::append($context, 'rii_walk_packed_body');
        $done = BasicBlockHelper::append($context, 'rii_walk_packed_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $advance = BasicBlockHelper::append($context, 'rii_walk_packed_advance');
        $use = BasicBlockHelper::append($context, 'rii_walk_packed_use');
        $context->builder->branchIf($isSet, $use, $advance);

        $context->builder->positionAtEnd($use);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        $keySlot = JitValueBox::alloc($context);
        $idxI64 = $context->builder->zExt($idx, $i64);
        JitValueBox::writeLong($context, $keySlot, $idxI64);
        $keyVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $keySlot);
        self::emitElement($context, $walkFn, $outValues, $outKeys, $elem, $keyVar, $advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($idxSlot), $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeys(
        Context $context,
        Value $walkFn,
        Value $src,
        Value $outValues,
        Value $outKeys
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $head = BasicBlockHelper::append($context, 'rii_walk_str_head');
        $body = BasicBlockHelper::append($context, 'rii_walk_str_body');
        $done = BasicBlockHelper::append($context, 'rii_walk_str_done');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($src, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keySlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $keySlot),
            $keyStr
        );
        $keyVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $keySlot);
        $advance = BasicBlockHelper::append($context, 'rii_walk_str_advance');
        self::emitElement($context, $walkFn, $outValues, $outKeys, $elem, $keyVar, $advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function emitElement(
        Context $context,
        Value $walkFn,
        Value $outValues,
        Value $outKeys,
        Variable $elem,
        Variable $keyVar,
        BasicBlock $continueBb
    ): void {
        $valPtr = JitValueBox::pointer($context, $elem->value);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valueMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $nest = BasicBlockHelper::append($context, 'rii_walk_nest');
        $leaf = BasicBlockHelper::append($context, 'rii_walk_leaf');
        $context->builder->branchIf($isHt, $nest, $leaf);

        $context->builder->positionAtEnd($nest);
        $child = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->call($walkFn, $child, $outValues, $outKeys);
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($leaf);
        $valuesVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $outValues);
        $valuesVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $valuesVar, $elem, null);
        $keysVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $outKeys);
        $keysVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $keysVar, $keyVar, null);
        $context->builder->branch($continueBb);
    }
}
