<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_fill() via ArrayFillJitHelper PHP (#13501).
 *
 * Standalone AOT keeps LLVM in {@see HashTableHelper::buildArrayFill()}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_fill}
 * php-src: ext/standard/array.c — php_array_fill()
 */
final class ArrayFillRuntime
{
    private const ABI_FILL = '__array_fill__copy';

    private const HELPER_PATH = '/ext/standard/ArrayFillJitHelper.php';

    private const FILL_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFillJitHelper::fillCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FILL_HELPER,
    ];

    public static function fill(
        Context $context,
        Value $startIndex,
        Value $count,
        JITVariable $value
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $sizeT = $context->getTypeFromString('size_t');

            return HashTableHelper::buildArrayFill(
                $context,
                $context->builder->truncOrBitCast($startIndex, $sizeT),
                $context->builder->truncOrBitCast($count, $sizeT),
                $value
            );
        }

        self::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FILL),
            $startIndex,
            $count,
            $valuePtr
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

        $probe = $context->module->getNamedFunction(self::ABI_FILL);
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
            self::ABI_FILL,
            'array_fill_bridge_entry',
            [$i64, $i64, $valuePtr],
            $htPtr,
            self::FILL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13501'
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
        $fn = $context->module->getNamedFunction(self::ABI_FILL);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FILL.' missing after ArrayFillRuntime bridge (#13501)');
        }
        $context->registerFunction(self::ABI_FILL, $fn);
    }
}
