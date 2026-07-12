<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_sum() via ArraySumJitHelper PHP (#12590).
 *
 * Standalone AOT and native literal arrays materialize to hashtable then route through PHP (#18133).
 * SSOT: {@see \PHPCompiler\ext\standard\ArraySumJitHelper}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_sum)
 */
final class ArraySumRuntime
{
    private const ABI_SUM = '__array_sum__fold';

    private const HELPER_PATH = '/ext/standard/ArraySumJitHelper.php';

    private const SUM_HELPER = 'PHPCompiler\\ext\\standard\\ArraySumJitHelper::sum';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUM_HELPER,
    ];

    public static function sum(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::isNativeArray($array->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SUM),
            $ht
        );
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
        $probe = $context->module->getNamedFunction(self::ABI_SUM);
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
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SUM,
            'array_sum_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::SUM_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12590'
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
        $fn = $context->module->getNamedFunction(self::ABI_SUM);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SUM.' missing after ArraySumRuntime bridge (#12590)');
        }
        $context->registerFunction(self::ABI_SUM, $fn);
    }
}
