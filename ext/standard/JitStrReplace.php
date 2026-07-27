<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for str_replace() — routes through StrReplaceJitHelper PHP (#14779, #23912).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrReplace;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitStrReplace
{
    public static function replace(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        return StringStrReplace::invoke(
            $context,
            $search,
            $replace,
            $subject,
            $caseInsensitive,
            $countSlot
        );
    }
}
