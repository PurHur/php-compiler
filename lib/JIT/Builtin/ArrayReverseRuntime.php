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
 * JIT/AOT link for array_reverse() via ArrayReverseJitHelper PHP (#12329).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildReverseArray()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::reverseCopy()}
 * php-src: ext/standard/array.c — php_array_reverse()
 */
final class ArrayReverseRuntime
{
    private const ABI_REVERSE = '__array_reverse__copy';

    private const HELPER_PATH = '/ext/standard/ArrayReverseJitHelper.php';

    private const REVERSE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReverseJitHelper::reverseCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REVERSE_HELPER,
    ];

    public static function reverse(Context $context, JITVariable $array, ?Value $preserveKeys = null): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::buildReverseArray($context, $array, $preserveKeys);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $i1 = $context->getTypeFromString('int1');
        $flag = null === $preserveKeys
            ? $i1->constInt(0, false)
            : $preserveKeys;

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REVERSE),
            $ht,
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

        $probe = $context->module->getNamedFunction(self::ABI_REVERSE);
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
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REVERSE,
            'array_reverse_bridge_entry',
            [$htPtr, $i1],
            $htPtr,
            self::REVERSE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12329'
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
        $fn = $context->module->getNamedFunction(self::ABI_REVERSE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_REVERSE.' missing after ArrayReverseRuntime bridge (#12329)');
        }
        $context->registerFunction(self::ABI_REVERSE, $fn);
    }
}
