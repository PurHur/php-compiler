<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for phpc_strspn_ex (strspn/strcspn with offset/length).
 */
final class StringStrspn
{
    public static function ensureLinked(Context $context): void
    {
        StringStrspnJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
