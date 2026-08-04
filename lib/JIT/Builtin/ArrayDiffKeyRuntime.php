<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableKeyFilterLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_diff_key() (#12553, #27522).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayDiffKeyJitHelper}
 * returned empty hashtables (#27522 — peer array_keys #27211 / intersect_key #27521).
 * Call-site LLVM via {@see HashTableKeyFilterLlvm}.
 *
 * Fresh ABI names (`__copy` / `__filter`) avoid colliding with helper-runtime-cached
 * NestedJIT bodies for the old `__single` / `__two` symbols (#15889).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff_key)
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayDiffKeyRuntime
{
    private const ABI_SINGLE = '__array_diff_key__copy';

    private const ABI_TWO = '__array_diff_key__filter';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function diffKey(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        // Load all operand HTs before bridge emit (#27522 / peer #27521).
        $firstHt = self::argToHashtable($context, $first);
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::argToHashtable($context, $other);
        }

        self::ensureLinked($context);

        if ([] === $otherHts) {
            return self::callDiffKeySingle($context, $firstHt);
        }

        $result = self::callDiffKeySingle($context, $firstHt);
        foreach ($otherHts as $nextHt) {
            $result = self::callDiffKeyTwo($context, $result, $nextHt);
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
        $entry = $fn->appendBasicBlock('array_diff_key_copy_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $copied = HashTableKeyFilterLlvm::copy($context, $fn->getParam(0));
        $context->builder->returnValue($copied);
        $context->registerFunction(self::ABI_SINGLE, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitTwoBridge(Context $context): void
    {
        $fn = self::declareFunction($context, self::ABI_TWO);
        $entry = $fn->appendBasicBlock('array_diff_key_filter_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $filtered = HashTableKeyFilterLlvm::diffKey(
            $context,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($filtered);
        $context->registerFunction(self::ABI_TWO, $fn);
        $context->builder->clearInsertionPosition();
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
                    default => throw new \LogicException('unknown array_diff_key bridge: '.$name),
                }
            )
        );
    }

    private static function callDiffKeySingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SINGLE),
            $ht
        );
    }

    private static function callDiffKeyTwo(Context $context, Value $left, Value $right): Value
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
                throw new \LogicException($name.' missing after ArrayDiffKeyRuntime bridge (#27522)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
