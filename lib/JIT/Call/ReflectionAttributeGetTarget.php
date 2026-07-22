<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionAttribute::getTarget() — JIT/AOT (#22044, ext/reflection/php_reflection.c).
 */
final class ReflectionAttributeGetTarget implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $target = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionAttribute',
            ReflectionSupport::PROP_ATTR_TARGET
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $target);

        return $slot;
    }
}
