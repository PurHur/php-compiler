<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for ftp_close() / ftp_quit() — FtpCloseJitHelper (#31377).
 */
final class StringFtpClose
{
    public static function ensureLinked(Context $context): void
    {
        FtpCloseRuntime::ensureLinked($context);
    }
}
