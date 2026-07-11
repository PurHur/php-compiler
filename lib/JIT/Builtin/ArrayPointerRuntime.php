<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array internal-pointer builtins via ArrayPointerJitHelper PHP.
 *
 * Replaces ~900 LOC inline LLVM in ext/standard/JitArrayPointer.php.
 * SSOT: {@see \PHPCompiler\ext\standard\ArrayPointerJitHelper}
 * php-src: ext/standard/array.c — php_array_key, php_array_current, php_array_next, …
 */
final class ArrayPointerRuntime
{
    private const ABI_KEY = '__array_pointer_key__builtin';

    private const ABI_CURRENT = '__array_pointer_current__builtin';

    private const ABI_NEXT = '__array_pointer_next__builtin';

    private const ABI_PREV = '__array_pointer_prev__builtin';

    private const ABI_RESET = '__array_pointer_reset__builtin';

    private const ABI_END = '__array_pointer_end__builtin';

    private const HELPER_PATH = '/ext/standard/ArrayPointerJitHelper.php';

    private const KEY_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::keyArgv';

    private const CURRENT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::currentArgv';

    private const NEXT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::nextArgv';

    private const PREV_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::prevArgv';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::resetArgv';

    private const END_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPointerJitHelper::endArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KEY_HELPER,
        self::CURRENT_HELPER,
        self::NEXT_HELPER,
        self::PREV_HELPER,
        self::RESET_HELPER,
        self::END_HELPER,
    ];

    public static function key(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_KEY),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function current(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CURRENT),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function next(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NEXT),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function prev(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PREV),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function reset(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RESET),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function end(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_END),
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
        if (self::allBridgesPresent($context)) {
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
            self::ABI_KEY,
            'array_pointer_key_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::KEY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-key'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CURRENT,
            'array_pointer_current_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::CURRENT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-current'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NEXT,
            'array_pointer_next_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::NEXT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-next'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PREV,
            'array_pointer_prev_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::PREV_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-prev'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_RESET,
            'array_pointer_reset_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::RESET_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-reset'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_END,
            'array_pointer_end_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::END_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'array-pointer-end'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function allBridgesPresent(Context $context): bool
    {
        foreach (self::abiNames() as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function abiNames(): array
    {
        return [
            self::ABI_KEY,
            self::ABI_CURRENT,
            self::ABI_NEXT,
            self::ABI_PREV,
            self::ABI_RESET,
            self::ABI_END,
        ];
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::abiNames() as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayPointerRuntime bridge');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
