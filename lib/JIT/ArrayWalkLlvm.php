<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pure LLVM array_walk / array_walk_recursive for thin standalone AOT (#27632 / #33713 / #33728).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayWalkJitHelper} segfaults under thin AOT
 * (same class as ArrayMapJitHelper #24156 / ArrayReduceLlvm). Emit packed + string-key walks
 * with live HT value slots so by-ref &$value mutates in place (php_array_walk).
 * Body emit must {@see BasicBlockHelper::scopeLoweringToFunction} so packed BBs stay in the
 * ABI fn when the outer user fn owns {@see Context::$loweringLlvmFunction} (#33713 / peer #33706).
 *
 * String callbacks (#33728): do not NestedJIT `__array_walk__builtin` with a C-string global —
 * the ABI is `__string__*`, and NestedJIT of the closure helpers alongside a string-only
 * module hits NestedClosureInvoke with zero candidates (#33721). Lower user-fn / builtin
 * names here via {@see Call}.
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
    public static function walkWithBuiltin(Context $context, Value $ht, string $builtinName): void
    {
        self::walkWithNamedCall(
            $context,
            $ht,
            VmInternalCall::resolveStringCallback($builtinName),
            false,
            'aw_bi_'.self::abiToken($builtinName)
        );
    }

    public static function walkRecursiveWithBuiltin(Context $context, Value $ht, string $builtinName): void
    {
        self::walkWithNamedCall(
            $context,
            $ht,
            VmInternalCall::resolveStringCallback($builtinName),
            true,
            'awr_bi_'.self::abiToken($builtinName)
        );
    }

    /** Compile-time string user-function name in this TU (#33728). */
    public static function walkWithUserFunction(Context $context, Value $ht, string $functionName): void
    {
        $proxy = $context->resolveFunctionProxy(strtolower(ltrim($functionName, '\\')));
        self::walkWithNamedCall(
            $context,
            $ht,
            $proxy,
            false,
            'aw_uf_'.self::abiToken($functionName)
        );
    }

    public static function walkRecursiveWithUserFunction(Context $context, Value $ht, string $functionName): void
    {
        $proxy = $context->resolveFunctionProxy(strtolower(ltrim($functionName, '\\')));
        self::walkWithNamedCall(
            $context,
            $ht,
            $proxy,
            true,
            'awr_uf_'.self::abiToken($functionName)
        );
    }

    private static function abiToken(string $name): string
    {
        return substr(hash('sha256', strtolower(ltrim($name, '\\'))), 0, 16);
    }

    private static function walkWithNamedCall(
        Context $context,
        Value $ht,
        Call $callback,
        bool $recursive,
        string $abiName
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, $abiName.'_cont');
        $fn = self::ensureNamedWalkFunction($context, $recursive, $abiName, $callback);
        $context->builder->call($fn, $ht);
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

    private static function ensureNamedWalkFunction(
        Context $context,
        bool $recursive,
        string $name,
        Call $callback
    ): LlvmFunction {
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

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $recursive, $callback): void {
            $entry = $fn->appendBasicBlock($recursive ? 'awr_named_entry' : 'aw_named_entry');
            $context->builder->positionAtEnd($entry);
            self::emitWalkBody($context, $fn, $recursive, $callback);
        });

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }

        return $fn;
    }

    private static function emitWalkBody(
        Context $context,
        LlvmFunction $fn,
        bool $recursive,
        ?Call $named = null
    ): void {
        $ht = $fn->getParam(0);
        $closurePtr = null === $named ? $fn->getParam(1) : null;
        $prefix = $recursive
            ? (null === $named ? 'awr_llvm' : 'awr_named')
            : (null === $named ? 'aw_llvm' : 'aw_named');
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $closureVar = null;
        if (null !== $closurePtr) {
            $closureVar = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $closurePtr
            );
        }

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
            self::callWalkAbi($context, $fn, $child, $closurePtr);
            $context->builder->branch($packedAdvance);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeLeaf(
                $context,
                $named,
                $closureVar,
                $entry,
                $idx,
                $i64,
                $prefix.'_packed'
            );
            $context->builder->branch($packedAdvance);
        } else {
            self::invokeLeaf(
                $context,
                $named,
                $closureVar,
                $entry,
                $idx,
                $i64,
                $prefix.'_packed'
            );
            $context->builder->branch($packedAdvance);
        }

        $context->builder->positionAtEnd($packedAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedDone);
        self::emitStringKeyWalk($context, $fn, $ht, $closurePtr, $closureVar, $named, $recursive, $prefix);
        $context->builder->returnVoid();
    }

    private static function emitStringKeyWalk(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        ?Value $closurePtr,
        ?Variable $closureVar,
        ?Call $named,
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
            self::callWalkAbi($context, $fn, $child, $closurePtr);
            $context->builder->branch($strNext);

            $context->builder->positionAtEnd($leafBlock);
            self::invokeLeafWithStringKey(
                $context,
                $named,
                $closureVar,
                $valEntry,
                $keyStr,
                $prefix.'_str'
            );
            $context->builder->branch($strNext);
        } else {
            self::invokeLeafWithStringKey(
                $context,
                $named,
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

    private static function callWalkAbi(
        Context $context,
        LlvmFunction $fn,
        Value $childHt,
        ?Value $closurePtr
    ): void {
        if (null === $closurePtr) {
            $context->builder->call($fn, $childHt);

            return;
        }
        $context->builder->call($fn, $childHt, $closurePtr);
    }

    private static function invokeLeaf(
        Context $context,
        ?Call $named,
        ?Variable $closureVar,
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
        if (null !== $named) {
            $named->call($context, $valueVar, $keyVar);

            return;
        }
        if (null === $closureVar) {
            throw new \LogicException('ArrayWalkLlvm leaf invoke needs Call or Closure (#33728)');
        }
        (new NestedClosureInvoke())->call($context, $closureVar, $valueVar, $keyVar);
    }

    private static function invokeLeafWithStringKey(
        Context $context,
        ?Call $named,
        ?Variable $closureVar,
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
        if (null !== $named) {
            $named->call($context, $valueVar, $keyVar);

            return;
        }
        if (null === $closureVar) {
            throw new \LogicException('ArrayWalkLlvm string-key invoke needs Call or Closure (#33728)');
        }
        (new NestedClosureInvoke())->call($context, $closureVar, $valueVar, $keyVar);
    }
}
