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
 * JIT/AOT link for array_combine() via ArrayCombineJitHelper PHP (#12502).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::combine()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::combine()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_combine)
 */
final class ArrayCombineRuntime
{
    private const ABI_COMBINE = '__array_combine__copy';

    private const HELPER_PATH = '/ext/standard/ArrayCombineJitHelper.php';

    private const COMBINE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayCombineJitHelper::combineCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMBINE_HELPER,
    ];

    public static function combine(Context $context, JITVariable $keys, JITVariable $values): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($keys->type)
            || ArrayBuiltinHelper::isNativeArray($values->type)) {
            return ArrayBuiltinHelper::combine($context, $keys, $values);
        }

        self::ensureLinked($context);
        $keysHt = ArrayBuiltinHelper::loadHashTable($context, $keys);
        $valuesHt = ArrayBuiltinHelper::loadHashTable($context, $values);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COMBINE),
            $keysHt,
            $valuesHt
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

        $probe = $context->module->getNamedFunction(self::ABI_COMBINE);
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COMBINE,
            'array_combine_bridge_entry',
            [$htPtr, $htPtr],
            $htPtr,
            self::COMBINE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12502'
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
        $fn = $context->module->getNamedFunction(self::ABI_COMBINE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_COMBINE.' missing after ArrayCombineRuntime bridge (#12502)');
        }
        $context->registerFunction(self::ABI_COMBINE, $fn);
    }
}
