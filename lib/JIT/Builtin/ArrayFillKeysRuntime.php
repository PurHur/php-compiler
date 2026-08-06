<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableFillKeysLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_fill_keys() (#12487, #14439, #18407, #27127).
 *
 * Thin AOT NestedJIT of the PHP helper failed link / segfaulted / returned `{}`
 * under HELPER_RUNTIME_O=0 (#27127). Call-site LLVM via {@see HashTableFillKeysLlvm}
 * (peer ArrayFillRuntime / #27073, ArrayCombineRuntime / #27132).
 *
 * Native literal arrays materialize via {@see ArrayBuiltinHelper::nativeListToHashTable()}
 * then route through call-site LLVM (#18407).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::fillKeys()} /
 * {@see \PHPCompiler\ext\standard\ArrayFillKeysJitHelper}
 * php-src: ext/standard/array.c — php_array_fill_keys()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayFillKeysRuntime
{
    private const ABI_FILL_KEYS = '__array_fill_keys__copy';

    public static function fillKeys(Context $context, JITVariable $keys, JITVariable $value): Value
    {
        self::ensureLinked($context);
        $keysHt = ArrayBuiltinHelper::isNativeArray($keys->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $keys)
            : ArrayBuiltinHelper::loadHashTable($context, $keys);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FILL_KEYS),
            $keysHt,
            $valuePtr
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
        $probe = $context->module->getNamedFunction(self::ABI_FILL_KEYS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitFillKeysBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitFillKeysBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $probe = $context->module->getNamedFunction(self::ABI_FILL_KEYS);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_FILL_KEYS,
                $context->context->functionType($htPtr, false, $htPtr, $valuePtr)
            );

        $entry = $fn->appendBasicBlock('array_fill_keys_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $filled = HashTableFillKeysLlvm::fillKeys(
            $context,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($filled);
        $context->registerFunction(self::ABI_FILL_KEYS, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_FILL_KEYS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FILL_KEYS.' missing after ArrayFillKeysRuntime bridge (#27127)');
        }
        $context->registerFunction(self::ABI_FILL_KEYS, $fn);
    }
}
