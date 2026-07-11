<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for fseek() via __compiler_fseek (issue #1191). */
final class JitFseek
{
    /** @return Value
     * (0 on success, -1 on failure) */
    public static function invoke(Context $context, Value $handleLong, Value $offsetLong, Value $whenceLong): Value
    {
        StreamReadRuntime::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $handleLong,
            $offsetLong,
            $whenceLong
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $result);

        return $ptr;
    }
}
