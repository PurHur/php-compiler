<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringNetInterfacesJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for net_get_interfaces() via StringNetInterfacesJit (#6106). */
final class JitNetGetInterfaces
{
    public static function invoke(Context $context): Value
    {
        StringNetInterfacesJit::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_net_get_interfaces'),
            $ptr
        );

        return $ptr;
    }
}
