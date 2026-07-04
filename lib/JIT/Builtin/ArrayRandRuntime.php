<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitArrayElem;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_rand() via ArrayRandJitHelper PHP (#16135).
 *
 * Standalone AOT compiles {@see ArrayRandJitHelper} via JitVmHelperLink bridge; replaces
 * legacy HashTable array_rand LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::arrayRandPacked()}
 * php-src: ext/standard/array.c — php_array_rand
 */
final class ArrayRandRuntime
{
    private const ABI_PICK = '__array_rand__pick';

    private const HELPER_PATH = '/ext/standard/ArrayRandJitHelper.php';

    private const PICK_HELPER = 'PHPCompiler\\ext\\standard\\ArrayRandJitHelper::pick';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PICK_HELPER,
    ];

    public static function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_rand', 1, 'array');
        if (isset($args[1])) {
            JitInternalStrictArg::requireInt($context, $args[1], 'array_rand', 'num', 2);
            $num = JitLongArg::lower($context, $args[1], 'array_rand() num');
        } else {
            $num = $context->getTypeFromString('int64')->constInt(1, false);
        }

        self::ensureLinked($context);
        $ht = self::argToHashtable($context, $args[0]);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PICK),
            $ht,
            $num
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
        $probe = $context->module->getNamedFunction(self::ABI_PICK);
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
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PICK,
            'array_rand_bridge_entry',
            [$htPtr, $i64],
            $valuePtr,
            self::PICK_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16135'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_PICK);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_PICK.' missing after ArrayRandRuntime bridge (#16135)');
        }
        $context->registerFunction(self::ABI_PICK, $fn);
    }
}
