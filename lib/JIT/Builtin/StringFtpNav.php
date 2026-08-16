<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for ftp navigation — FtpNavJitHelper (#31379). */
final class StringFtpNav
{
    public static function ensureLinked(Context $context): void
    {
        FtpNavRuntime::ensureLinked($context);
    }
}
