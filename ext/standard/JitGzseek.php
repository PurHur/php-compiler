<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for gzseek() via __compiler_gzseek (#14585). */
final class JitGzseek
{
    /** @return Value (0 on success, -1 on failure) */
    public static function invoke(Context $context, Value $handleLong, Value $offsetLong, Value $whenceLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_gzseek'),
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
