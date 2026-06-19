<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for ob_gzhandler() (issue #4655, #8818).
 *
 * PHP lowering via {@see ObGzhandlerJitRuntime} + {@see \PHPCompiler\ext\standard\ObGzhandlerJitHelper}.
 */
final class ObGzhandler
{
    public static function ensureLinked(Context $context): void
    {
        StringZlib::ensureLinked($context);
        ObGzhandlerJitRuntime::implement($context);
    }
}
