<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableMergeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_merge() (#10183, #22954, #27546).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayMergeJitHelper} returned a
 * PHP HashTable that is not a native `__hashtable__` — implode segfaults after
 * `c:main_before_php` (#27546). Call-site LLVM via {@see HashTableMergeLlvm}
 * (peer ArrayCombineRuntime / #27132, ArrayReverseRuntime / #27067, ArrayPadRuntime / #26971).
 *
 * Native literal arrays materialize via {@see ArrayBuiltinHelper::nativeListToHashTable()}
 * then route through call-site LLVM.
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::merge()} /
 * {@see \PHPCompiler\ext\standard\ArrayMergeJitHelper}
 * php-src: ext/standard/array.c — php_array_merge()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayMergeRuntime
{
    private const ABI_SINGLE = '__array_merge__single';

    private const ABI_TWO = '__array_merge__two';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function merge(Context $context, JITVariable ...$args): Value
    {
        self::ensureLinked($context);

        $count = \count($args);
        if ($count < 1) {
            return ArrayBuiltinHelper::emptyArray($context);
        }

        $firstHt = self::argToHashtable($context, $args[0]);
        if (1 === $count) {
            return $context->builder->call(
                $context->lookupFunction(self::ABI_SINGLE),
                $firstHt
            );
        }

        $result = $context->builder->call(
            $context->lookupFunction(self::ABI_SINGLE),
            $firstHt
        );
        for ($i = 1; $i < $count; ++$i) {
            $nextHt = self::argToHashtable($context, $args[$i]);
            $result = $context->builder->call(
                $context->lookupFunction(self::ABI_TWO),
                $result,
                $nextHt
            );
        }

        return $result;
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SINGLE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitSingleBridge($context);
        self::emitTwoBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    private static function emitSingleBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_SINGLE);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_SINGLE,
                $context->context->functionType($htPtr, false, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_merge_single_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $merged = HashTableMergeLlvm::mergeSingle($context, $fn->getParam(0));
        $context->builder->returnValue($merged);
        $context->registerFunction(self::ABI_SINGLE, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitTwoBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_TWO);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_TWO,
                $context->context->functionType($htPtr, false, $htPtr, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_merge_two_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $merged = HashTableMergeLlvm::mergeTwo(
            $context,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($merged);
        $context->registerFunction(self::ABI_TWO, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_SINGLE, self::ABI_TWO] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayMergeRuntime bridge (#27546)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
