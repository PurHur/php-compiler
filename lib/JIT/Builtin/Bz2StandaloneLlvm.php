<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for bz2 ABI when nested Bz2JitHelper cannot compile (#8868).
 *
 * Returns null __string__* (failure) — matches unavailable libbz2/FFI in standalone link.
 */
final class Bz2StandaloneLlvm
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_bzcompress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::implementNullReturn($context, '__compiler_bzcompress', 3);
        self::implementNullReturn($context, '__compiler_bzdecompress', 2);
        self::registerLinkedRuntime($context);
    }

    private static function implementNullReturn(Context $context, string $name, int $paramCount): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $params = '__compiler_bzcompress' === $name
            ? [$strPtr, $i64, $i64]
            : [$strPtr, $i64];

        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, ...$params)
        );
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_bzcompress', '__compiler_bzdecompress'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after Bz2StandaloneLlvm (#8868)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
