<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Throwable/Exception::getMessage() — read message property (#4531, #7461, #30895). */
final class ExceptionGetMessage implements Call
{
    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getMessage() requires an object receiver');
        }
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getMessage() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_getmessage_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $context->builder->load($context->constantStringFromString(''))
            );

            return $slot;
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $messageVar = $context->type->object->propertyFetch($obj, $this->declaringRoot, 'message');

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $messageVar)
        );
    }
}
