<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\UsortKeyedLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for usort()/uksort()/uasort() closure comparators via UsortJitHelper PHP (#15518).
 *
 * Packed usort Closures: NestedJIT {@see \PHPCompiler\ext\standard\UsortJitHelper::sortPackedWithClosure}.
 * uksort/uasort Closures: pure LLVM {@see UsortKeyedLlvm} — NestedJIT of keyed helpers aborts (#27217).
 *
 * strcmp string callbacks route through {@see SortRuntime} (packed lists),
 * {@see KeySortRuntime} (uksort keys), and {@see HashTable}::__hashtable__sortStringKeyValues
 * (uasort string-key values; #5698).
 * SSOT: {@see \PHPCompiler\ext\standard\usort_}, {@see \PHPCompiler\ext\standard\uksort_},
 * {@see \PHPCompiler\ext\standard\uasort_}
 * php-src: ext/standard/array.c — php_array_usort / php_array_uksort / php_array_uasort
 */
final class UsortRuntime
{
    private const ABI_USORT_CLOSURE = '__usort__packed_closure';

    private const HELPER_PATH = '/ext/standard/UsortJitHelper.php';

    private const USORT_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\UsortJitHelper::sortPackedWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::USORT_CLOSURE_HELPER,
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

    public static function uasortValues(Context $context, JITVariable $array, JITVariable $callback): Value
    {
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        if (UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            self::sortValuesWithClosure($context, $array, $callback);
        } else {
            self::sortValuesByStrcmp($context, $array);
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
        // Thin standalone AOT: publish sg_vm_context before NestedJIT of UsortJitHelper
        // (VmClosureCall needs an active VM context — #24142 / peer #17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedClosureInvokeLlvm::ensureLinked($context);

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
        // Packed usort only — uksort/uasort Closures use UsortKeyedLlvm (#27217).
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_USORT_CLOSURE,
            'usort_packed_closure_bridge_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::USORT_CLOSURE_HELPER,
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
        $sorted = $context->builder->call(
            $context->lookupFunction(self::ABI_USORT_CLOSURE),
            $ht,
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $sorted);
    }

    private static function sortKeysWithClosure(Context $context, JITVariable $array, JITVariable $callback): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'uksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or build the list with [] append'
            );
        }

        NestedClosureInvokeLlvm::ensureLinked($context);
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        SpaceshipRuntime::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        UsortKeyedLlvm::sortKeysWithClosure($context, $ht, $callback);
    }

    private static function sortValuesWithClosure(Context $context, JITVariable $array, JITVariable $callback): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'uasort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or build the list with [] append'
            );
        }

        NestedClosureInvokeLlvm::ensureLinked($context);
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        SpaceshipRuntime::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        UsortKeyedLlvm::sortValuesWithClosure($context, $ht, $callback);
    }

    /** uasort() strcmp — string-key value sort in LLVM (#5698, asort_ parity). */
    private static function sortValuesByStrcmp(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            SortRuntime::sortPacked($context, $array);

            return;
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValues'), $ht);
    }

    private static function bridgesComplete(Context $context): bool
    {
        $probe = $context->module->getNamedFunction(self::ABI_USORT_CLOSURE);

        return null !== $probe && 0 !== $probe->countBasicBlocks();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_USORT_CLOSURE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_USORT_CLOSURE.' missing after UsortRuntime bridge (#15518)');
        }
        $context->registerFunction(self::ABI_USORT_CLOSURE, $fn);
    }
}
