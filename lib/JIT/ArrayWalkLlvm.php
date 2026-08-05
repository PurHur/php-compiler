<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pure LLVM array_walk / array_walk_recursive (Closure) for thin standalone AOT (#27632).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayWalkJitHelper} segfaults under thin AOT
 * (same class as ArrayMapJitHelper #24156 / ArrayReduceLlvm). Emit packed + string-key walks
 * with live HT value slots so by-ref &$value mutates in place (php_array_walk).
 *
 * php-src: ext/standard/array.c — php_array_walk / php_array_walk_recursive
 */
final class ArrayWalkLlvm
{
    private const ABI_FLAT = '__array_walk__closure_llvm';

    private const ABI_RECURSIVE = '__array_walk_recursive__closure_llvm';

    public static function walkWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_walk_llvm_cont');
        $fn = self::ensureWalkFunction($context, false);
        $context->builder->call(
            $fn,
            $ht,
            JitValueBox::valuePtrFromVariable($context, $closure)
        );
    }

    public static function walkRecursiveWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_walk_rec_llvm_cont');
        $fn = self::ensureWalkFunction($context, true);
        $context->builder->call(
            $fn,
            $ht,
            JitValueBox::valuePtrFromVariable($context, $closure)
        );
    }

    private static function ensureWalkFunction(Context $context, bool $recursive): LlvmFunction
    {
        $name = $recursive ? self::ABI_RECURSIVE : self::ABI_FLAT;
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return $probe;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $context->context->voidType(),
                    false,
                    $htPtr,
                    $valuePtr
                )
            );
        }
        $context->registerFunction($name, $fn);
        $context->activeFunction = $name;

        $entry = $fn->appendBasicBlock($recursive ? 'awr_llvm_entry' : 'aw_llvm_entry');
        $context->builder->positionAtEnd($entry);
        self::emitWalkBody($context, $fn, $recursive);

        $context->activeFunction = $savedActive;
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }

        return $fn;
    }

    private static function emitWalkBody(Context $context, LlvmFunction $fn, bool $recursive): void
    {
        $ht = $fn->getParam(0);
        $closurePtr = $fn->getParam(1);
        $prefix = $recursive ? 'awr_llvm' : 'aw_llvm';
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $closureVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $closurePtr
        );

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, $prefix.'_packed_head');
        $packedCheck = BasicBlockHelper::append($context, $prefix.'_packed_check');
        $packedBody = BasicBlockHelper::append($context, $prefix.'_packed_body');
        $packedAdvance = BasicBlockHelper::append($context, $prefix.'_packed_adv');
        $packedDone = BasicBlockHelper::append($context, $prefix.'_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedCheck);

        $context->builder->positionAtEnd($packedCheck);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedBody, $packedAdvance);

        $context->builder->positionAtEnd($packedBody);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        if ($recursive) {
            $isHt = self::isHashtableTypeByte($context, $typeByte);
            $recurseBlock = BasicBlockHelper::append($context, $prefix.'_packed_recurse');
            $leafBlock = BasicBlockHelper::append($context, $prefix.'_packed_leaf');
            $context->builder->branchIf($isHt, $recurseBlock, $leafBlock);

            $context->builder->positionAtEnd($recurseBlock);
            $child = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $entry
            );
            $context->builder->call($fn, $child, $closurePtr);
            $context->builder->branch($packedAdvance);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeClosure($context, $closureVar, $entry, $idx, $i64, $prefix.'_packed');
            $context->builder->branch($packedAdvance);
        } else {
            self::invokeClosure($context, $closureVar, $entry, $idx, $i64, $prefix.'_packed');
            $context->builder->branch($packedAdvance);
        }

        $context->builder->positionAtEnd($packedAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedDone);
        self::emitStringKeyWalk($context, $fn, $ht, $closurePtr, $closureVar, $recursive, $prefix);
        $context->builder->returnVoid();
    }

    private static function emitStringKeyWalk(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $closurePtr,
        Variable $closureVar,
        bool $recursive,
        string $prefix
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $walkSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $strHead = BasicBlockHelper::append($context, $prefix.'_str_head');
        $strBody = BasicBlockHelper::append($context, $prefix.'_str_body');
        $strNext = BasicBlockHelper::append($context, $prefix.'_str_next');
        $strDone = BasicBlockHelper::append($context, $prefix.'_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        if ($recursive) {
            $isHt = self::isHashtableTypeByte($context, $typeByte);
            $recurseBlock = BasicBlockHelper::append($context, $prefix.'_str_recurse');
            $leafBlock = BasicBlockHelper::append($context, $prefix.'_str_leaf');
            $context->builder->branchIf($isHt, $recurseBlock, $leafBlock);

            $context->builder->positionAtEnd($recurseBlock);
            $child = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valEntry
            );
            $context->builder->call($fn, $child, $closurePtr);
            $context->builder->branch($strNext);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeClosureWithStringKey(
                $context,
                $closureVar,
                $valEntry,
                $keyStr,
                $prefix.'_str'
            );
            $context->builder->branch($strNext);
        } else {
            self::invokeClosureWithStringKey(
                $context,
                $closureVar,
                $valEntry,
                $keyStr,
                $prefix.'_str'
            );
            $context->builder->branch($strNext);
        }

        $context->builder->positionAtEnd($strNext);
        $node = $context->builder->load($walkSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /** Mask IS_REFCOUNTED — peer HashTableReplaceRecursiveLlvm (#26977). */
    private static function isHashtableTypeByte(Context $context, Value $typeByte): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
    }

    private static function invokeClosure(
        Context $context,
        Variable $closureVar,
        Value $entry,
        Value $idx,
        $i64,
        string $tag
    ): void {
        $valueVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $entry
        );
        $keySlot = JitValueBox::alloc($context);
        $keyLong = $context->builder->zExt($idx, $i64);
        JitValueBox::writeLong($context, $keySlot, $keyLong);
        $keyVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $keySlot
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, $tag.'_invoke');
        (new NestedClosureInvoke())->call($context, $closureVar, $valueVar, $keyVar);
    }

    private static function invokeClosureWithStringKey(
        Context $context,
        Variable $closureVar,
        Value $entry,
        Value $keyStr,
        string $tag
    ): void {
        $valueVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $entry
        );
        $keySlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $keySlot),
            $keyStr
        );
        $keyVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $keySlot
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, $tag.'_invoke');
        (new NestedClosureInvoke())->call($context, $closureVar, $valueVar, $keyVar);
    }
}
