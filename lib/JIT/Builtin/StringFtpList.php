<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for ftp list — FtpListJitHelper (#31428). */
final class StringFtpList
{
    public static function ensureLinked(Context $context): void
    {
        FtpListRuntime::ensureLinked($context);
    }
}
