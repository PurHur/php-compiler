<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for pclose() via __compiler_pclose (#6211 / #33430). */
final class JitPclose
{
    /** @return Value boxed int exit status */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamLifecycleRuntime::ensureLinkedForUserScriptLowering($context);

        $status = $context->builder->call(
            $context->lookupFunction('__compiler_pclose'),
            $handleLong
        );
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $context->builder->sext($status, $i64));

        return $ptr;
    }
}
