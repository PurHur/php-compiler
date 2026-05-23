<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSuperglobalName;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for __compiler_is_superglobal_name() (issue #1056). */
final class JitSuperglobalName
{
    /** @return Value __value__* (native long 0/1) */
    public static function invoke(Context $context, Value $name): Value
    {
        StringSuperglobalName::ensureLinked($context);

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_is_superglobal_name'),
            $name
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $raw);

        return $ptr;
    }
}
