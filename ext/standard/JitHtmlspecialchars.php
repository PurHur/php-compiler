<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for htmlspecialchars() with ENT_QUOTES / ENT_COMPAT flags (UTF-8).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringHtmlspecialchars;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitHtmlspecialchars
{
    public static function escape(Context $context, Value $strPtr, Value $flags): Value
    {
        return StringHtmlspecialchars::invoke($context, $strPtr, $flags);
    }

    /** UTF-8 + double_encode (int64 0/1) — #27290. */
    public static function escapeEx(Context $context, Value $strPtr, Value $flags, Value $doubleEncode): Value
    {
        return StringHtmlspecialchars::invokeEx($context, $strPtr, $flags, $doubleEncode);
    }
}
