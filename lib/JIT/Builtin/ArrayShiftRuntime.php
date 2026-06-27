<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_shift() via ArrayShiftJitHelper PHP (#12672).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::shiftFirst()}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_shift}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_shift)
 */
final class ArrayShiftRuntime
{
    private const ABI_SHIFT = '__array_shift__first';

    private const HELPER_PATH = '/ext/standard/ArrayShiftJitHelper.php';

    private const SHIFT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayShiftJitHelper::shift';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SHIFT_HELPER,
    ];

    public static function shift(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::shiftFirst($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SHIFT),
            $ht
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

        $probe = $context->module->getNamedFunction(self::ABI_SHIFT);
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
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SHIFT,
            'array_shift_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::SHIFT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12672'
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
        $fn = $context->module->getNamedFunction(self::ABI_SHIFT);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SHIFT.' missing after ArrayShiftRuntime bridge (#12672)');
        }
        $context->registerFunction(self::ABI_SHIFT, $fn);
    }
}
