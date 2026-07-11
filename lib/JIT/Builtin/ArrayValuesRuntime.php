<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_values() via ArrayValuesJitHelper PHP (#12329).
 *
 * Standalone AOT compiles {@see ArrayValuesJitHelper} via JitVmHelperLink (#14244); native literal arrays materialize to hashtable then route through PHP (#17922).
 * SSOT: {@see \PHPCompiler\VM\HashTable::valuesCopy()}
 * php-src: ext/standard/array.c — php_array_values()
 */
final class ArrayValuesRuntime
{
    private const ABI_VALUES = '__array_values__copy';

    private const HELPER_PATH = '/ext/standard/ArrayValuesJitHelper.php';

    private const VALUES_HELPER = 'PHPCompiler\\ext\\standard\\ArrayValuesJitHelper::valuesCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUES_HELPER,
    ];

    public static function values(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_VALUES),
            ArrayBuiltinHelper::loadHashTable($context, $array)
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
        $probe = $context->module->getNamedFunction(self::ABI_VALUES);
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
            self::ABI_VALUES,
            'array_values_bridge_entry',
            [$htPtr],
            $htPtr,
            self::VALUES_HELPER,
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
        $fn = $context->module->getNamedFunction(self::ABI_VALUES);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_VALUES.' missing after ArrayValuesRuntime bridge (#12329)');
        }
        $context->registerFunction(self::ABI_VALUES, $fn);
    }
}
