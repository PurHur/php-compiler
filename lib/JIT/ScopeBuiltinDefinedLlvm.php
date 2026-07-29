<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value;

/** get_defined_vars() / get_declared_variables() LLVM emission (#19035, php-in-PHP). */
final class ScopeBuiltinDefinedLlvm
{
    private static int $blockSeq = 0;

    public static function getDefinedVars(Context $context): Value
    {
        $named = ScopeBuiltinHelper::namedVariablesForDefinedVars($context);
        $ht = HashTableHelper::alloc($context);
        if ([] === $named) {
            return self::wrapHashTableValue($context, $ht);
        }

        self::foreachNamedLocalIfSet(
            $context,
            $named,
            'gdv',
            static function (Context $context, Variable $dest, string $name, Value $ht): void {
                $keyStr = $context->builder->load($context->constantStringFromString($name));
                ScopeBuiltinEmitHelper::storeVariableSnapshotAtStringKey($context, $ht, $keyStr, $dest);
            },
            $ht
        );

        return self::wrapHashTableValue($context, $ht);
    }

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

        self::foreachNamedLocalIfSet(
            $context,
            $named,
            'gdlv',
            static function (Context $context, Variable $dest, string $name, Value $ht) use ($idxSlot, $setStringAt, $sizeT): void {
                $idx = $context->builder->load($idxSlot);
                $nameStr = $context->builder->load($context->constantStringFromString($name));
                $context->builder->call($setStringAt, $ht, $idx, $nameStr);
                $one = $sizeT->constInt(1, false);
                $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            },
            $ht
        );

        return self::wrapHashTableValue($context, $ht);
    }

    public static function wrapHashTableValue(Context $context, Value $ht): Value
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

    /**
     * @param array<string, Variable> $named
     * @param callable(Context, Variable, string, Value): void $onSet
     */
    private static function foreachNamedLocalIfSet(
        Context $context,
        array $named,
        string $tagPrefix,
        callable $onSet,
        Value $ht
    ): void {
        $tag = $tagPrefix.(string) ++self::$blockSeq;
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $first = $context->builder->getInsertBlock();
        $blocks = [$first];
        $names = array_keys($named);
        $n = \count($names);
        for ($i = 1; $i < $n; ++$i) {
            $blocks[$i] = BasicBlockHelper::append($context, $tag.'_check_'.$i);
        }

        foreach ($names as $i => $name) {
            $dest = $named[$name];
            $context->builder->positionAtEnd($blocks[$i]);
            // Match VM initializedSlots — omit compile-allocated unassigned CVs (#24660).
            $assignedBlock = BasicBlockHelper::append($context, $tag.'_assigned_'.$i);
            $storeBlock = BasicBlockHelper::append($context, $tag.'_store_'.$i);
            $nextBlock = ($i < $n - 1) ? $blocks[$i + 1] : $done;
            $isAssigned = ScopeVariableAssignedFlags::isAssignedCondition(
                $context,
                ScopeVariableAssignedFlags::flagKey($context, $name)
            );
            $context->builder->branchIf($isAssigned, $assignedBlock, $nextBlock);

            $context->builder->positionAtEnd($assignedBlock);
            $isSet = IssetHelper::compile($context, $dest, null);
            $context->builder->branchIf($isSet, $storeBlock, $nextBlock);

            $context->builder->positionAtEnd($storeBlock);
            $onSet($context, $dest, $name, $ht);
            $context->builder->branch($nextBlock);
        }

        $context->builder->positionAtEnd($done);
    }
}
