<?php

declare(strict_types=1);

/**
 * PHP lowering for session_id() — single callee {@see SessionId::APPLY_*}.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SessionId as Sid;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitSessionId
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException('session_id() accepts at most one argument');
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $nullBoxed = $context->getTypeFromString('__value__*')->constNull();

        if (0 === $argc) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_GET, false),
                $nullStr,
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        $arg = $args[0];
        if (JITVariable::TYPE_STRING === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_SET, false),
                $context->helper->loadValue($arg),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_NULL === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_GET, false),
                $nullStr,
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            $boxed = $context->helper->loadValue($arg);
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_BOXED, false),
                $nullStr,
                $boxed,
                $ptr
            );

            return $ptr;
        }

        throw new \LogicException('session_id() id must be a string in this compiler build');
    }
}
