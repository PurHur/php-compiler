<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for ext/gettext builtins (#3449 phase 2, #8625).
 *
 * PHP lowering via {@see StringGettextJit}; libc gettext when linked, msgid fallback otherwise.
 */
final class StringGettext
{
    public static function ensureLinked(Context $context): void
    {
        StringGettextJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringGettextJit::implement($context);
    }
}
