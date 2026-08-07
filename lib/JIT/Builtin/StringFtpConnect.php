<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for ftp_connect() — FtpConnectJitHelper (#27393).
 */
final class StringFtpConnect
{
    public static function ensureLinked(Context $context): void
    {
        FtpConnectRuntime::ensureLinked($context);
    }
}
