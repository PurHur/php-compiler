<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
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
 * JIT/AOT link for array_reduce() via ArrayReduceJitHelper PHP (#12646, #14979).
 *
 * Standalone AOT compiles {@see ArrayReduceJitHelper} via JitVmHelperLink bridge (#14438); closure callbacks route through PHP (#14979).
 * SSOT: {@see \PHPCompiler\ext\standard\array_reduce}
 * php-src: ext/standard/array.c — php_array_reduce()
 */
final class ArrayReduceRuntime
{
    private const ABI_REDUCE_BUILTIN = '__array_reduce__builtin';

    private const ABI_REDUCE_CLOSURE = '__array_reduce__closure';

    private const HELPER_PATH = '/ext/standard/ArrayReduceJitHelper.php';

    private const CLOSURE_INVOKE_PATH = '/ext/standard/VmClosureInvoke.php';

    private const REDUCE_BUILTIN_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReduceJitHelper::reduceWithBuiltin';

    private const REDUCE_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReduceJitHelper::reduceWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REDUCE_BUILTIN_HELPER,
        self::REDUCE_CLOSURE_HELPER,
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
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $initialPtr = self::initialPtr($context, $initial);
        if (ArrayReduceCallbackPolicy::isClosureJitLowerable($callback)) {
            if ($context->isThinStandaloneAotMain()) {
                throw new \LogicException(ArrayReduceCallbackPolicy::thinAotClosureRejectionMessage());
            }
            return $context->builder->call(
                $context->lookupFunction(self::ABI_REDUCE_CLOSURE),
                $ht,
                JitValueBox::valuePtrFromVariable($context, $callback),
                $initialPtr
            );
        }

        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REDUCE_BUILTIN),
            $ht,
            $context->constantFromString($name),
            $initialPtr
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
        // Thin standalone AOT: publish sg_vm_context before NestedJIT (#24117 / peer #17391).
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
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::HELPER_PATH, self::CLOSURE_INVOKE_PATH],
            self::COMPILED_HELPERS,
            '#24156'
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REDUCE_CLOSURE,
            'array_reduce_closure_bridge_entry',
            [$htPtr, $valuePtr, $valuePtr],
            $valuePtr,
            self::REDUCE_CLOSURE_HELPER,
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
        foreach ([self::ABI_REDUCE_BUILTIN, self::ABI_REDUCE_CLOSURE] as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_REDUCE_BUILTIN, self::ABI_REDUCE_CLOSURE] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayReduceRuntime bridge (#14979)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
