<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for chroot() via ChrootJitHelper PHP (#30558, #3500).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringChroot;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitChroot
{
    /** @return Value true when chroot succeeds */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringChroot::invoke($context, $pathStr);
    }
}
