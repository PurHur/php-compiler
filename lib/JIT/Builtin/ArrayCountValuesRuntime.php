<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_count_values() via ArrayCountValuesJitHelper PHP (#12331).
 *
 * Standalone AOT compiles {@see ArrayCountValuesJitHelper} via JitVmHelperLink bridge (#14485); native literal arrays keep LLVM in {@see ArrayBuiltinHelper::arrayCountValues()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::countValues()}
 * php-src: ext/standard/array.c — php_array_count_values()
 */
final class ArrayCountValuesRuntime
{
    private const ABI_COUNT = '__array_count_values__count';

    private const HELPER_PATH = '/ext/standard/ArrayCountValuesJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayCountValuesJitHelper::countValues';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
    ];

    public static function countValues(Context $context, JITVariable $array): Value
    {
        // Route all operands through the PHP helper so warn-and-skip matches VM (#4267, #17398).
        // Native LLVM array_count_values skips invalid entries without E_WARNING.
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COUNT),
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COUNT,
            'array_count_values_bridge_entry',
            [$htPtr],
            $htPtr,
            self::COUNT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12331'
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
            throw new \LogicException(self::ABI_COUNT.' missing after ArrayCountValuesRuntime bridge (#12331)');
        }
        $context->registerFunction(self::ABI_COUNT, $fn);
    }
}
