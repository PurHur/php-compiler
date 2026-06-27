<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_chunk() via ArrayChunkJitHelper PHP (#12455).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildChunkArray()}.
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
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::buildChunkArray($context, $array, $size, $preserveKeys);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $i1 = $context->getTypeFromString('int1');
        $flag = null === $preserveKeys
            ? $i1->constInt(0, false)
            : $preserveKeys;

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CHUNK),
            $ht,
            $size,
            $flag
        );
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
