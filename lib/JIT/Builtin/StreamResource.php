<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamResourceKernel;
use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for get_resource_type()/get_resources() (#5179, #3646, #6821, #19613). */
final class StreamResource
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamResourceKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
