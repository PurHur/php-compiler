<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;

/**
 * JIT/AOT link for network service lookups (#5333, #9777).
 *
 * String-return lookups use NetworkServicesJitHelper PHP; name/port lookups keep LLVM tables until AOT is_int parity (#9777 follow-up).
 */
final class StringNetworkServices
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        StringNetworkServicesJit::implement($context);
        self::restoreInsertBlock($context, $restore);
    }

    public static function ensureStringReturnLinked(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        StringNetworkServicesStringReturn::implement($context);
        self::restoreInsertBlock($context, $restore);
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

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
