<?php

declare(strict_types=1);

/**
 * LLVM helpers for extract() / compact() (caller scope import/export).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block as CompilerBlock;
use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ScopeBuiltinHelper
{
    private static int $blockSeq = 0;

    /**
     * @return array<string, Variable>
     */
    public static function namedVariablesInScope(Context $context): array
    {
        $map = [];
        foreach ($context->scope->variables as $op) {
            $name = OperandName::resolve($op);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            $var = $context->scope->variables[$op];
            if (Variable::TYPE_HASHTABLE === $var->type || 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        return $map;
    }

    public static function findVariableByName(Context $context, string $name): ?Variable
    {
        return self::namedVariablesInScope($context)[$name] ?? null;
    }

    /**
     * @return Value
     * int64 import count
     */
    public static function extract(Context $context, Variable $array, ?Variable $flagsArg = null): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_HASHTABLE !== $array->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $flags = self::resolveFlags($context, $flagsArg);
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $i64 = $context->getTypeFromString('int64');
        $countSlot = $context->builder->alloca($i64, 1, 'extract_count');
        $context->builder->store($i64->constInt(0, false), $countSlot);

        self::walkStringKeyNodes($context, $ht, $named, $flags, $countSlot);

        return $context->builder->load($countSlot);
    }

    /**
     * parse_str() one-arg: import every matching parsed key into named locals (issue #3708).
     */
    public static function importHashtableIntoScope(Context $context, Value $ht): void
    {
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        self::walkStringKeyNodes($context, $ht, $named, $flags, null);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function walkStringKeyNodes(
        Context $context,
        Value $ht,
        array $named,
        Value $flags,
        ?Value $countSlot
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'scope_import_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $strHead = BasicBlockHelper::append($context, 'scope_import_str_head');
        $strBody = BasicBlockHelper::append($context, 'scope_import_str_body');
        $strNext = BasicBlockHelper::append($context, 'scope_import_str_next');
        $strDone = BasicBlockHelper::append($context, 'scope_import_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        self::importKeyIntoScope($context, $keyStr, $valEntry, $named, $flags, $countSlot);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function importKeyIntoScope(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        array $named,
        Value $flags,
        ?Value $countSlot
    ): void {
        if ([] === $named) {
            return;
        }

        $names = array_keys($named);
        $n = \count($names);
        $tag = 'e'.(string) ++self::$blockSeq;
        $done = BasicBlockHelper::append($context, 'extract_import_done_'.$tag);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'extract_import_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $name) {
            $dest = $named[$name];
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $nameGlobal = $context->builder->load($context->constantStringFromString($name));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                self::stringDataPtr($context, $keyStr),
                self::stringDataPtr($context, $nameGlobal)
            );
            $i32 = $context->getTypeFromString('int32');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $onMatch = BasicBlockHelper::append($context, 'extract_on_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $done;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            self::maybeAssignExtract($context, $dest, $valEntry, $flags, $countSlot, $done);
        }

        $context->builder->positionAtEnd($done);
    }

    private static function maybeAssignExtract(
        Context $context,
        Variable $dest,
        Value $valEntry,
        Value $flags,
        ?Value $countSlot,
        \PHPLLVM\BasicBlock $merge
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $skipMask = $i64->constInt(VmScope::EXTR_SKIP, false);
        $tag = 'a'.(string) ++self::$blockSeq;
        $assignBlock = BasicBlockHelper::append($context, 'extract_assign_'.$tag);
        $skipDone = BasicBlockHelper::append($context, 'extract_skip_done_'.$tag);

        $flagsAndSkip = $context->builder->and($flags, $skipMask);
        $skipEnabled = $context->builder->icmp(
            Builder::INT_EQ,
            $flagsAndSkip,
            $skipMask
        );
        $skipBlock = BasicBlockHelper::append($context, 'extract_skip_'.$tag);
        $context->builder->branchIf($skipEnabled, $skipBlock, $assignBlock);

        $context->builder->positionAtEnd($skipBlock);
        $isSet = IssetHelper::compile($context, $dest, null);
        $context->builder->branchIf($isSet, $skipDone, $assignBlock);

        $context->builder->positionAtEnd($skipDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($assignBlock);
        self::assignFromValueEntry($context, $dest, $valEntry);
        if (null !== $countSlot) {
            $prev = $context->builder->load($countSlot);
            $context->builder->store($context->builder->addNoSignedWrap($prev, $one), $countSlot);
        }
        $context->builder->branch($merge);
    }

    public static function compact(Context $context, Variable ...$nameArgs): Value
    {
        if ([] === $nameArgs) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
        }

        $result = HashTableHelper::alloc($context);
        foreach ($nameArgs as $arg) {
            self::addCompactArgument($context, $result, $arg);
        }

        return $result;
    }

    private static function addCompactArgument(Context $context, Value $result, Variable $arg): void
    {
        if (null !== $arg->compileTimeString) {
            self::addCompactByName($context, $result, $arg->compileTimeString);

            return;
        }

        self::applyRuntimeCompactArgument($context, $result, $arg);
    }

    private static function addCompactByName(Context $context, Value $result, string $name): void
    {
        $source = self::findVariableByName($context, $name);
        if (null === $source) {
            return;
        }
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        self::storeVariableAtStringKey($context, $result, $keyStr, $source);
    }

    private static function applyRuntimeCompactArgument(Context $context, Value $result, Variable $arg): void
    {
        $names = self::tryCompileTimeCompactNames($arg);
        if (null !== $names) {
            foreach ($names as $name) {
                self::addCompactByName($context, $result, $name);
            }

            return;
        }

        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return;
        }

        $scopeNames = array_keys($named);
        $bindingCount = \count($scopeNames);
        $charPtr = $context->getTypeFromString('char*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        foreach ($named as $scopeVar) {
            if (null === $scopeVar->valueBoxAliasPtr) {
                JitValueBox::promoteNativeLvalueToValueBox($context, $scopeVar);
            }
        }

        $namesArrayTy = $charPtr->arrayType($bindingCount);
        $slotsArrayTy = $valuePtrTy->arrayType($bindingCount);
        $namesAlloc = BasicBlockHelper::entryAlloca($context, $namesArrayTy);
        $slotsAlloc = BasicBlockHelper::entryAlloca($context, $slotsArrayTy);
        $zero = $i64->constInt(0, false);

        foreach ($scopeNames as $i => $scopeName) {
            $idx = $i64->constInt($i, false);
            $nameSlot = $context->builder->gep($namesAlloc, $zero, $idx);
            $valueSlot = $context->builder->gep($slotsAlloc, $zero, $idx);
            $nameGlobal = $context->builder->load($context->constantStringFromString($scopeName));
            $context->builder->store(
                self::stringDataPtr($context, $nameGlobal),
                $nameSlot
            );
            $context->builder->store(
                JitValueBox::valuePtrFromVariable($context, $named[$scopeName]),
                $valueSlot
            );
        }

        $argPtr = self::compactArgValuePtr($context, $arg);
        $namesPtr = $context->builder->pointerCast($namesAlloc, $charPtr->pointerType(0));
        $slotsPtr = $context->builder->pointerCast($slotsAlloc, $valuePtrTy->pointerType(0));
        $context->builder->call(
            $context->lookupFunction('__compiler_compact_apply_arg'),
            $result,
            $argPtr,
            $namesPtr,
            $slotsPtr,
            $i64->constInt($bindingCount, false)
        );
    }

    /**
     * @return list<string>|null
     */
    private static function tryCompileTimeCompactNames(Variable $arg): ?array
    {
        if (null !== $arg->compileTimeString) {
            return [$arg->compileTimeString];
        }

        return null;
    }

    private static function compactArgValuePtr(Context $context, Variable $arg): Value
    {
        if (JitValueBox::isValueOperand($arg)) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }

        if (ArrayBuiltinHelper::isNativeArray($arg->type) || Variable::TYPE_HASHTABLE === $arg->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $ht = ArrayBuiltinHelper::loadHashTable($context, $arg);
            $context->refcount->addref($ht);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $ptr,
                $ht
            );

            return $ptr;
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $arg);

        return $ptr;
    }

    private static function resolveFlags(Context $context, ?Variable $flagsArg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $flagsArg) {
            return $i64->constInt(VmScope::EXTR_SKIP, false);
        }

        return JitLongArg::lower($context, $flagsArg, 'extract() flags');
    }

    private static function storeVariableAtStringKey(
        Context $context,
        Value $ht,
        Value $keyStr,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_STRING:
                HashTableHelper::setAtStringKey(
                    $context,
                    $ht,
                    $keyStr,
                    $element
                );

                return;
            case Variable::TYPE_NATIVE_LONG:
            case Variable::TYPE_NATIVE_BOOL:
                HashTableHelper::setAtStringKey(
                    $context,
                    $ht,
                    $keyStr,
                    $element
                );

                return;
            case Variable::TYPE_VALUE:
                $valuePtr = $context->helper->loadValue($element);
                $valueMap = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $valueMap['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $tag = 'c'.(string) ++self::$blockSeq;
                $isString = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_STRING, false)
                );
                $stringBlock = BasicBlockHelper::append($context, 'compact_val_string_'.$tag);
                $longBlock = BasicBlockHelper::append($context, 'compact_val_long_'.$tag);
                $done = BasicBlockHelper::append($context, 'compact_val_done_'.$tag);
                $afterString = BasicBlockHelper::append($context, 'compact_val_after_string_'.$tag);
                $context->builder->branchIf($isString, $stringBlock, $afterString);

                $context->builder->positionAtEnd($stringBlock);
                $str = $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $valuePtr
                );
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyString'),
                    $ht,
                    $keyStr,
                    $owned
                );
                $context->builder->branch($done);

                $context->builder->positionAtEnd($afterString);
                $isLong = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
                );
                $context->builder->branchIf($isLong, $longBlock, $done);

                $context->builder->positionAtEnd($longBlock);
                $longVal = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyStr,
                    $longVal
                );
                $context->builder->branch($done);

                $context->builder->positionAtEnd($done);

                return;
            default:
                throw new \LogicException(
                    'compact() variable type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    private static function assignFromValueEntry(Context $context, Variable $dest, Value $entryPtr): void
    {
        if (Variable::TYPE_VALUE === $dest->type) {
            JitValueBox::copyFromPointer($context, $dest->value, $entryPtr);

            return;
        }
        if (Variable::TYPE_STRING === $dest->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $entryPtr
            );
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $dest->free();
            $context->builder->store($owned, $dest->value);
            $dest->addref();

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type) {
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $entryPtr
            );
            $dest->free();
            $context->builder->store($longVal, $dest->value);
            $dest->addref();

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            JitValueBox::writeBool(
                $context,
                $dest->value,
                $context->builder->truncOrBitCast(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr),
                    $context->getTypeFromString('int1')
                )
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            $doubleVal = $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $entryPtr
            );
            $dest->free();
            $context->builder->store($doubleVal, $dest->value);
            $dest->addref();

            return;
        }

        throw new \LogicException(
            'extract() target variable type not supported for JIT: '
            .Variable::getStringType($dest->type)
        );
    }

    /**
     * @return array<string, Variable>
     */
    public static function namedVariablesForDefinedVars(Context $context): array
    {
        $map = [];
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        if (!$block instanceof CompilerBlock) {
            return $map;
        }
        foreach ($block->eachNamedScopeSlot() as [$name, $_slot]) {
            if ('this' === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            $var = VarFetchHelper::bindingByName($context, $block, $name);
            if (null === $var) {
                continue;
            }
            if (0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        return $map;
    }

    /**
     * get_defined_vars() — export all named locals in the current scope (issue #3135).
     */
    public static function getDefinedVars(Context $context): Value
    {
        $named = self::namedVariablesForDefinedVars($context);
        $ht = HashTableHelper::alloc($context);
        if ([] === $named) {
            return self::wrapHashTableValue($context, $ht);
        }

        $tag = 'gdv'.(string) ++self::$blockSeq;
        $done = BasicBlockHelper::append($context, 'gdv_done_'.$tag);
        $first = $context->builder->getInsertBlock();
        $blocks = [$first];
        $names = array_keys($named);
        $n = \count($names);
        for ($i = 1; $i < $n; ++$i) {
            $blocks[$i] = BasicBlockHelper::append($context, 'gdv_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $name) {
            $dest = $named[$name];
            $context->builder->positionAtEnd($blocks[$i]);
            $isSet = IssetHelper::compile($context, $dest, null);
            $storeBlock = BasicBlockHelper::append($context, 'gdv_store_'.$tag.'_'.$i);
            $nextBlock = ($i < $n - 1) ? $blocks[$i + 1] : $done;
            $context->builder->branchIf($isSet, $storeBlock, $nextBlock);

            $context->builder->positionAtEnd($storeBlock);
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeDefinedVarAtStringKey($context, $ht, $keyStr, $dest);
            $context->builder->branch($nextBlock);
        }

        $context->builder->positionAtEnd($done);

        return self::wrapHashTableValue($context, $ht);
    }

    private static function storeDefinedVarAtStringKey(
        Context $context,
        Value $ht,
        Value $keyStr,
        Variable $element
    ): void {
        if (Variable::TYPE_VALUE !== $element->type) {
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $element);

            return;
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
        $typeByte = $context->builder->load(
            $context->builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'dv'.(string) ++self::$blockSeq;
        $stringBlock = BasicBlockHelper::append($context, 'gdv_val_string_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'gdv_val_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'gdv_val_bool_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'gdv_val_ht_'.$tag);
        $done = BasicBlockHelper::append($context, 'gdv_val_done_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'gdv_val_after_string_'.$tag);
        $afterLong = BasicBlockHelper::append($context, 'gdv_val_after_long_'.$tag);
        $afterBool = BasicBlockHelper::append($context, 'gdv_val_after_bool_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $htBlock, $done);

        $context->builder->positionAtEnd($htBlock);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyStr,
            $childHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function wrapHashTableValue(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function stringDataPtr(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($str, $map['value']);
    }
}
