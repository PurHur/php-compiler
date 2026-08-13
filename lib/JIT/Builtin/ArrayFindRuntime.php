<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayFindCallbackPolicy;
use PHPCompiler\JIT\ArrayFindLlvm;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_find family via ArrayFindJitHelper PHP (#14842, #17547, #17674).
 *
 * String callbacks route through NestedJIT {@see ArrayFindJitHelper}; Closures use
 * {@see ArrayFindLlvm} under thin AOT (#26824, peer ArrayReduceLlvm).
 * SSOT: {@see \PHPCompiler\ext\standard\array_find} and siblings.
 * php-src: ext/standard/array.c
 */
final class ArrayFindRuntime
{
    private const ABI_FIND = '__array_find__named';

    private const ABI_FIND_CLOSURE = '__array_find__closure';

    private const HELPER_PATH = '/ext/standard/ArrayFindJitHelper.php';

    private const WALK_NAMED_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFindJitHelper::walkWithNamedCallback';

    private const WALK_CLOSURE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFindJitHelper::walkWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WALK_NAMED_HELPER,
        self::WALK_CLOSURE_HELPER,
    ];

    public static function walk(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        int $mode,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
    ): Value {
        if (ArrayFindCallbackPolicy::isJitNullCallback($callback)) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                ArrayFindCallbackPolicy::invalidCallbackTypeError('array_find')
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'array_find_null_cb_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (!self::isStringCallback($callback)) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $modeVal = $context->constantFromInteger($mode, 'int64');
        $unaryKeyI1 = $context->constantFromBool($unaryInternalUsesKey);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FIND),
            $ht,
            $context->constantFromString($name),
            $modeVal,
            $strictI1,
            $unaryKeyI1
        );
    }

    public static function walkClosure(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        int $mode,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
    ): Value {
        if (ArrayFindCallbackPolicy::isJitNullCallback($callback)) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                ArrayFindCallbackPolicy::invalidCallbackTypeError('array_find')
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'array_find_closure_null_cb_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (!ArrayFindCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }

        // Pure LLVM + caller closureCall — NestedJIT of ArrayFindJitHelper stubs
        // VmClosureCall under thin AOT (null/false), peer ArrayReduceLlvm (#26824 / #24156).
        NestedClosureInvokeLlvm::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return ArrayFindLlvm::walkWithClosure($context, $ht, $callback, $mode);
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
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FIND,
            'array_find_bridge_entry',
            [$htPtr, $strPtr, $i64, $i1, $i1],
            $valuePtr,
            self::WALK_NAMED_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17674'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FIND_CLOSURE,
            'array_find_closure_bridge_entry',
            [$htPtr, $valuePtr, $i64, $i1, $i1],
            $valuePtr,
            self::WALK_CLOSURE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17547'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function isStringCallback(JITVariable $callback): bool
    {
        return ArrayReduceCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    private static function bridgesComplete(Context $context): bool
    {
        foreach ([self::ABI_FIND, self::ABI_FIND_CLOSURE] as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_FIND, self::ABI_FIND_CLOSURE] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayFindRuntime bridge (#17674)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
