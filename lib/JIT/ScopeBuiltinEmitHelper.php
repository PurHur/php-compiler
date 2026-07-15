<?php

declare(strict_types=1);

/**
 * LLVM emission for extract() / compact() / get_defined_vars() (#10184, php-in-PHP).
 *
 * Orchestration: {@see ScopeBuiltinHelper}
 * php-src: ext/standard/basic_functions.c — php_extract, php_compact
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\JIT\Builtin\ScopeBuiltinRuntime;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ScopeBuiltinEmitHelper
{
    private static int $blockSeq = 0;

    public static function walkStringKeyNodes(
        Context $context,
        Value $ht,
        array $named,
        Value $flags,
        ?Value $countSlot,
        Value $prefixStr,
    ): void {
        HashTableReadLlvm::forEachStringKeyNode(
            $context,
            $ht,
            'scope_import',
            static function (
                Context $context,
                Value $keyStr,
                Value $valEntry
            ) use ($named, $flags, $countSlot, $prefixStr): void {
                self::importExtractKey($context, $keyStr, $valEntry, $named, $flags, $prefixStr, $countSlot);
            }
        );
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function importExtractKey(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        array $named,
        Value $flags,
        Value $prefixStr,
        ?Value $countSlot
    ): void {
        if ([] === $named) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ffMask = $i64->constInt(0xFF, false);
        $extractType = $context->builder->and($flags, $ffMask);

        $varExists = self::compileKeyVarExists($context, $keyStr, $named);
        $targetStr = ScopeBuiltinRuntime::resolveExtractTargetName(
            $context,
            $keyStr,
            $varExists,
            $extractType,
            $prefixStr
        );

        $tag = 'e'.(string) ++self::$blockSeq;
        $emptyDone = BasicBlockHelper::append($context, 'extract_target_empty_'.$tag);
        $nonEmpty = BasicBlockHelper::append($context, 'extract_target_nonempty_'.$tag);
        $firstChar = $context->builder->load(self::stringDataPtr($context, $targetStr));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $firstChar, $i8->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyDone, $nonEmpty);

        $merge = BasicBlockHelper::append($context, 'extract_import_done_'.$tag);
        $context->builder->positionAtEnd($nonEmpty);
        self::branchOnNamedVariableIndex(
            $context,
            ScopeBuiltinRuntime::matchNamedVariableIndex(
                $context,
                $targetStr,
                self::namedVariablesTable($named)
            ),
            $named,
            'extract_target_'.$tag,
            $emptyDone,
            static function (Context $context, Variable $dest, string $name) use ($valEntry, $flags, $countSlot, $merge): void {
                self::maybeAssignExtract($context, $dest, $valEntry, $flags, $countSlot, $merge);
            },
            $nonEmpty
        );

        $context->builder->positionAtEnd($emptyDone);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function compileKeyVarExists(
        Context $context,
        Value $keyStr,
        array $named
    ): Value {
        $names = array_keys($named);
        $n = \count($names);
        if (0 === $n) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        $index = ScopeBuiltinRuntime::matchNamedVariableIndex(
            $context,
            $keyStr,
            self::namedVariablesTable($named)
        );
        $i1 = $context->getTypeFromString('int1');
        $tag = 've'.(string) ++self::$blockSeq;
        $falseDone = BasicBlockHelper::append($context, 'extract_key_exists_false_'.$tag);
        $trueBlock = BasicBlockHelper::append($context, 'extract_key_exists_true_'.$tag);
        $phiBlock = BasicBlockHelper::append($context, 'extract_key_exists_phi_'.$tag);
        $entry = $context->builder->getInsertBlock();
        self::branchOnNamedVariableIndex(
            $context,
            $index,
            $named,
            'extract_key_exists_'.$tag,
            $falseDone,
            static function (Context $context, Variable $dest, string $name) use ($trueBlock, $falseDone): void {
                $isSet = IssetHelper::compile($context, $dest, null);
                $context->builder->branchIf($isSet, $trueBlock, $falseDone);
            },
            $entry
        );

        $context->builder->positionAtEnd($falseDone);
        $context->builder->branch($phiBlock);

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->branch($phiBlock);

        $context->builder->positionAtEnd($phiBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $falseDone);
        $phi->addIncoming($i1->constInt(1, false), $trueBlock);

        return $phi;
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

    public static function buildCompact(Context $context, Variable ...$nameArgs): Value
    {
        if ([] === $nameArgs) {
            throw new \ArgumentCountError('compact() expects at least 1 argument, 0 given');
        }

        $result = HashTableHelper::alloc($context);
        foreach ($nameArgs as $i => $arg) {
            self::addCompactArgument($context, $result, $arg, (int) $i + 1);
        }

        return $result;
    }

    private static function addCompactArgument(Context $context, Value $result, Variable $arg, int $argNum): void
    {
        if (null !== $arg->compileTimeString) {
            self::addCompactByName($context, $result, $arg->compileTimeString);

            return;
        }

        self::applyRuntimeCompactArgument($context, $result, $arg, $argNum);
    }

    private static function addCompactByName(Context $context, Value $result, string $name): void
    {
        $local = ScopeBuiltinHelper::findVariableByName($context, $name);
        if (null !== $local) {
            self::addCompactLocalByName($context, $result, $name, $local);

            return;
        }
        if (Superglobals::isSuperglobalName($name)) {
            $source = SuperglobalInit::load($context, $name);
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $source);

            return;
        }
        self::addCompactFromScriptGlobal($context, $result, $name);
    }

    /** CV may exist before first assign — runtime assigned flag like VM initializedSlots (#10164). */
    private static function addCompactLocalByName(
        Context $context,
        Value $result,
        string $name,
        Variable $local
    ): void {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        if (!$block instanceof \PHPCompiler\Block || null === $block->slotIndexForVariableName($name)) {
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $local);

            return;
        }

        $tag = 'cl'.(string) ++self::$blockSeq;
        $okBlock = BasicBlockHelper::append($context, 'compact_local_ok_'.$tag);
        $missBlock = BasicBlockHelper::append($context, 'compact_local_miss_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'compact_local_done_'.$tag);
        $isAssigned = ScopeVariableAssignedFlags::isAssignedCondition(
            $context,
            ScopeVariableAssignedFlags::flagKey($context, $name)
        );
        $context->builder->branchIf($isAssigned, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        self::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $local);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        self::emitCompactUndefinedVariableWarning($context, $name);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /** $GLOBALS-only symbols — runtime isset before import (#11743, php-src zif_compact). */
    private static function addCompactFromScriptGlobal(Context $context, Value $result, string $name): void
    {
        $global = GlobalsTableInit::ensureGlobal($context, $name);
        $keyVar = new Variable($context, Variable::TYPE_STRING);
        $keyVar->compileTimeString = $name;
        $isSet = GlobalsTableInit::offsetIsSet($context, $keyVar);

        $tag = 'cg'.(string) ++self::$blockSeq;
        $okBlock = BasicBlockHelper::append($context, 'compact_global_ok_'.$tag);
        $missBlock = BasicBlockHelper::append($context, 'compact_global_miss_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'compact_global_done_'.$tag);
        $context->builder->branchIf($isSet, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $keyStr = $context->builder->load($context->constantStringFromString($name));
        self::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $global);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        self::emitCompactUndefinedVariableWarning($context, $name);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function applyRuntimeCompactArgument(Context $context, Value $result, Variable $arg, int $argNum): void
    {
        $names = self::tryCompileTimeCompactNames($arg);
        if (null !== $names) {
            foreach ($names as $name) {
                self::addCompactByName($context, $result, $name);
            }

            return;
        }

        $named = ScopeBuiltinHelper::namedVariablesInScope($context);
        if ([] === $named) {
            return;
        }

        $argPtr = self::compactArgValuePtr($context, $arg);
        self::collectCompactValue($context, $result, $argPtr, $named, $argNum);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function collectCompactValue(
        Context $context,
        Value $result,
        Value $valuePtr,
        array $named,
        int $argNum
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'cc'.(string) ++self::$blockSeq;
        $stringBlock = BasicBlockHelper::append($context, 'compact_collect_string_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'compact_collect_ht_'.$tag);
        $invalidBlock = BasicBlockHelper::append($context, 'compact_collect_invalid_'.$tag);
        $done = BasicBlockHelper::append($context, 'compact_collect_done_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'compact_collect_after_string_'.$tag);

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
        self::compactApplyNameFromCstr($context, $result, self::stringDataPtr($context, $str), $named);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $htBlock, $invalidBlock);

        $context->builder->positionAtEnd($htBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $namesHt = HashTableHelper::alloc($context);
        $htResume = self::captureInsertBlock($context);
        ScopeBuiltinRuntime::collectCompactNamesFromHashtable(
            $context,
            $namesHt,
            $ht,
            $context->getTypeFromString('int64')->constInt($argNum, false)
        );
        self::importCompactNamesFromHashtable($context, $result, $namesHt, $named);
        self::restoreInsertBlock($context, $htResume);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($invalidBlock);
        ScopeBuiltinRuntime::emitCompactInvalidArgumentWarning($context, $argNum, $typeByte);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function importCompactNamesFromHashtable(
        Context $context,
        Value $result,
        Value $namesHt,
        array $named,
    ): void {
        HashTableReadLlvm::forEachStringKeyNode(
            $context,
            $namesHt,
            'compact_names',
            static function (Context $context, Value $keyStr, Value $_valEntry) use ($result, $named): void {
                $nameResume = self::captureInsertBlock($context);
                self::compactApplyNameFromCstr(
                    $context,
                    $result,
                    self::stringDataPtr($context, $keyStr),
                    $named
                );
                self::restoreInsertBlock($context, $nameResume);
            }
        );
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function compactApplyNameFromCstr(
        Context $context,
        Value $result,
        Value $namePtr,
        array $named
    ): void {
        if ([] === $named) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $tag = 'cn'.(string) ++self::$blockSeq;
        $emptyDone = BasicBlockHelper::append($context, 'compact_name_empty_done_'.$tag);
        $nonEmpty = BasicBlockHelper::append($context, 'compact_name_nonempty_'.$tag);
        $firstChar = $context->builder->load($namePtr);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $firstChar, $i8->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyDone, $nonEmpty);

        $context->builder->positionAtEnd($nonEmpty);
        $missDone = BasicBlockHelper::append($context, 'compact_name_miss_'.$tag);
        self::branchOnNamedVariableIndex(
            $context,
            ScopeBuiltinRuntime::matchNamedVariableIndexFromCstr(
                $context,
                $namePtr,
                self::namedVariablesTable($named)
            ),
            $named,
            'compact_name_'.$tag,
            $missDone,
            static function (Context $context, Variable $dest, string $name) use ($result, $emptyDone): void {
                $keyStr = $context->builder->load($context->constantStringFromString($name));
                self::storeVariableSnapshotAtStringKey($context, $result, $keyStr, $dest);
                $context->builder->branch($emptyDone);
            },
            $nonEmpty
        );

        $context->builder->positionAtEnd($missDone);
        self::emitCompactUndefinedVariableWarningFromCstr($context, $namePtr);
        $context->builder->branch($emptyDone);

        $context->builder->positionAtEnd($emptyDone);
    }

    private static function emitCompactUndefinedVariableWarningFromCstr(Context $context, Value $namePtr): void
    {
        ScopeBuiltinRuntime::emitCompactUndefinedVariableWarningFromCstr($context, $namePtr);
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

    private static function storeVariableSnapshotAtStringKey(
        Context $context,
        Value $ht,
        Value $keyStr,
        Variable $element
    ): void {
        if (JitValueBox::isValueOperand($element)) {
            ScopeBuiltinRuntime::storeVarSnapshotAtStringKey(
                $context,
                $ht,
                $keyStr,
                JitValueBox::valuePtrFromVariable($context, $element)
            );

            return;
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $element);
        ScopeBuiltinRuntime::storeVarSnapshotAtStringKey($context, $ht, $keyStr, $ptr);
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
     * get_defined_vars() — export all named locals in the current scope (issue #3135).
     */
    public static function getDefinedVars(Context $context): Value
    {
        $named = ScopeBuiltinHelper::namedVariablesForDefinedVars($context);
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
            self::storeVariableSnapshotAtStringKey($context, $ht, $keyStr, $dest);
            $context->builder->branch($nextBlock);
        }

        $context->builder->positionAtEnd($done);

        return self::wrapHashTableValue($context, $ht);
    }

    /**
     * get_declared_variables() — export names of set locals in the current scope (issue #4780).
     */
    public static function getDeclaredVariables(Context $context): Value
    {
        $named = ScopeBuiltinHelper::namedVariablesForDefinedVars($context);
        $ht = HashTableHelper::alloc($context);
        if ([] === $named) {
            return self::wrapHashTableValue($context, $ht);
        }

        $sizeT = $context->getTypeFromString('size_t');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'gdlv_idx');
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');

        $tag = 'gdlv'.(string) ++self::$blockSeq;
        $done = BasicBlockHelper::append($context, 'gdlv_done_'.$tag);
        $first = $context->builder->getInsertBlock();
        $blocks = [$first];
        $names = array_keys($named);
        $n = \count($names);
        for ($i = 1; $i < $n; ++$i) {
            $blocks[$i] = BasicBlockHelper::append($context, 'gdlv_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $name) {
            $dest = $named[$name];
            $context->builder->positionAtEnd($blocks[$i]);
            $isSet = IssetHelper::compile($context, $dest, null);
            $storeBlock = BasicBlockHelper::append($context, 'gdlv_store_'.$tag.'_'.$i);
            $nextBlock = ($i < $n - 1) ? $blocks[$i + 1] : $done;
            $context->builder->branchIf($isSet, $storeBlock, $nextBlock);

            $context->builder->positionAtEnd($storeBlock);
            $idx = $context->builder->load($idxSlot);
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            $context->builder->call($setStringAt, $ht, $idx, $nameStr);
            $one = $sizeT->constInt(1, false);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($nextBlock);
        }

        $context->builder->positionAtEnd($done);

        return self::wrapHashTableValue($context, $ht);
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

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitCompactUndefinedVariableWarning(Context $context, string $name): void
    {
        ScopeBuiltinRuntime::emitCompactUndefinedVariableWarning($context, $name);
    }

    /**
     * @param array<string, Variable> $named
     * @param callable(Context, Variable, string): void $onMatch  third arg is the variable name
     */
    private static function branchOnNamedVariableIndex(
        Context $context,
        Value $index,
        array $named,
        string $tag,
        BasicBlock $missBlock,
        callable $onMatch,
        ?BasicBlock $entryBlock = null
    ): void {
        $names = \array_keys($named);
        $n = \count($names);
        if (0 === $n) {
            if (null !== $entryBlock) {
                $context->builder->positionAtEnd($entryBlock);
            }
            $context->builder->branch($missBlock);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $minusOne = $i32->constInt(-1, true);
        $isMiss = $context->builder->icmp(Builder::INT_EQ, $index, $minusOne);
        $dispatchEntry = BasicBlockHelper::append($context, $tag.'_dispatch');
        if (null !== $entryBlock) {
            $context->builder->positionAtEnd($entryBlock);
        }
        $context->builder->branchIf($isMiss, $missBlock, $dispatchEntry);

        $checkBlocks = [$dispatchEntry];
        for ($i = 1; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, $tag.'_idx_'.$i);
        }

        foreach ($names as $i => $name) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $isCase = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i32->constInt($i, false)
            );
            $caseMatch = BasicBlockHelper::append($context, $tag.'_match_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $missBlock;
            $context->builder->branchIf($isCase, $caseMatch, $onMiss);

            $context->builder->positionAtEnd($caseMatch);
            $onMatch($context, $named[$name], $name);
        }
    }

    /**
     * @param array<string, Variable> $named
     */
    private static function namedVariablesTable(array $named): string
    {
        return \implode("\0", \array_keys($named));
    }
}
