<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\ArrayWalkLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_walk() / array_walk_recursive() via ArrayWalkJitHelper PHP (#14875, #14877, #14933).
 *
 * Standalone AOT compiles {@see ArrayWalkJitHelper} via JitVmHelperLink bridge (string builtin + closure).
 * Thin AOT must publish sg_vm_context + NestedClosureInvoke before NestedJIT of the helper
 * (VmClosureCall needs an active VM context — #27632 / peer UsortRuntime #24142).
 * In-place HT mutation: skip unconditional storeHashtableInArrayVariable on value boxes (#27227).
 * SSOT: {@see \PHPCompiler\ext\standard\array_walk}, {@see \PHPCompiler\ext\standard\array_walk_recursive}
 * php-src: ext/standard/array.c — php_array_walk() / php_array_walk_recursive()
 */
final class ArrayWalkRuntime
{
    private const ABI_WALK_BUILTIN = '__array_walk__builtin';

    private const ABI_WALK_RECURSIVE_BUILTIN = '__array_walk_recursive__builtin';

    private const ABI_WALK_CLOSURE = '__array_walk__closure';

    private const ABI_WALK_RECURSIVE_CLOSURE = '__array_walk_recursive__closure';

    private const HELPER_PATH = '/ext/standard/ArrayWalkJitHelper.php';

    private const WALK_BUILTIN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayWalkJitHelper::walkWithBuiltin';

    private const WALK_RECURSIVE_BUILTIN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayWalkJitHelper::walkRecursiveWithBuiltin';

    private const WALK_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayWalkJitHelper::walkWithClosure';

    private const WALK_RECURSIVE_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayWalkJitHelper::walkRecursiveWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WALK_BUILTIN_HELPER,
        self::WALK_RECURSIVE_BUILTIN_HELPER,
        self::WALK_CLOSURE_HELPER,
        self::WALK_RECURSIVE_CLOSURE_HELPER,
    ];

    public static function walkInPlaceWithStringBuiltin(
        Context $context,
        JITVariable $array,
        JITVariable $callback
    ): Value {
        if (!ArrayMapCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_walk() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to a variable first'
            );
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call(
            $context->lookupFunction(self::ABI_WALK_BUILTIN),
            $ht,
            $context->constantFromString($name)
        );
        self::storeIfNative($context, $array, $ht);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    public static function walkRecursiveInPlaceWithStringBuiltin(
        Context $context,
        JITVariable $array,
        JITVariable $callback
    ): Value {
        if (!ArrayMapCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_walk_recursive() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to a variable first'
            );
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call(
            $context->lookupFunction(self::ABI_WALK_RECURSIVE_BUILTIN),
            $ht,
            $context->constantFromString($name)
        );
        self::storeIfNative($context, $array, $ht);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    public static function walkInPlaceWithClosure(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        ?JITVariable $userdata
    ): Value {
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_walk() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to a variable first'
            );
        }
        if (null !== $userdata) {
            // Keep NestedJIT bridge for userdata — LLVM path is 2-arg only (#27632).
            self::ensureLinked($context);
            $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
            $valuePtr = $context->getTypeFromString('__value__*');
            $context->builder->call(
                $context->lookupFunction(self::ABI_WALK_CLOSURE),
                $ht,
                JitValueBox::valuePtrFromVariable($context, $callback),
                JitValueBox::valuePtrFromVariable($context, $userdata)
            );
            self::storeIfNative($context, $array, $ht);

            return $context->getTypeFromString('int1')->constInt(1, false);
        }

        // Pure LLVM + NestedClosureInvoke — NestedJIT ArrayWalkJitHelper segfaults (#27632 / #24156).
        NestedClosureInvokeLlvm::ensureLinked($context);
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        ArrayWalkLlvm::walkWithClosure($context, $ht, $callback);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    public static function walkRecursiveInPlaceWithClosure(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        ?JITVariable $userdata
    ): Value {
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_walk_recursive() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to a variable first'
            );
        }
        if (null !== $userdata) {
            throw new \LogicException(
                'array_walk_recursive() userdata is not supported for JIT/AOT in this compiler build (#4913)'
            );
        }

        // Pure LLVM + NestedClosureInvoke — NestedJIT ArrayWalkJitHelper segfaults (#27632 / #24156).
        NestedClosureInvokeLlvm::ensureLinked($context);
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        ArrayWalkLlvm::walkRecursiveWithClosure($context, $ht, $callback);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /** In-place HT mutation; unconditional store corrupts thin AOT value boxes (#27227 / #27632). */
    private static function storeIfNative(Context $context, JITVariable $array, Value $ht): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
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
        // Thin standalone AOT: publish sg_vm_context before NestedJIT of ArrayWalkJitHelper
        // (VmClosureCall needs an active VM context — #27632 / peer #24142).
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
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $bridges = [
            [self::ABI_WALK_BUILTIN, 'array_walk_builtin_bridge_entry', self::WALK_BUILTIN_HELPER],
            [self::ABI_WALK_RECURSIVE_BUILTIN, 'array_walk_recursive_builtin_bridge_entry', self::WALK_RECURSIVE_BUILTIN_HELPER],
            [self::ABI_WALK_CLOSURE, 'array_walk_closure_bridge_entry', self::WALK_CLOSURE_HELPER],
            [self::ABI_WALK_RECURSIVE_CLOSURE, 'array_walk_recursive_closure_bridge_entry', self::WALK_RECURSIVE_CLOSURE_HELPER],
        ];
        foreach ($bridges as [$abi, $entry, $helper]) {
            $paramTypes = \in_array($abi, [self::ABI_WALK_CLOSURE, self::ABI_WALK_RECURSIVE_CLOSURE], true)
                ? [$htPtr, $valuePtr, $valuePtr]
                : [$htPtr, $strPtr];
            JitVmHelperLink::ensureBridge(
                $context,
                $abi,
                $entry,
                $paramTypes,
                $void,
                $helper,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#14933'
            );
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function bridgesComplete(Context $context): bool
    {
        foreach ([
            self::ABI_WALK_BUILTIN,
            self::ABI_WALK_RECURSIVE_BUILTIN,
            self::ABI_WALK_CLOSURE,
            self::ABI_WALK_RECURSIVE_CLOSURE,
        ] as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            self::ABI_WALK_BUILTIN,
            self::ABI_WALK_RECURSIVE_BUILTIN,
            self::ABI_WALK_CLOSURE,
            self::ABI_WALK_RECURSIVE_CLOSURE,
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayWalkRuntime bridge (#14933)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
