<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayCountValuesLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_count_values() (#12331, #27213).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayCountValuesJitHelper}
 * aborts in the helper body (peer array_flip #26970). Call-site LLVM via
 * {@see ArrayCountValuesLlvm}.
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::countValues()}
 * php-src: ext/standard/array.c — php_array_count_values()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayCountValuesRuntime
{
    private const ABI_COUNT = '__array_count_values__count';

    public static function countValues(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COUNT),
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
        $probe = $context->module->getNamedFunction(self::ABI_COUNT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitCountBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitCountBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_COUNT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_COUNT,
                $context->context->functionType($htPtr, false, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_count_values_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $counts = ArrayCountValuesLlvm::countValuesHashTable($context, $src);
        $context->builder->returnValue($counts);
        $context->registerFunction(self::ABI_COUNT, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_COUNT);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_COUNT.' missing after ArrayCountValuesRuntime bridge (#27213)');
        }
        $context->registerFunction(self::ABI_COUNT, $fn);
    }
}
