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
        ?Variable $prefixArg = null,
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

    public static function buildCompact(Context $context, Variable ...$nameArgs): Value
    {
        if ([] === $nameArgs) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
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
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableAtStringKey($context, $result, $keyStr, $local);

            return;
        }
        if (Superglobals::isSuperglobalName($name)) {
            $source = SuperglobalInit::load($context, $name);
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableAtStringKey($context, $result, $keyStr, $source);

            return;
        }
        self::addCompactFromScriptGlobal($context, $result, $name);
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
        self::storeVariableAtStringKey($context, $result, $keyStr, $global);
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
        $htResume = self::captureInsertBlock($context);
        self::collectCompactFromHashtable($context, $result, $ht, $named, $argNum);
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
    private static function collectCompactFromHashtable(
        Context $context,
        Value $result,
        Value $ht,
        array $named,
        int $argNum
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'compact_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'compact_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'compact_packed_body');
        $packedCollect = BasicBlockHelper::append($context, 'compact_packed_collect');
        $packedNext = BasicBlockHelper::append($context, 'compact_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'compact_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCollect, $packedNext);

        $context->builder->positionAtEnd($packedCollect);
        $entryPtr = self::compactValueEntryAt($context, $ht, $idx);
        $packedResume = self::captureInsertBlock($context);
        self::collectCompactValue($context, $result, $entryPtr, $named, $argNum);
        self::restoreInsertBlock($context, $packedResume);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'compact_str_walk');
        $context->builder->positionAtEnd($packedDone);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $strHead = BasicBlockHelper::append($context, 'compact_str_head');
        $strBody = BasicBlockHelper::append($context, 'compact_str_body');
        $strNext = BasicBlockHelper::append($context, 'compact_str_next');
        $strDone = BasicBlockHelper::append($context, 'compact_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $strResume = self::captureInsertBlock($context);
        self::collectCompactValue($context, $result, $valEntry, $named, $argNum);
        self::restoreInsertBlock($context, $strResume);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    private static function compactValueEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));

        return $context->builder->inBoundsGep($values, $index);
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

        $names = array_keys($named);
        $n = \count($names);
        $missDone = BasicBlockHelper::append($context, 'compact_name_miss_'.$tag);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $nonEmpty
                : BasicBlockHelper::append($context, 'compact_name_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $name) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $nameGlobal = $context->builder->load($context->constantStringFromString($name));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $namePtr,
                self::stringDataPtr($context, $nameGlobal)
            );
            $i32 = $context->getTypeFromString('int32');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $onMatch = BasicBlockHelper::append($context, 'compact_name_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $missDone;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            self::storeVariableAtStringKey($context, $result, $keyStr, $named[$name]);
            $context->builder->branch($emptyDone);
        }

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
            case Variable::TYPE_NATIVE_DOUBLE:
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
                $doubleBlock = BasicBlockHelper::append($context, 'compact_val_double_'.$tag);
                $done = BasicBlockHelper::append($context, 'compact_val_done_'.$tag);
                $afterString = BasicBlockHelper::append($context, 'compact_val_after_string_'.$tag);
                $afterLong = BasicBlockHelper::append($context, 'compact_val_after_long_'.$tag);
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

                $context->builder->positionAtEnd($afterLong);
                $isDouble = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
                );
                $context->builder->branchIf($isDouble, $doubleBlock, $done);

                $context->builder->positionAtEnd($doubleBlock);
                $doubleVal = $context->builder->call(
                    $context->lookupFunction('__value__readDouble'),
                    $valuePtr
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $keyStr,
                    $doubleVal
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
            self::storeDefinedVarAtStringKey($context, $ht, $keyStr, $dest);
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
        $doubleBlock = BasicBlockHelper::append($context, 'gdv_val_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'gdv_val_bool_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'gdv_val_ht_'.$tag);
        $done = BasicBlockHelper::append($context, 'gdv_val_done_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'gdv_val_after_string_'.$tag);
        $afterLong = BasicBlockHelper::append($context, 'gdv_val_after_long_'.$tag);
        $afterDouble = BasicBlockHelper::append($context, 'gdv_val_after_double_'.$tag);
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
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyDouble'),
            $ht,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
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
}
