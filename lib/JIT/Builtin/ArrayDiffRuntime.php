<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableValueFilterLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_diff() (#12527, #23116, #35603).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayDiffJitHelper} returned
 * non-native hashtables and segfaulted under standalone AOT (peer #27522 array_diff_key).
 * Call-site LLVM via {@see HashTableValueFilterLlvm}.
 *
 * Fresh ABI names (`__copy` / `__filter`) avoid colliding with helper-runtime-cached
 * NestedJIT bodies for the old `__single` / `__two` symbols (#15889).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff)
 */
final class ArrayDiffRuntime
{
    private const ABI_SINGLE = '__array_diff__copy';

    private const ABI_TWO = '__array_diff__filter';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function diff(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        $firstHt = self::argToHashtable($context, $first);
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::argToHashtable($context, $other);
        }

        self::ensureLinked($context);

        if ([] === $otherHts) {
            return self::callDiffSingle($context, $firstHt);
        }

        $result = self::callDiffSingle($context, $firstHt);
        foreach ($otherHts as $nextHt) {
            $result = self::callDiffTwo($context, $result, $nextHt);
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

    private static function emitSingleBridge(Context $context): void
    {
        $fn = self::declareFunction($context, self::ABI_SINGLE);
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, self::ABI_SINGLE, static function () use ($context, $fn): void {
            $entry = $fn->appendBasicBlock('array_diff_copy_bridge_entry');
            $context->builder->positionAtEnd($entry);
            $copied = HashTableValueFilterLlvm::copy($context, $fn->getParam(0));
            $context->builder->returnValue($copied);
        });
        $context->registerFunction(self::ABI_SINGLE, $fn);
    }

    private static function emitTwoBridge(Context $context): void
    {
        $fn = self::declareFunction($context, self::ABI_TWO);
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, self::ABI_TWO, static function () use ($context, $fn): void {
            $entry = $fn->appendBasicBlock('array_diff_filter_bridge_entry');
            $context->builder->positionAtEnd($entry);
            $filtered = HashTableValueFilterLlvm::diff(
                $context,
                $fn->getParam(0),
                $fn->getParam(1)
            );
            $context->builder->returnValue($filtered);
        });
        $context->registerFunction(self::ABI_TWO, $fn);
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $htPtr,
                false,
                ...match ($name) {
                    self::ABI_SINGLE => [$htPtr],
                    self::ABI_TWO => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_diff bridge: '.$name),
                }
            )
        );
    }

    private static function callDiffSingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SINGLE),
            $ht
        );
    }

    private static function callDiffTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_TWO),
            $left,
            $right
        );
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_SINGLE, self::ABI_TWO] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayDiffRuntime bridge (#35603)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
