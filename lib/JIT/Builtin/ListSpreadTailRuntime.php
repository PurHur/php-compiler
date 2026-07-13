<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for list destructuring spread tail via ListSpreadTailJitHelper PHP (#18446).
 *
 * SSOT: {@see \PHPCompiler\VM\HashTable::copyListSpreadTail()}
 */
final class ListSpreadTailRuntime
{
    private const ABI_COPY_TAIL = '__list_spread_tail__copy';

    private const HELPER_PATH = '/ext/standard/ListSpreadTailJitHelper.php';

    private const COPY_TAIL_HELPER = 'PHPCompiler\\ext\\standard\\ListSpreadTailJitHelper::copyTail';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COPY_TAIL_HELPER,
    ];

    /**
     * @param list<string> $excludedStringKeys compile-time keys already bound before spread
     */
    public static function copyTail(
        Context $context,
        JITVariable $array,
        Value $offset,
        array $excludedStringKeys
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COPY_TAIL),
            self::argToHashtable($context, $array),
            $offset,
            self::excludedKeysHashtable($context, $excludedStringKeys)
        );
    }

    /**
     * @param list<string> $excludedStringKeys
     */
    private static function excludedKeysHashtable(Context $context, array $excludedStringKeys): Value
    {
        $vmTable = \PHPCompiler\ext\standard\ListSpreadTailJitHelper::excludedKeysTable($excludedStringKeys);
        $var = HashTableHelper::variableFromVmHashTable($context, $vmTable);

        return HashTableHelper::loadHashtablePointer($context, $var);
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
        $probe = $context->module->getNamedFunction(self::ABI_COPY_TAIL);
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COPY_TAIL,
            'list_spread_tail_bridge_entry',
            [$htPtr, $i64, $htPtr],
            $htPtr,
            self::COPY_TAIL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18446'
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
        $fn = $context->module->getNamedFunction(self::ABI_COPY_TAIL);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_COPY_TAIL.' missing after ListSpreadTailRuntime bridge (#18446)');
        }
        $context->registerFunction(self::ABI_COPY_TAIL, $fn);
    }
}
