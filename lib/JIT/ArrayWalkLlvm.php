<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pure LLVM array_walk / array_walk_recursive for thin standalone AOT (#27632 / #33713 / #33728).
 *
 * - Closures → NestedClosureInvoke (#33713)
 * - String stdlib / user-fn names → Call / Internal on live HT slots (#33728 / peer #33721)
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayWalkJitHelper} cannot resolve user-function
 * string names (VmInternalCall is BuiltinRegistry-only) and previously passed cstr into a
 * `__string__*` ABI. Emit packed + string-key walks with live HT value slots so by-ref &$value
 * mutates in place (php_array_walk).
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

    /** Compile-time string stdlib builtin (intval, …) (#33728). */
    public static function walkWithBuiltin(
        Context $context,
        Value $ht,
        string $builtinName,
        bool $recursive
    ): void {
        $handler = \PHPCompiler\ext\standard\VmInternalCall::resolveStringCallback($builtinName);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_walk_builtin_cont');
        $fn = self::ensureStringWalkFunction(
            $context,
            $recursive,
            'builtin_'.preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($builtinName)),
            static function (Context $ctx, Variable $valueVar, Variable $keyVar) use ($handler): void {
                $handler->call($ctx, $valueVar, $keyVar);
            }
        );
        $context->builder->call($fn, $ht);
    }

    /** Compile-time string user-function name in this TU (#33728 / peer #33721). */
    public static function walkWithUserFunction(
        Context $context,
        Value $ht,
        string $functionName,
        bool $recursive
    ): void {
        $proxy = $context->resolveFunctionProxy(strtolower(ltrim($functionName, '\\')));
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_walk_user_cont');
        $fn = self::ensureStringWalkFunction(
            $context,
            $recursive,
            'user_'.preg_replace('/[^A-Za-z0-9_]/', '_', strtolower(ltrim($functionName, '\\'))),
            static function (Context $ctx, Variable $valueVar, Variable $keyVar) use ($proxy): void {
                $proxy->call($ctx, $valueVar, $keyVar);
            }
        );
        $context->builder->call($fn, $ht);
    }

    /**
     * @param callable(Context, Variable, Variable): void $invoke value+key on live HT slot
     */
    private static function ensureStringWalkFunction(
        Context $context,
        bool $recursive,
        string $suffix,
        callable $invoke
    ): LlvmFunction {
        $name = ($recursive ? '__array_walk_recursive__str_' : '__array_walk__str_').$suffix;
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return $probe;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $context->context->voidType(),
                    false,
                    $htPtr
                )
            );
        }
        $context->registerFunction($name, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $recursive, $invoke): void {
            $entry = $fn->appendBasicBlock($recursive ? 'awr_str_entry' : 'aw_str_entry');
            $context->builder->positionAtEnd($entry);
            self::emitStringCallbackWalkBody($context, $fn, $recursive, $invoke);
        });

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }

        return $fn;
    }

    /**
     * @param callable(Context, Variable, Variable): void $invoke
     */
    private static function emitStringCallbackWalkBody(
        Context $context,
        LlvmFunction $fn,
        bool $recursive,
        callable $invoke
    ): void {
        $ht = $fn->getParam(0);
        $prefix = $recursive ? 'awr_str' : 'aw_str';
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

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
        $isUndef = HashTableHelper::packedIndexIsUndefined($context, $ht, $idx);
        $context->builder->branchIf($isUndef, $packedAdvance, $packedBody);

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
            $context->builder->call($fn, $child);
            $context->builder->branch($packedAdvance);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeCallable($context, $invoke, $entry, $idx, $i64, $prefix.'_packed');
            $context->builder->branch($packedAdvance);
        } else {
            self::invokeCallable($context, $invoke, $entry, $idx, $i64, $prefix.'_packed');
            $context->builder->branch($packedAdvance);
        }

        $context->builder->positionAtEnd($packedAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedDone);
        self::emitStringKeyWalkCallable($context, $fn, $ht, $recursive, $prefix, $invoke);
        $context->builder->returnVoid();
    }

    /**
     * @param callable(Context, Variable, Variable): void $invoke
     */
    private static function emitStringKeyWalkCallable(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        bool $recursive,
        string $prefix,
        callable $invoke
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
            $context->builder->call($fn, $child);
            $context->builder->branch($strNext);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeCallableWithStringKey($context, $invoke, $valEntry, $keyStr, $prefix.'_str');
            $context->builder->branch($strNext);
        } else {
            self::invokeCallableWithStringKey($context, $invoke, $valEntry, $keyStr, $prefix.'_str');
            $context->builder->branch($strNext);
        }

        $context->builder->positionAtEnd($strNext);
        $node = $context->builder->load($walkSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * @param callable(Context, Variable, Variable): void $invoke
     */
    private static function invokeCallable(
        Context $context,
        callable $invoke,
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
        $invoke($context, $valueVar, $keyVar);
    }

    /**
     * @param callable(Context, Variable, Variable): void $invoke
     */
    private static function invokeCallableWithStringKey(
        Context $context,
        callable $invoke,
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
        $invoke($context, $valueVar, $keyVar);
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

        // Pin loweringLlvmFunction so BasicBlockHelper::append / JitValueBox helpers stay
        // inside this ABI fn — otherwise packed walk BBs land in the outer user fn and
        // module verify fails (cross-function br / arg) (#33713 / re-#26969 / peer #33706).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $recursive): void {
            $entry = $fn->appendBasicBlock($recursive ? 'awr_llvm_entry' : 'aw_llvm_entry');
            $context->builder->positionAtEnd($entry);
            self::emitWalkBody($context, $fn, $recursive);
        });

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
        // Skip TYPE_UNDEFINED holes only — TYPE_NULL is a real value (#33710 / #33705).
        $isUndef = HashTableHelper::packedIndexIsUndefined($context, $ht, $idx);
        $context->builder->branchIf($isUndef, $packedAdvance, $packedBody);

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
