<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_slice() via ArraySliceJitHelper PHP (#12410, #17936).
 *
 * Standalone AOT compiles {@see ArraySliceJitHelper} via JitVmHelperLink (#14285); native literal arrays materialize to hashtable then route through PHP (#17936).
 * SSOT: {@see \PHPCompiler\VM\HashTable::sliceCopy()}
 * php-src: ext/standard/array.c — php_array_slice()
 */
final class ArraySliceRuntime
{
    private const ABI_SLICE = '__array_slice__copy';

    private const HELPER_PATH = '/ext/standard/ArraySliceJitHelper.php';

    private const SLICE_HELPER = 'PHPCompiler\\ext\\standard\\ArraySliceJitHelper::sliceCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SLICE_HELPER,
    ];

    public static function slice(
        Context $context,
        JITVariable $array,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?Value $preserveKeys = null
    ): Value {
        self::ensureLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $flag = null === $preserveKeys
            ? $i1->constInt(0, false)
            : $preserveKeys;

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SLICE),
            self::argToHashtable($context, $array),
            $offset,
            $hasLength,
            $length,
            $flag
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
        $probe = $context->module->getNamedFunction(self::ABI_SLICE);
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
            self::ABI_SLICE,
            'array_slice_bridge_entry',
            [$htPtr, $i64, $i1, $i64, $i1],
            $htPtr,
            self::SLICE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12410'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_SLICE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SLICE.' missing after ArraySliceRuntime bridge (#12410)');
        }
        $context->registerFunction(self::ABI_SLICE, $fn);
    }
}
