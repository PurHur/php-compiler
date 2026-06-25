<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for ext/gettext builtins (#3449 phase 2, #8625, #9859).
 *
 * PHP lowering via {@see StringGettextRuntime} → {@see \PHPCompiler\ext\gettext\GettextJitHelper}.
 */
final class StringGettext
{
    public static function ensureLinked(Context $context): void
    {
        StringGettextRuntime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringGettextRuntime::implement($context);
    }
}
