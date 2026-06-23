<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;

/**
 * Isolate CFG block maps during nested php-in-PHP JIT helper compiles (#8559, #9091, #10343).
 */
final class NestedJitCompileScope
{
    private static int $depth = 0;

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $compile
     *
     * @return T
     */
    public static function run(Context $context, callable $compile)
    {
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $savedBlockStorage = $context->scope->blockStorage;
        $savedBlockEntryStorage = $context->scope->blockEntryStorage;
        $context->scope->blockStorage = new \SplObjectStorage();
        $context->scope->blockEntryStorage = new \SplObjectStorage();
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $context->builder->clearInsertionPosition();
            ++self::$depth;

            return $compile();
        } finally {
            --self::$depth;
            $context->scope->blockStorage = $savedBlockStorage;
            $context->scope->blockEntryStorage = $savedBlockEntryStorage;
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
