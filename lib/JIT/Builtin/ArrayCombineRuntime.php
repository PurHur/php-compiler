<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCombineLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_combine() (#12502, #27132).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayCombineJitHelper} returned a
 * PHP HashTable that is not a native `__hashtable__` — json_encode segfaults after
 * `c:main_before_php` (#27132). Call-site LLVM via {@see HashTableCombineLlvm}
 * (peer ArrayReverseRuntime / #27067, ArrayPadRuntime / #26971, ArrayFlipRuntime / #26970).
 *
 * Native literal arrays materialize via {@see ArrayBuiltinHelper::nativeListToHashTable()}
 * then route through call-site LLVM (#18013).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::combine()} /
 * {@see \PHPCompiler\ext\standard\ArrayCombineJitHelper}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_combine)
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayCombineRuntime
{
    private const ABI_COMBINE = '__array_combine__copy';

    public static function combine(Context $context, JITVariable $keys, JITVariable $values): Value
    {
        ArrayBuiltinHelper::guardCombinePackedListLengthMismatch($context, $keys, $values, 'bridge');

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COMBINE),
            self::argToHashtable($context, $keys),
            self::argToHashtable($context, $values)
        );
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
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
        $probe = $context->module->getNamedFunction(self::ABI_COMBINE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitCombineBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitCombineBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_COMBINE);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_COMBINE,
                $context->context->functionType($htPtr, false, $htPtr, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_combine_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $combined = HashTableCombineLlvm::combine(
            $context,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($combined);
        $context->registerFunction(self::ABI_COMBINE, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_COMBINE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_COMBINE.' missing after ArrayCombineRuntime bridge (#27132)');
        }
        $context->registerFunction(self::ABI_COMBINE, $fn);
    }
}
