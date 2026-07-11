<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_chunk() via ArrayChunkJitHelper PHP (#12455, #17951).
 *
 * Standalone AOT compiles {@see ArrayChunkJitHelper} via JitVmHelperLink (#14289); native literal arrays materialize to hashtable then route through PHP (#17951).
 * SSOT: {@see \PHPCompiler\VM\HashTable::chunkCopy()}
 * php-src: ext/standard/array.c — php_array_chunk()
 */
final class ArrayChunkRuntime
{
    private const ABI_CHUNK = '__array_chunk__copy';

    private const HELPER_PATH = '/ext/standard/ArrayChunkJitHelper.php';

    private const CHUNK_HELPER = 'PHPCompiler\\ext\\standard\\ArrayChunkJitHelper::chunkCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHUNK_HELPER,
    ];

    public static function chunk(
        Context $context,
        JITVariable $array,
        Value $size,
        ?Value $preserveKeys = null
    ): Value {
        self::ensureLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $flag = null === $preserveKeys
            ? $i1->constInt(0, false)
            : $preserveKeys;

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CHUNK),
            self::argToHashtable($context, $array),
            $size,
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
        $probe = $context->module->getNamedFunction(self::ABI_CHUNK);
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
            self::ABI_CHUNK,
            'array_chunk_bridge_entry',
            [$htPtr, $i64, $i1],
            $htPtr,
            self::CHUNK_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12455'
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
        $fn = $context->module->getNamedFunction(self::ABI_CHUNK);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_CHUNK.' missing after ArrayChunkRuntime bridge (#12455)');
        }
        $context->registerFunction(self::ABI_CHUNK, $fn);
    }
}
