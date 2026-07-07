<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayFindCallbackPolicy;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_find family string-builtin path via ArrayFindJitHelper PHP (#14842).
 *
 * Closure and compile-unit user-function callbacks still use {@see \PHPCompiler\JIT\ArrayFindHelper} LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\array_find} and siblings.
 * php-src: ext/standard/array.c
 */
final class ArrayFindRuntime
{
    private const ABI_FIND = '__array_find__builtin';

    private const HELPER_PATH = '/ext/standard/ArrayFindJitHelper.php';

    private const WALK_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFindJitHelper::walkWithBuiltin';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WALK_HELPER,
    ];

    public static function walk(
        Context $context,
        JITVariable $array,
        JITVariable $callback,
        int $mode
    ): Value {
        if ($callback->isNullConstant) {
            throw new \TypeError(ArrayFindCallbackPolicy::invalidCallbackTypeError('array_find'));
        }
        if (!self::isStringBuiltinCallback($callback)) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $modeVal = $context->constantFromInteger($mode, 'int64');

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FIND),
            $ht,
            $context->constantFromString($name),
            $modeVal
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
        $probe = $context->module->getNamedFunction(self::ABI_FIND);
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
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FIND,
            'array_find_bridge_entry',
            [$htPtr, $strPtr, $i64],
            $valuePtr,
            self::WALK_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14842'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function isStringBuiltinCallback(JITVariable $callback): bool
    {
        return ArrayMapCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_FIND);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FIND.' missing after ArrayFindRuntime bridge (#14842)');
        }
        $context->registerFunction(self::ABI_FIND, $fn);
    }
}
