<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_filter() no-callback path via ArrayFilterJitHelper PHP (#12370, #17852).
 *
 * Standalone AOT compiles {@see ArrayFilterJitHelper} via JitVmHelperLink bridge (#14386); native literal arrays materialize to hashtable then route through PHP (#17852).
 * SSOT: {@see \PHPCompiler\ext\standard\array_filter}
 * php-src: ext/standard/array.c — php_array_filter()
 */
final class ArrayFilterRuntime
{
    private const ABI_FILTER = '__array_filter__default';

    private const HELPER_PATH = '/ext/standard/ArrayFilterJitHelper.php';

    private const FILTER_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFilterJitHelper::filterDefault';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FILTER_HELPER,
    ];

    public static function filterDefault(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FILTER),
            self::argToHashtable($context, $array)
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
        $probe = $context->module->getNamedFunction(self::ABI_FILTER);
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
            self::ABI_FILTER,
            'array_filter_bridge_entry',
            [$htPtr],
            $htPtr,
            self::FILTER_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12370'
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
        $fn = $context->module->getNamedFunction(self::ABI_FILTER);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FILTER.' missing after ArrayFilterRuntime bridge (#12370)');
        }
        $context->registerFunction(self::ABI_FILTER, $fn);
    }
}
