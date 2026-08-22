<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\BuiltinRegistry;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\ArrayReduceLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_reduce() (#12646, #14979, #33721).
 *
 * - Closures → {@see ArrayReduceLlvm::reduceWithClosure} (user-module NestedClosureInvoke)
 * - User-function string names → {@see ArrayReduceLlvm::reduceWithUserFunction}
 * - Stdlib string builtins → NestedJIT {@see ArrayReduceJitHelper::reduceWithBuiltin}
 *
 * Do not NestedJIT a closure helper alongside string callbacks — that registers
 * NestedClosureInvoke with zero Closure candidates (#24156 / #33721).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\array_reduce}
 * php-src: ext/standard/array.c — php_array_reduce()
 */
final class ArrayReduceRuntime
{
    private const ABI_REDUCE_BUILTIN = '__array_reduce__builtin';

    private const HELPER_PATH = '/ext/standard/ArrayReduceJitHelper.php';

    private const REDUCE_BUILTIN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReduceJitHelper::reduceWithBuiltin';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REDUCE_BUILTIN_HELPER,
    ];

    public static function reduce(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        ?JITVariable $initial
    ): Value {
        if ($callback->isNullConstant) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (!ArrayReduceCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $initialPtr = self::initialPtr($context, $initial);
        if (ArrayReduceCallbackPolicy::isClosureJitLowerable($callback)) {
            // Pure LLVM + caller closureCall — NestedJIT RuntimeIndirect with ≥3 Closures
            // intermittently free(): invalid pointer alongside ArrayMapLlvm (#24156).
            NestedClosureInvokeLlvm::ensureLinked($context);

            return ArrayReduceLlvm::reduceWithClosure($context, $ht, $callback, $initialPtr);
        }

        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }

        // Pure LLVM for all compile-time string callbacks — NestedJIT reduceWithBuiltin
        // segfaults under thin AOT after carry init (#33721). Closures already use Llvm.
        if (null !== BuiltinRegistry::resolve($name)) {
            return ArrayReduceLlvm::reduceWithBuiltin($context, $ht, $name, $initialPtr);
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                ArrayReduceCallbackPolicy::invalidStringCallbackTypeError($name)
            );
        }

        return ArrayReduceLlvm::reduceWithUserFunction($context, $ht, $name, $initialPtr);
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
        // Thin standalone AOT: publish sg_vm_context before NestedJIT (#24117 / peer #17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);

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
        // Builtin-only NestedJIT — no VmClosureInvoke / NestedClosureInvoke (#33721).
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::HELPER_PATH],
            self::COMPILED_HELPERS,
            '#33721'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REDUCE_BUILTIN,
            'array_reduce_builtin_bridge_entry',
            [$htPtr, $strPtr, $valuePtr],
            $valuePtr,
            self::REDUCE_BUILTIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14979'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function initialPtr(Context $context, ?JITVariable $initial): Value
    {
        if (null === $initial) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }

        return JitValueBox::valuePtrFromVariable($context, $initial);
    }

    private static function bridgesComplete(Context $context): bool
    {
        $probe = $context->module->getNamedFunction(self::ABI_REDUCE_BUILTIN);

        return null !== $probe && 0 !== $probe->countBasicBlocks();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_REDUCE_BUILTIN);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_REDUCE_BUILTIN.' missing after ArrayReduceRuntime bridge (#14979 / #33721)');
        }
        $context->registerFunction(self::ABI_REDUCE_BUILTIN, $fn);
    }
}
