<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for ftp transfer — FtpTransferJitHelper (#31429). */
final class StringFtpTransfer
{
    public static function ensureLinked(Context $context): void
    {
        FtpTransferRuntime::ensureLinked($context);
    }
}
