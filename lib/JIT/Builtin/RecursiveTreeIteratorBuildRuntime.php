<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\strval;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableValuesLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * RecursiveTreeIterator prefix tree — pure LLVM for thin AOT (#27584).
 *
 * NestedJIT HashTable helpers segfault under thin standalone AOT (peer #26825).
 * php-src: ext/spl/spl_iterators.c — RecursiveTreeIterator default prefixes
 */
final class RecursiveTreeIteratorBuildRuntime
{
    public const ABI = '__compiler_recursive_tree_iterator_build';

    private const WALK_ABI = '__compiler_rti_walk';

    private const BRIDGE_ENTRY = 'recursive_tree_iterator_build_bridge_entry';

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
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitWalk($context);
        self::emitBuild($context, $probe);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitBuild(Context $context, ?LlvmFunction $probe): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $htPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $out = HashTableHelper::alloc($context);
        $emptyPrefix = $context->builder->load($context->constantStringFromString(''));
        $context->builder->call(
            $context->lookupFunction(self::WALK_ABI),
            $src,
            $out,
            $emptyPrefix
        );
        $context->builder->returnValue($out);
        $context->registerFunction(self::ABI, $fn);
    }

    private static function emitWalk(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::WALK_ABI);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::WALK_ABI, $existing);

            return;
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false, $htPtr, $htPtr, $strPtr);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::WALK_ABI, $ft);
        // Register before body so the recursive self-call can resolve (#27584).
        $context->registerFunction(self::WALK_ABI, $fn);
        $entry = $fn->appendBasicBlock('rti_walk_entry');
        $context->builder->positionAtEnd($entry);

        $src = $fn->getParam(0);
        $out = $fn->getParam(1);
        $ancestor = $fn->getParam(2);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $values = HashTableValuesLlvm::values($context, $src);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $values
        );
        $idxSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = $fn->appendBasicBlock('rti_walk_head');
        $body = $fn->appendBasicBlock('rti_walk_body');
        $done = $fn->appendBasicBlock('rti_walk_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $val = HashTableReadLlvm::readIndexedToValueBox($context, $values, $idx);
        $isLast = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->addNoSignedWrap($idx, $one),
            $num
        );
        $branchMark = $context->builder->select(
            $isLast,
            $context->builder->load($context->constantStringFromString('\\-')),
            $context->builder->load($context->constantStringFromString('|-'))
        );
        $prefix = self::concatStr($context, $ancestor, $branchMark);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $val);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($typeByte, $i8->constInt(0x7f, false)),
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $arrBb = $fn->appendBasicBlock('rti_walk_arr');
        $leafBb = $fn->appendBasicBlock('rti_walk_leaf');
        $advBb = $fn->appendBasicBlock('rti_walk_adv');
        $context->builder->branchIf($isArray, $arrBb, $leafBb);

        $context->builder->positionAtEnd($arrBb);
        $lineArr = self::concatStr(
            $context,
            $prefix,
            $context->builder->load($context->constantStringFromString('Array'))
        );
        self::appendString($context, $out, $lineArr);
        $childAncestor = self::concatStr(
            $context,
            $ancestor,
            $context->builder->select(
                $isLast,
                $context->builder->load($context->constantStringFromString('  ')),
                $context->builder->load($context->constantStringFromString('| '))
            )
        );
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->call(
            $context->lookupFunction(self::WALK_ABI),
            $childHt,
            $out,
            $childAncestor
        );
        $context->builder->branch($advBb);

        $context->builder->positionAtEnd($leafBb);
        $leafStr = (new strval())->valueToString($context, $valPtr);
        $lineLeaf = self::concatStr($context, $prefix, $leafStr);
        self::appendString($context, $out, $lineLeaf);
        $context->builder->branch($advBb);

        $context->builder->positionAtEnd($advBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function concatStr(Context $context, Value $left, Value $right): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftSize = $context->builder->load($context->builder->structGep($left, $map['length']));
        $rightSize = $context->builder->load($context->builder->structGep($right, $map['length']));
        $size = $context->builder->addNoUnsignedWrap($leftSize, $rightSize);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $size);
        $context->intrinsic->builder = $context->builder;
        $dest = $context->builder->structGep($result, $map['value']);
        $leftChar = $context->builder->structGep($left, $map['value']);
        $context->intrinsic->memcpy($dest, $leftChar, $leftSize, false);
        $dest2 = $context->builder->gep($dest, $leftSize);
        $rightChar = $context->builder->structGep($right, $map['value']);
        $context->intrinsic->memcpy($dest2, $rightChar, $rightSize, false);

        return $result;
    }

    private static function appendString(Context $context, Value $out, Value $str): void
    {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $out
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );
        $boxed = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        HashTableHelper::setAtIndex($context, $out, $num, $boxed);
    }
}
