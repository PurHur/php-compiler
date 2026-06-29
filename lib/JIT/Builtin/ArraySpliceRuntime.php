<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_splice() via ArraySpliceJitHelper PHP (#13643).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildSpliceArray()}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_splice}
 * php-src: ext/standard/array.c — php_array_splice()
 */
final class ArraySpliceRuntime
{
    private const ABI_SPLICE = '__array_splice__mutate';

    private const HELPER_PATH = '/ext/standard/ArraySpliceJitHelper.php';

    private const SPLICE_HELPER = 'PHPCompiler\\ext\\standard\\ArraySpliceJitHelper::spliceInPlace';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SPLICE_HELPER,
    ];

    public static function splice(
        Context $context,
        JITVariable $array,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?JITVariable $replacement,
        bool $hasReplacementArg
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::buildSpliceArray(
                $context,
                $array,
                $offset,
                $hasLength,
                $length,
                $replacement,
                $hasReplacementArg
            );
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $hasReplFlag = $hasReplacementArg
            ? $i1->constInt(1, false)
            : $i1->constInt(0, false);
        $replHt = $htPtr->constNull();
        if ($hasReplacementArg && null !== $replacement) {
            $replHt = self::lowerReplacementHashTable($context, $replacement);
        }

        $removed = $context->builder->call(
            $context->lookupFunction(self::ABI_SPLICE),
            $ht,
            $offset,
            $hasLength,
            $length,
            $hasReplFlag,
            $replHt
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $removed;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_SPLICE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SPLICE,
            'array_splice_bridge_entry',
            [$htPtr, $i64, $i1, $i64, $i1, $htPtr],
            $htPtr,
            self::SPLICE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13643'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function lowerReplacementHashTable(Context $context, JITVariable $replacement): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($replacement->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $replacement);
        }
        if (JITVariable::TYPE_HASHTABLE === ($replacement->type & ~JITVariable::IS_NATIVE_ARRAY)) {
            return ArrayBuiltinHelper::loadHashTable($context, $replacement);
        }

        $wrapped = HashTableHelper::alloc($context);
        ArrayBuiltinHelper::appendElement($context, $wrapped, $replacement);

        return $wrapped;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_SPLICE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SPLICE.' missing after ArraySpliceRuntime bridge (#13643)');
        }
        $context->registerFunction(self::ABI_SPLICE, $fn);
    }
}
