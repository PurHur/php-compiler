<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSuperglobalName;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for __compiler_is_superglobal_name() (issue #1056, #33235).
 *
 * Call-site {@see StringSuperglobalName::ensureLinked} before lookup (Type always-on shell dropped).
 */
final class JitSuperglobalName
{
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
