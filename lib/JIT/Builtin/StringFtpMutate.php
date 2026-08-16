<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for ftp mutate — FtpMutateJitHelper (#31427). */
final class StringFtpMutate
{
    public static function ensureLinked(Context $context): void
    {
        FtpMutateRuntime::ensureLinked($context);
    }
}
