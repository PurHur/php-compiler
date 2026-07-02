<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_pad() via ArrayPadJitHelper PHP (#12476).
 *
 * Standalone AOT compiles {@see ArrayPadJitHelper} via JitVmHelperLink (#14286); native literal arrays keep LLVM in {@see ArrayBuiltinHelper::pad()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::padCopy()}
 * php-src: ext/standard/array.c — php_array_pad()
 */
final class ArrayPadRuntime
{
    private const ABI_PAD = '__array_pad__copy';

    private const HELPER_PATH = '/ext/standard/ArrayPadJitHelper.php';

    private const PAD_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPadJitHelper::padCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAD_HELPER,
    ];

    public static function pad(
        Context $context,
        JITVariable $array,
        Value $length,
        JITVariable $value,
        Value $padType
    ): Value {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PAD),
            $ht,
            $length,
            $valuePtr,
            $padType
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
        $probe = $context->module->getNamedFunction(self::ABI_PAD);
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
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PAD,
            'array_pad_bridge_entry',
            [$htPtr, $i64, $valuePtr, $i64],
            $htPtr,
            self::PAD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12476'
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
        $fn = $context->module->getNamedFunction(self::ABI_PAD);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_PAD.' missing after ArrayPadRuntime bridge (#12476)');
        }
        $context->registerFunction(self::ABI_PAD, $fn);
    }
}
