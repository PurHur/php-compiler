<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for call-time ...$spread — operand guards via ListUnpackJitHelper PHP (#10202).
 *
 * SSOT: {@see \PHPCompiler\VM\CallUnpackSupport}, {@see \PHPCompiler\VM\ListUnpackJitHelper}
 */
final class CallUnpackRuntime
{
    public static function ensureLinked(Context $context): void
    {
        ListUnpackRuntime::ensureLinked($context);
    }
}
