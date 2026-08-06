<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReverseLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_reverse() (#12329, #27067, #27130).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayReverseJitHelper} failed on
 * unresolved HashTable::reverseCopy (#27067). Call-site LLVM via {@see HashTableReverseLlvm}
 * (peer ArrayFlipRuntime / #26970 / HashTableMergeLlvm / #27546).
 *
 * Native literals use {@see ArrayBuiltinHelper::nativeListToHashTable} (same as merge) so
 * NestedJIT json_encode sees a dense packed result rather than `{}` (#27130).
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::reverseCopy()}
 * php-src: ext/standard/array.c — php_array_reverse()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayReverseRuntime
{
    private const ABI_REVERSE = '__array_reverse__copy';

    public static function reverse(Context $context, JITVariable $array, ?Value $preserveKeys = null): Value
    {
        self::ensureLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $flag = null === $preserveKeys
            ? $i1->constInt(0, false)
            : $preserveKeys;

        // Prefer nativeListToHashTable for native literals — same as ArrayMergeRuntime
        // (#27546). loadHashTable→materializeNativeArrayForCall yields tables that
        // index/foreach correctly but NestedJIT json_encode sees as empty `{}` (#27130).
        $srcHt = ArrayBuiltinHelper::isNativeArray($array->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REVERSE),
            $srcHt,
            $flag
        );
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
        $probe = $context->module->getNamedFunction(self::ABI_REVERSE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitReverseBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitReverseBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $probe = $context->module->getNamedFunction(self::ABI_REVERSE);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_REVERSE,
                $context->context->functionType($htPtr, false, $htPtr, $i1)
            );

        $entry = $fn->appendBasicBlock('array_reverse_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $preserve = $fn->getParam(1);
        $reversed = HashTableReverseLlvm::reverse($context, $src, $preserve);
        $context->builder->returnValue($reversed);
        $context->registerFunction(self::ABI_REVERSE, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_REVERSE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_REVERSE.' missing after ArrayReverseRuntime bridge (#27067)');
        }
        $context->registerFunction(self::ABI_REVERSE, $fn);
    }
}
