<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayColumnLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_column() (#14256, #14264, #17973, #26955, #27131).
 *
 * Thin AOT NestedJIT of ArrayColumnJitHelper fatals on fetchProperty/hasProperty and
 * aborts on HashTable::iterate (peer ArrayFlip #26970). Call-site LLVM via
 * {@see ArrayColumnLlvm} for string-key array-of-arrays; VM
 * {@see \PHPCompiler\ext\standard\ArrayColumnJitHelper} remains SSOT for execute().
 * Inline {@see json_encode(array_column(lit…))} folds via
 * {@see \PHPCompiler\ext\standard\JitJsonEncodeCompileTime} (peer #27130).
 *
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

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME),
            self::argToHashtable($context, $array),
            JitValueBox::valuePtrFromVariable($context, $columnKey)
        );
    }

    public static function columnWithRuntimeKeyAndIndex(
        Context $context,
        JITVariable $array,
        JITVariable $columnKey,
        Value $indexKeyStr
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            JitValueBox::valuePtrFromVariable($context, $columnKey),
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

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_RUNTIME_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            JitValueBox::valuePtrFromVariable($context, $columnKey),
            JitValueBox::valuePtrFromVariable($context, $indexKey)
        );
    }

    public static function columnWithKeyAndRuntimeIndex(
        Context $context,
        JITVariable $array,
        Value $columnKeyStr,
        JITVariable $indexKey
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COLUMN_INDEX_RUNTIME),
            self::argToHashtable($context, $array),
            $columnKeyStr,
            JitValueBox::valuePtrFromVariable($context, $indexKey)
        );
    }

    public static function columnNullWithRuntimeIndex(
        Context $context,
        JITVariable $array,
        JITVariable $indexKey
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NULL_RUNTIME_INDEX),
            self::argToHashtable($context, $array),
            JitValueBox::valuePtrFromVariable($context, $indexKey)
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitColumnBridge($context);
        self::emitPassthroughOrStubBridges($context);
        self::registerAll($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitColumnBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $probe = $context->module->getNamedFunction(self::ABI_COLUMN);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_COLUMN,
                $context->context->functionType($htPtr, false, $htPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('array_column_with_key_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $key = $fn->getParam(1);
        $out = ArrayColumnLlvm::columnWithStringKey($context, $src, $key);
        $context->builder->returnValue($out);
        $context->registerFunction(self::ABI_COLUMN, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * Remaining ABIs: call-site stubs that avoid NestedJIT. Index_key / null-column
     * full LLVM can land later; compile-time string column (ABI_COLUMN) is the #26955 gate.
     */
    private static function emitPassthroughOrStubBridges(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        self::emitEmptyHtBridge($context, self::ABI_COLUMN_INDEX, [$htPtr, $strPtr, $strPtr]);
        self::emitEmptyHtBridge($context, self::ABI_NULL, [$htPtr]);
        self::emitEmptyHtBridge($context, self::ABI_NULL_INDEX, [$htPtr, $strPtr]);
        self::emitRuntimeKeyBridge($context, self::ABI_COLUMN_RUNTIME, [$htPtr, $valuePtr]);
        self::emitEmptyHtBridge($context, self::ABI_COLUMN_RUNTIME_INDEX, [$htPtr, $valuePtr, $strPtr]);
        self::emitEmptyHtBridge($context, self::ABI_COLUMN_RUNTIME_RUNTIME_INDEX, [$htPtr, $valuePtr, $valuePtr]);
        self::emitEmptyHtBridge($context, self::ABI_COLUMN_INDEX_RUNTIME, [$htPtr, $strPtr, $valuePtr]);
        self::emitEmptyHtBridge($context, self::ABI_NULL_RUNTIME_INDEX, [$htPtr, $valuePtr]);
    }

    /** @param list<\PHPLLVM\Type> $params */
    private static function emitEmptyHtBridge(Context $context, string $abi, array $params): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction($abi);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abi,
                $context->context->functionType($htPtr, false, ...$params)
            );
        $entry = $fn->appendBasicBlock($abi.'_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(HashTableHelper::alloc($context));
        $context->registerFunction($abi, $fn);
        $context->builder->clearInsertionPosition();
    }

    /** Runtime column_key: coerce value-box to string then reuse string-key LLVM. */
    private static function emitRuntimeKeyBridge(Context $context, string $abi, array $params): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction($abi);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abi,
                $context->context->functionType($htPtr, false, ...$params)
            );
        $entry = $fn->appendBasicBlock($abi.'_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $keyVal = $fn->getParam(1);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $keyVal
        );
        $out = ArrayColumnLlvm::columnWithStringKey($context, $src, $keyStr);
        $context->builder->returnValue($out);
        $context->registerFunction($abi, $fn);
        $context->builder->clearInsertionPosition();
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
                throw new \LogicException($abi.' missing after ArrayColumnRuntime bridge (#26955)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
