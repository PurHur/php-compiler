<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/** VmActiveContextJitHelper::resolve() — load sg_vm_context (#17391). */
final class VmActiveContextResolve implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        unset($args);

        return $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
    }
}
