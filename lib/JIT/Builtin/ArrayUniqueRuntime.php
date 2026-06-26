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
 * JIT/AOT link for array_unique() via ArrayUniqueJitHelper PHP (#12341).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arrayUnique()}.
 * SSOT: {@see \PHPCompiler\ext\standard\ArrayUniqueJitHelper}
 * php-src: ext/standard/array.c — php_array_unique()
 */
final class ArrayUniqueRuntime
{
    private const ABI_UNIQUE = '__array_unique__copy';

    private const HELPER_PATH = '/ext/standard/ArrayUniqueJitHelper.php';

    private const UNIQUE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayUniqueJitHelper::unique';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UNIQUE_HELPER,
    ];

    public static function unique(Context $context, JITVariable $array, int $flags): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::arrayUnique($context, $array, $flags);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction(self::ABI_UNIQUE),
            $ht,
            $i64->constInt($flags, false)
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

        $probe = $context->module->getNamedFunction(self::ABI_UNIQUE);
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
            self::ABI_UNIQUE,
            'array_unique_bridge_entry',
            [$htPtr, $i64],
            $htPtr,
            self::UNIQUE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12341'
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
        $fn = $context->module->getNamedFunction(self::ABI_UNIQUE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_UNIQUE.' missing after ArrayUniqueRuntime bridge (#12341)');
        }
        $context->registerFunction(self::ABI_UNIQUE, $fn);
    }
}
