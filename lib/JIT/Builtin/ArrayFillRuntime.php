<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableFillLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_fill() (#13501, #14297, #27073).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFillJitHelper} bitcasts the
 * `__value__*` fill value to a Variable `__object__*` and stores garbage object slots —
 * gettype object / segfault after `c:main_before_php` (#27073). Call-site LLVM via
 * {@see HashTableFillLlvm} (peer ArrayPadRuntime / #26971, ArrayReverseRuntime / #27067).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\array_fill} /
 * {@see \PHPCompiler\ext\standard\ArrayFillJitHelper}
 * php-src: ext/standard/array.c — php_array_fill()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayFillRuntime
{
    private const ABI_FILL = '__array_fill__copy';

    public static function fill(
        Context $context,
        Value $startIndex,
        Value $count,
        JITVariable $value
    ): Value {
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

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FILL);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitFillBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitFillBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $probe = $context->module->getNamedFunction(self::ABI_FILL);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_FILL,
                $context->context->functionType($htPtr, false, $i64, $i64, $valuePtr)
            );

        $entry = $fn->appendBasicBlock('array_fill_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $filled = HashTableFillLlvm::fill(
            $context,
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($filled);
        $context->registerFunction(self::ABI_FILL, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_FILL);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FILL.' missing after ArrayFillRuntime bridge (#27073)');
        }
        $context->registerFunction(self::ABI_FILL, $fn);
    }
}
