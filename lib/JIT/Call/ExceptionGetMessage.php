<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Throwable/Exception::getMessage() — read message property (#4531, #7461). */
final class ExceptionGetMessage implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getMessage() requires an object receiver');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $messageVar = $context->type->object->propertyFetch($obj, 'Exception', 'message');

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $messageVar)
        );
    }
}
