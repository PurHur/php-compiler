<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT multipart POST dispatch — standalone LLVM quarantine only (#7302, #9394).
 *
 * Embed/default standalone refresh uses {@see \PHPCompiler\Web\MultipartParser} PHP SSOT.
 * php-src: main/rfc1867.c
 */
final class StringMultipart
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Standalone AOT: multipart LLVM only when superglobal LLVM refresh is active. */
    public static function ensureStandaloneBodies(Context $context): void
    {
        if (!self::shouldLinkStandaloneLlvm($context)) {
            return;
        }

        StringMultipartStandaloneLlvm::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        if (!self::shouldLinkStandaloneLlvm($context)) {
            return;
        }

        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringMultipartStandaloneLlvm::implement($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function shouldLinkStandaloneLlvm(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }

        foreach (['PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM', 'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER'] as $key) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }
}
