<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableValuesLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_values() (#12329, #27212).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayValuesJitHelper} returned
 * empty hashtables because NestedVmHashTableMethodLlvm skipped valuesCopy (#20533).
 * Call-site LLVM via {@see HashTableValuesLlvm} (peer ArrayKeysRuntime / #27211).
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::valuesCopy()}
 * php-src: ext/standard/array.c — php_array_values()
 */
final class ArrayValuesRuntime
{
    private const ABI_VALUES = '__array_values__copy';

    public static function values(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_VALUES),
            ArrayBuiltinHelper::loadHashTable($context, $array)
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
        $probe = $context->module->getNamedFunction(self::ABI_VALUES);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitValuesBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitValuesBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_VALUES);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_VALUES,
                $context->context->functionType($htPtr, false, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_values_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $values = HashTableValuesLlvm::values($context, $src);
        $context->builder->returnValue($values);
        $context->registerFunction(self::ABI_VALUES, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_VALUES);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_VALUES.' missing after ArrayValuesRuntime bridge (#27212)');
        }
        $context->registerFunction(self::ABI_VALUES, $fn);
    }
}
