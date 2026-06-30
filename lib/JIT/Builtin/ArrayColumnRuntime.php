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
 * JIT/AOT link for array_column() compile-time key paths via ArrayColumnJitHelper PHP (#14256).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildColumnArray*} cluster.
 * Runtime column_key/index_key (TYPE_VALUE) stays on LLVM until a later slice.
 * SSOT: {@see \PHPCompiler\ext\standard\array_column}
 * php-src: ext/standard/array.c — php_array_column()
 */
final class ArrayColumnRuntime
{
    private const ABI_COLUMN = '__array_column__with_key';

    private const ABI_COLUMN_INDEX = '__array_column__with_key_index';

    private const ABI_NULL = '__array_column__null';

    private const ABI_NULL_INDEX = '__array_column__null_index';

    private const HELPER_PATH = '/ext/standard/ArrayColumnJitHelper.php';

    private const COLUMN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithKey';

    private const COLUMN_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithKeyAndIndex';

    private const NULL_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnNull';

    private const NULL_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnNullWithIndex';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COLUMN_HELPER,
        self::COLUMN_INDEX_HELPER,
        self::NULL_HELPER,
        self::NULL_INDEX_HELPER,
    ];

    public static function column(Context $context, JITVariable $array, Value $columnKeyStr): Value
    {
        if (self::useLlvm($context, $array)) {
            return ArrayBuiltinHelper::buildColumnArray($context, $array, $columnKeyStr);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN),
            $ht,
            $columnKeyStr
        );
    }

    public static function columnWithIndex(
        Context $context,
        JITVariable $array,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): Value {
        if (self::useLlvm($context, $array)) {
            return ArrayBuiltinHelper::buildColumnArrayWithIndex($context, $array, $columnKeyStr, $indexKeyStr);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_INDEX),
            $ht,
            $columnKeyStr,
            $indexKeyStr
        );
    }

    public static function columnNull(Context $context, JITVariable $array): Value
    {
        if (self::useLlvm($context, $array)) {
            return ArrayBuiltinHelper::buildColumnArrayNullColumn($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL),
            $ht
        );
    }

    public static function columnNullWithIndex(
        Context $context,
        JITVariable $array,
        Value $indexKeyStr
    ): Value {
        if (self::useLlvm($context, $array)) {
            return ArrayBuiltinHelper::buildColumnArrayNullColumnWithIndex($context, $array, $indexKeyStr);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL_INDEX),
            $ht,
            $indexKeyStr
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

        if (self::bridgeReady($context, self::ABI_COLUMN)) {
            self::registerAll($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('int8*');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN,
            'array_column_with_key_entry',
            [$htPtr, $strPtr],
            $htPtr,
            self::COLUMN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14256'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN_INDEX,
            'array_column_with_key_index_entry',
            [$htPtr, $strPtr, $strPtr],
            $htPtr,
            self::COLUMN_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14256'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NULL,
            'array_column_null_entry',
            [$htPtr],
            $htPtr,
            self::NULL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14256'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NULL_INDEX,
            'array_column_null_index_entry',
            [$htPtr, $strPtr],
            $htPtr,
            self::NULL_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14256'
        );
        self::registerAll($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function useLlvm(Context $context, JITVariable $array): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type);
    }

    private static function bridgeReady(Context $context, string $abi): bool
    {
        $probe = $context->module->getNamedFunction($abi);

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function registerAll(Context $context): void
    {
        foreach ([
            self::ABI_COLUMN,
            self::ABI_COLUMN_INDEX,
            self::ABI_NULL,
            self::ABI_NULL_INDEX,
        ] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayColumnRuntime bridge (#14256)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
