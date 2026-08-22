<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_replace() (#12516, #27519, #33699).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayReplaceJitHelper} baked
 * {@see HashTableCowLlvm} into the helper-runtime unit; editing CowLlvm did not refresh
 * that unit, so TYPE_NULL kept dropping (#33699). Call-site LLVM (peer ArrayMergeRuntime
 * / #27546, ArrayReverseRuntime / #27067) emits the live CowLlvm lowering into the
 * user module.
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::replaceCopy()} /
 * {@see \PHPCompiler\ext\standard\ArrayReplaceJitHelper}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace)
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayReplaceRuntime
{
    private const ABI_SINGLE = '__array_replace__single';

    private const ABI_TWO = '__array_replace__two';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function replace(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        self::ensureLinked($context);

        $firstHt = self::argToHashtable($context, $first);
        if ([] === $others) {
            return $context->builder->call(
                $context->lookupFunction(self::ABI_SINGLE),
                $firstHt
            );
        }

        $result = $context->builder->call(
            $context->lookupFunction(self::ABI_SINGLE),
            $firstHt
        );
        foreach ($others as $other) {
            $nextHt = self::argToHashtable($context, $other);
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

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, self::ABI_SINGLE, static function () use ($context, $fn): void {
            $entry = $fn->appendBasicBlock('array_replace_single_bridge_entry');
            $context->builder->positionAtEnd($entry);
            $copied = HashTableCowLlvm::duplicate($context, $fn->getParam(0));
            $context->builder->returnValue($copied);
        });
        $context->registerFunction(self::ABI_SINGLE, $fn);
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

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, self::ABI_TWO, static function () use ($context, $fn): void {
            $entry = $fn->appendBasicBlock('array_replace_two_bridge_entry');
            $context->builder->positionAtEnd($entry);
            $replaced = HashTableCowLlvm::replace(
                $context,
                $fn->getParam(0),
                $fn->getParam(1)
            );
            $context->builder->returnValue($replaced);
        });
        $context->registerFunction(self::ABI_TWO, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_SINGLE, self::ABI_TWO] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayReplaceRuntime bridge (#33699)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
