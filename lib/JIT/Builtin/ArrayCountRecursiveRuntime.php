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
 * JIT/AOT link for count(COUNT_RECURSIVE) via ArrayCountRecursiveJitHelper PHP (#13274).
 *
 * Standalone AOT keeps LLVM in {@see \PHPCompiler\ext\standard\JitArrayCountRecursive}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::countRecursiveForCompiled()}
 * php-src: ext/standard/array.c — php_count_recursive
 */
final class ArrayCountRecursiveRuntime
{
    private const ABI_COUNT = '__array_count_recursive__fold';

    private const HELPER_PATH = '/ext/standard/ArrayCountRecursiveJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayCountRecursiveJitHelper::countRecursive';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
    ];

    public static function countRecursive(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::countRecursive($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $count = $context->builder->call(
            $context->lookupFunction(self::ABI_COUNT),
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

        $probe = $context->module->getNamedFunction(self::ABI_COUNT);
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
            self::ABI_COUNT,
            'array_count_recursive_bridge_entry',
            [$htPtr],
            $sizeT,
            self::COUNT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13274'
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
        $fn = $context->module->getNamedFunction(self::ABI_COUNT);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_COUNT.' missing after ArrayCountRecursiveRuntime bridge (#13274)');
        }
        $context->registerFunction(self::ABI_COUNT, $fn);
    }
}
