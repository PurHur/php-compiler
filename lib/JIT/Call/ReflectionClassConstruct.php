<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class ReflectionClassConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            \PHPCompiler\VM\ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionClass',
                1,
                max(0, count($args) - 1)
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        ReflectionSetup::emitSetClassFromStringVar($context, $obj, $args[1]);
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
