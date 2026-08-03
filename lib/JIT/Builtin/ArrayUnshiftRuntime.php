<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call\HashTableUnshiftPrepend;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_unshift() (#12717, #22818, #27226).
 *
 * Avoid NestedJIT of ArrayUnshiftJitHelper::unshiftFromList
 * (`foreach ($ht->iterate())` → ObjectPropertyForeach null slot, #27226).
 * Call-site prepend via HashTableUnshiftPrepend. VM still uses the PHP helper.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\array_unshift}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_unshift)
 */
final class ArrayUnshiftRuntime
{
    public static function unshift(Context $context, JITVariable $array, JITVariable ...$values): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $native = ArrayBuiltinHelper::isNativeArray($array->type);
        $htVar = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
        if (0 === \count($values)) {
            $count = ArrayBuiltinHelper::getNumElements($context, $ht);
        } else {
            $countRaw = (new HashTableUnshiftPrepend())->call($context, $htVar, ...$values);
            $count = JitNestedHelperCoerce::coerceBridgeResult(
                $context,
                $countRaw,
                $context->getTypeFromString('int64')
            );
        }
        if ($native) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }

        return $count;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__array_unshift__count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementIfMissing($context, '__array_unshift__count', self::implementCountBridge(...));
        self::implementIfMissing($context, '__array_unshift__prepend', self::implementPrependBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @param callable(Context, LlvmFunction): void $emit */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $i64,
                false,
                ...match ($name) {
                    '__array_unshift__count' => [$htPtr],
                    '__array_unshift__prepend' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_unshift bridge: '.$name),
                }
            )
        );
    }

    private static function implementCountBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_unshift_count_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            ArrayBuiltinHelper::getNumElements($context, $fn->getParam(0))
        );
    }

    private static function implementPrependBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_unshift_prepend_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $dest = $fn->getParam(0);
        $valuesHt = $fn->getParam(1);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $k = $context->builder->truncOrBitCast(
            ArrayBuiltinHelper::getNumElements($context, $valuesHt),
            $sizeT
        );
        $n = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $dest);

        $isEmptyValues = $context->builder->icmp(Builder::INT_EQ, $k, $zero);
        $retBb = BasicBlockHelper::append($context, 'array_unshift_prepend_ret');
        $workBb = BasicBlockHelper::append($context, 'array_unshift_prepend_work');
        $context->builder->branchIf($isEmptyValues, $retBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $need = $context->builder->addNoSignedWrap($n, $k);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $dest, $need);

        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($context->builder->subNoSignedWrap($n, $one), $indexSlot);
        $sHead = BasicBlockHelper::append($context, 'array_unshift_prepend_shift_head');
        $sBody = BasicBlockHelper::append($context, 'array_unshift_prepend_shift_body');
        $sDone = BasicBlockHelper::append($context, 'array_unshift_prepend_shift_done');
        $skipShift = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $context->builder->branchIf($skipShift, $sDone, $sHead);

        $context->builder->positionAtEnd($sHead);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SLT, $idx, $zero);
        $context->builder->branchIf($past, $sDone, $sBody);
        $context->builder->positionAtEnd($sBody);
        $srcVal = HashTableHelper::readIndexedToValueBox($context, $dest, $idx);
        HashTableHelper::setAtIndex(
            $context,
            $dest,
            $context->builder->addNoSignedWrap($idx, $k),
            $srcVal
        );
        $context->builder->store($context->builder->subNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($sHead);

        $context->builder->positionAtEnd($sDone);
        $wIdx = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $wIdx);
        $wHead = BasicBlockHelper::append($context, 'array_unshift_prepend_write_head');
        $wBody = BasicBlockHelper::append($context, 'array_unshift_prepend_write_body');
        $context->builder->branch($wHead);
        $context->builder->positionAtEnd($wHead);
        $wi = $context->builder->load($wIdx);
        $wDone = $context->builder->icmp(Builder::INT_SGE, $wi, $k);
        $context->builder->branchIf($wDone, $retBb, $wBody);
        $context->builder->positionAtEnd($wBody);
        HashTableHelper::setAtIndex(
            $context,
            $dest,
            $wi,
            HashTableHelper::readIndexedToValueBox($context, $valuesHt, $wi)
        );
        $context->builder->store($context->builder->addNoSignedWrap($wi, $one), $wIdx);
        $context->builder->branch($wHead);

        $context->builder->positionAtEnd($retBb);
        $map = $context->structFieldMap['__hashtable__'];
        $finalN = $context->builder->addNoSignedWrap($n, $k);
        $context->builder->store($finalN, $context->builder->structGep($dest, $map['nextFreeElement']));
        $context->builder->store($finalN, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->returnValue(
            JitNestedHelperCoerce::i64ToScalar(
                $context,
                JitNestedHelperCoerce::scalarToI64(
                    $context,
                    $finalN,
                    $context->getTypeFromString('int64')
                ),
                $context->getTypeFromString('int64')
            )
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_unshift__count', '__array_unshift__prepend'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayUnshiftRuntime bridge (#12717)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
