<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_first()/array_last() via ArrayElemJitHelper PHP (#15063).
 *
 * Replaces ~150 LOC inline LLVM in ext/standard/JitArrayElem.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray}.
 * php-src: ext/standard/array.c — php_array_first, php_array_last
 */
final class ArrayElemRuntime
{
    private const ABI_FIRST = '__array_first__builtin';

    private const ABI_LAST = '__array_last__builtin';

    private const HELPER_PATH = '/ext/standard/ArrayElemJitHelper.php';

    private const FIRST_HELPER = 'PHPCompiler\\ext\\standard\\ArrayElemJitHelper::firstArgv';

    private const LAST_HELPER = 'PHPCompiler\\ext\\standard\\ArrayElemJitHelper::lastArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FIRST_HELPER,
        self::LAST_HELPER,
    ];

    public static function first(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FIRST),
            $ht
        );
    }

    public static function last(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_LAST),
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
        $probeFirst = $context->module->getNamedFunction(self::ABI_FIRST);
        $probeLast = $context->module->getNamedFunction(self::ABI_LAST);
        if (null !== $probeFirst && $probeFirst->countBasicBlocks() > 0
            && null !== $probeLast && $probeLast->countBasicBlocks() > 0) {
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
            self::ABI_FIRST,
            'array_first_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::FIRST_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15063'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LAST,
            'array_last_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::LAST_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15063'
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
        foreach ([self::ABI_FIRST, self::ABI_LAST] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayElemRuntime bridge (#15063)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
