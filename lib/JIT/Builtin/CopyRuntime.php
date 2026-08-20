<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_copy via CopyLibcRuntime stdio (#9585, #32466).
 *
 * Type always-on leftover dropped (#32466): declareFunction uses getNamedFunction first
 * so a drifted ABI cannot mint __compiler_copy.1 (#31894 / #32122).
 * Thin libc fread/fwrite — NestedJIT CopyJitHelper cannot copy under AOT (#32466 / peer #28995).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::copy()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyRuntime
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_copy',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_copy');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementIfMissing($context, '__compiler_copy', self::implementCopyBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        // getNamedFunction first — leftover Type always-on addFunction without it
        // minted __compiler_copy.1 on ABI drift (#32466 / #32122).
        return $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr, $strPtr)
        );
    }

    private static function implementCopyBridge(Context $context, LlvmFunction $fn): void
    {
        // Thin libc stdio — NestedJIT CopyJitHelper cannot copy under AOT (#32466 / peer #28995).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, '__compiler_copy', static function () use ($context, $fn): void {
            CopyLibcRuntime::emit($context, $fn);
        });
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after CopyRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
