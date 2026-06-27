<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_reduce() string-builtin path via ArrayReduceJitHelper PHP (#12646).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildReduceArray()}.
 * Closure callbacks still use LLVM until a VM bridge exists.
 * SSOT: {@see \PHPCompiler\ext\standard\array_reduce}
 * php-src: ext/standard/array.c — php_array_reduce()
 */
final class ArrayReduceRuntime
{
    private const ABI_REDUCE = '__array_reduce__builtin';

    private const HELPER_PATH = '/ext/standard/ArrayReduceJitHelper.php';

    private const REDUCE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReduceJitHelper::reduceWithBuiltin';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REDUCE_HELPER,
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
        if (ArrayReduceCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayBuiltinHelper::buildReduceArrayWithClosure($context, $array, $callback, $initial);
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return ArrayBuiltinHelper::buildReduceArray($context, $array, $callback, $initial);
        }

        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $initialPtr = self::initialPtr($context, $initial);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REDUCE),
            $ht,
            $context->constantFromString($name),
            $initialPtr
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

        $probe = $context->module->getNamedFunction(self::ABI_REDUCE);
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
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REDUCE,
            'array_reduce_bridge_entry',
            [$htPtr, $strPtr, $valuePtr],
            $valuePtr,
            self::REDUCE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12646'
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_REDUCE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_REDUCE.' missing after ArrayReduceRuntime bridge (#12646)');
        }
        $context->registerFunction(self::ABI_REDUCE, $fn);
    }
}
