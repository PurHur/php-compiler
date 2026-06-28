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
 * JIT/AOT link for count(COUNT_NORMAL) via ArrayCountJitHelper PHP (#13276).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::getNumElements()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::getNumElements()}
 * php-src: ext/standard/array.c — php_count
 */
final class ArrayCountRuntime
{
    private const ABI_NUM = '__array_count__numElements';

    private const HELPER_PATH = '/ext/standard/ArrayCountJitHelper.php';

    private const NUM_HELPER = 'PHPCompiler\\ext\\standard\\ArrayCountJitHelper::numElements';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NUM_HELPER,
    ];

    public static function numElements(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::getNumElements(
                $context,
                ArrayBuiltinHelper::loadHashTable($context, $array)
            );
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $count = $context->builder->call(
            $context->lookupFunction(self::ABI_NUM),
            $ht
        );

        return $context->builder->zExt($count, $context->getTypeFromString('int64'));
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

        $probe = $context->module->getNamedFunction(self::ABI_NUM);
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
        $sizeT = $context->getTypeFromString('size_t');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NUM,
            'array_count_normal_bridge_entry',
            [$htPtr],
            $sizeT,
            self::NUM_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13276'
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
        $fn = $context->module->getNamedFunction(self::ABI_NUM);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NUM.' missing after ArrayCountRuntime bridge (#13276)');
        }
        $context->registerFunction(self::ABI_NUM, $fn);
    }
}
