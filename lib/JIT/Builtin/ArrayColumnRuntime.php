<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_column() via ArrayColumnJitHelper PHP (#14256, #14264, #17973).
 *
 * Standalone AOT compiles {@see ArrayColumnJitHelper} via JitVmHelperLink (#14275); native literal arrays materialize to hashtable then route through PHP (#17973).
 * SSOT: {@see \PHPCompiler\ext\standard\array_column}
 * php-src: ext/standard/array.c — php_array_column()
 */
final class ArrayColumnRuntime
{
    private const ABI_COLUMN = '__array_column__with_key';

    private const ABI_COLUMN_INDEX = '__array_column__with_key_index';

    private const ABI_NULL = '__array_column__null';

    private const ABI_NULL_INDEX = '__array_column__null_index';

    private const ABI_COLUMN_RUNTIME = '__array_column__with_runtime_key';

    private const ABI_COLUMN_RUNTIME_INDEX = '__array_column__with_runtime_key_index';

    private const ABI_COLUMN_RUNTIME_RUNTIME_INDEX = '__array_column__with_runtime_key_runtime_index';

    private const ABI_COLUMN_INDEX_RUNTIME = '__array_column__with_key_runtime_index';

    private const ABI_NULL_RUNTIME_INDEX = '__array_column__null_runtime_index';

    private const HELPER_PATH = '/ext/standard/ArrayColumnJitHelper.php';

    private const COLUMN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithKey';

    private const COLUMN_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithKeyAndIndex';

    private const NULL_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnNull';

    private const NULL_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnNullWithIndex';

    private const COLUMN_RUNTIME_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithRuntimeKey';

    private const COLUMN_RUNTIME_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithRuntimeKeyAndIndex';

    private const COLUMN_RUNTIME_RUNTIME_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithRuntimeKeyAndRuntimeIndex';

    private const COLUMN_INDEX_RUNTIME_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnWithKeyAndRuntimeIndex';

    private const NULL_RUNTIME_INDEX_HELPER = 'PHPCompiler\\ext\\standard\\ArrayColumnJitHelper::columnNullWithRuntimeIndex';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COLUMN_HELPER,
        self::COLUMN_INDEX_HELPER,
        self::NULL_HELPER,
        self::NULL_INDEX_HELPER,
        self::COLUMN_RUNTIME_HELPER,
        self::COLUMN_RUNTIME_INDEX_HELPER,
        self::COLUMN_RUNTIME_RUNTIME_INDEX_HELPER,
        self::COLUMN_INDEX_RUNTIME_HELPER,
        self::NULL_RUNTIME_INDEX_HELPER,
    ];

    public static function column(Context $context, JITVariable $array, Value $columnKeyStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN),
            self::argToHashtable($context, $array),
            $columnKeyStr
        );
    }

    public static function columnWithIndex(
        Context $context,
        JITVariable $array,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_INDEX),
            self::argToHashtable($context, $array),
            $columnKeyStr,
            $indexKeyStr
        );
    }

    public static function columnNull(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL),
            self::argToHashtable($context, $array)
        );
    }

    public static function columnNullWithIndex(
        Context $context,
        JITVariable $array,
        Value $indexKeyStr
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL_INDEX),
            self::argToHashtable($context, $array),
            $indexKeyStr
        );
    }

    public static function columnWithRuntimeKey(
        Context $context,
        JITVariable $array,
        JITVariable $columnKey
    ): Value {
        self::ensureLinked($context);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $columnKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME),
            self::argToHashtable($context, $array),
            $keyPtr
        );
    }

    public static function columnWithRuntimeKeyAndIndex(
        Context $context,
        JITVariable $array,
        JITVariable $columnKey,
        Value $indexKeyStr
    ): Value {
        self::ensureLinked($context);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $columnKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            $keyPtr,
            $indexKeyStr
        );
    }

    public static function columnWithRuntimeKeyAndRuntimeIndex(
        Context $context,
        JITVariable $array,
        JITVariable $columnKey,
        JITVariable $indexKey
    ): Value {
        self::ensureLinked($context);
        $columnPtr = JitValueBox::valuePtrFromVariable($context, $columnKey);
        $indexPtr = JitValueBox::valuePtrFromVariable($context, $indexKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            $columnPtr,
            $indexPtr
        );
    }

    public static function columnWithKeyAndRuntimeIndex(
        Context $context,
        JITVariable $array,
        Value $columnKeyStr,
        JITVariable $indexKey
    ): Value {
        self::ensureLinked($context);
        $indexPtr = JitValueBox::valuePtrFromVariable($context, $indexKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_INDEX_RUNTIME),
            self::argToHashtable($context, $array),
            $columnKeyStr,
            $indexPtr
        );
    }

    public static function columnNullWithRuntimeIndex(
        Context $context,
        JITVariable $array,
        JITVariable $indexKey
    ): Value {
        self::ensureLinked($context);
        $indexPtr = JitValueBox::valuePtrFromVariable($context, $indexKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            $indexPtr
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
        // NestedJIT string params are `__string__*` — i8* bridges mismatch the call site and
        // fail module verify under thin AOT (peer MathBaseConvertRuntime / #26884, #26955).
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN_RUNTIME,
            'array_column_with_runtime_key_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::COLUMN_RUNTIME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14264'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN_RUNTIME_INDEX,
            'array_column_with_runtime_key_index_entry',
            [$htPtr, $valuePtr, $strPtr],
            $htPtr,
            self::COLUMN_RUNTIME_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14264'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN_RUNTIME_RUNTIME_INDEX,
            'array_column_with_runtime_key_runtime_index_entry',
            [$htPtr, $valuePtr, $valuePtr],
            $htPtr,
            self::COLUMN_RUNTIME_RUNTIME_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14264'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COLUMN_INDEX_RUNTIME,
            'array_column_with_key_runtime_index_entry',
            [$htPtr, $strPtr, $valuePtr],
            $htPtr,
            self::COLUMN_INDEX_RUNTIME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14264'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NULL_RUNTIME_INDEX,
            'array_column_null_runtime_index_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::NULL_RUNTIME_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14264'
        );
        self::registerAll($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
            self::ABI_COLUMN_RUNTIME,
            self::ABI_COLUMN_RUNTIME_INDEX,
            self::ABI_COLUMN_RUNTIME_RUNTIME_INDEX,
            self::ABI_COLUMN_INDEX_RUNTIME,
            self::ABI_NULL_RUNTIME_INDEX,
        ] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayColumnRuntime bridge (#14256/#14264)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
