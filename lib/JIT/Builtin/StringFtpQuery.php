<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for ftp query — FtpQueryJitHelper (#31380). */
final class StringFtpQuery
{
    public static function ensureLinked(Context $context): void
    {
        FtpQueryRuntime::ensureLinked($context);
    }
}
