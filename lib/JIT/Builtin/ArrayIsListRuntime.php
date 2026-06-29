<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitArrayIsList;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_is_list() via ArrayIsListJitHelper PHP (#13645).
 *
 * Standalone AOT keeps LLVM in {@see JitArrayIsList::hashTableIsList()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::isList()}
 * php-src: ext/standard/array.c — php_array_is_list()
 */
final class ArrayIsListRuntime
{
    private const ABI_IS_LIST = '__array_is_list__check';

    private const HELPER_PATH = '/ext/standard/ArrayIsListJitHelper.php';

    private const IS_LIST_HELPER = 'PHPCompiler\\ext\\standard\\ArrayIsListJitHelper::isList';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_LIST_HELPER,
    ];

    public static function isList(Context $context, JITVariable $array): Value
    {
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(true);
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return JitArrayIsList::invoke($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_LIST),
            $ht
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

        $probe = $context->module->getNamedFunction(self::ABI_IS_LIST);
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
            self::ABI_IS_LIST,
            'array_is_list_bridge_entry',
            [$htPtr],
            $i1,
            self::IS_LIST_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13645'
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
        $fn = $context->module->getNamedFunction(self::ABI_IS_LIST);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_IS_LIST.' missing after ArrayIsListRuntime bridge (#13645)');
        }
        $context->registerFunction(self::ABI_IS_LIST, $fn);
    }
}
