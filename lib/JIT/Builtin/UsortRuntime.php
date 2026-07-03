<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for usort()/uksort() closure comparators via UsortJitHelper PHP (#15518).
 *
 * strcmp string callbacks route through {@see SortRuntime} / {@see KeySortRuntime} (existing PHP bridges).
 * SSOT: {@see \PHPCompiler\ext\standard\usort_}, {@see \PHPCompiler\ext\standard\uksort_}
 * php-src: ext/standard/array.c — php_array_usort / php_array_uksort
 */
final class UsortRuntime
{
    private const ABI_USORT_CLOSURE = '__usort__packed_closure';

    private const ABI_UKSORT_CLOSURE = '__uksort__keys_closure';

    private const HELPER_PATH = '/ext/standard/UsortJitHelper.php';

    private const USORT_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\UsortJitHelper::sortPackedWithClosure';

    private const UKSORT_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\UsortJitHelper::sortKeysWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::USORT_CLOSURE_HELPER,
        self::UKSORT_CLOSURE_HELPER,
    ];

    public static function usortPacked(Context $context, JITVariable $array, JITVariable $callback): Value
    {
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        if (UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            self::sortPackedWithClosure($context, $array, $callback);
        } else {
            SortRuntime::sortPacked($context, $array);
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    public static function uksortKeys(Context $context, JITVariable $array, JITVariable $callback): Value
    {
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        if (UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            self::sortKeysWithClosure($context, $array, $callback);
        } else {
            KeySortRuntime::ksortByKey($context, $array);
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
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
        if (self::bridgesComplete($context)) {
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
        $void = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_USORT_CLOSURE,
            'usort_packed_closure_bridge_entry',
            [$htPtr, $valuePtr],
            $void,
            self::USORT_CLOSURE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15518'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_UKSORT_CLOSURE,
            'uksort_keys_closure_bridge_entry',
            [$htPtr, $valuePtr],
            $void,
            self::UKSORT_CLOSURE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15518'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function sortPackedWithClosure(Context $context, JITVariable $array, JITVariable $callback): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'usort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or build the list with [] append'
            );
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call(
            $context->lookupFunction(self::ABI_USORT_CLOSURE),
            $ht,
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    private static function sortKeysWithClosure(Context $context, JITVariable $array, JITVariable $callback): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'uksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or build the list with [] append'
            );
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call(
            $context->lookupFunction(self::ABI_UKSORT_CLOSURE),
            $ht,
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    private static function bridgesComplete(Context $context): bool
    {
        foreach ([self::ABI_USORT_CLOSURE, self::ABI_UKSORT_CLOSURE] as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_USORT_CLOSURE, self::ABI_UKSORT_CLOSURE] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after UsortRuntime bridge (#15518)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
