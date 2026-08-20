<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_chown/__compiler_chgrp via ChownLibcRuntime (#9585, #32466).
 *
 * Type always-on leftover dropped (#32466): declareFunction uses getNamedFunction first
 * so a drifted ABI cannot mint __compiler_chown.1 (#31894 / #32122).
 * Thin libc chown/fchownat — NestedJIT ChownJitHelper cannot chown under AOT (#32466 / peer #28995).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownRuntime
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_chown',
        '__compiler_chgrp',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_chown');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementIfMissing($context, '__compiler_chown', self::implementChownBridge(...));
        self::implementIfMissing($context, '__compiler_chgrp', self::implementChgrpBridge(...));
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
        $valuePtr = $context->getTypeFromString('__value__*');

        // getNamedFunction first — leftover Type always-on addFunction without it
        // minted __compiler_chown.1 on ABI drift (#32466 / #32122).
        return $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr, $valuePtr, $i32)
        );
    }

    private static function implementChownBridge(Context $context, LlvmFunction $fn): void
    {
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, '__compiler_chown', static function () use ($context, $fn): void {
            ChownLibcRuntime::emitChown($context, $fn);
        });
    }

    private static function implementChgrpBridge(Context $context, LlvmFunction $fn): void
    {
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, '__compiler_chgrp', static function () use ($context, $fn): void {
            ChownLibcRuntime::emitChgrp($context, $fn);
        });
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ChownRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
