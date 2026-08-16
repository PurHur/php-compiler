<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for ftp_login() — FtpLoginJitHelper (#31378).
 */
final class StringFtpLogin
{
    public static function ensureLinked(Context $context): void
    {
        FtpLoginRuntime::ensureLinked($context);
    }
}
