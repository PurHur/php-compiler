<?php

declare(strict_types=1);

/**
 * PHP lowering for http_response_code() — single callee {@see HttpResponseCode::APPLY_*}.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\HttpResponseCode as Hrc;
use PHPCompiler\JIT\Builtin\HttpResponseCodeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitHttpResponseCode
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc > 1) {
            throw new \LogicException('http_response_code() accepts at most one argument');
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $nullBoxed = $context->getTypeFromString('__value__*')->constNull();

        if (0 === $argc) {
            $context->builder->call(
                $context->lookupFunction('__phpc_http_response_code_apply'),
                $i8->constInt(Hrc::APPLY_GET, false),
                $i64->constInt(0, false),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        $arg = $args[0];
        $compileTimeCode = HttpResponseCodeJit::compileTimeCodeLong($context, $arg);
        if (null !== $compileTimeCode) {
            if (0 === $compileTimeCode) {
                $context->builder->call(
                    $context->lookupFunction('__phpc_http_response_code_apply'),
                    $i8->constInt(Hrc::APPLY_GET, false),
                    $i64->constInt(0, false),
                    $nullBoxed,
                    $ptr
                );

                return $ptr;
            }
            $context->builder->call(
                $context->lookupFunction('__phpc_http_response_code_apply'),
                $i8->constInt(Hrc::APPLY_SET_LONG, false),
                $i64->constInt($compileTimeCode, false),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_http_response_code_apply'),
                $i8->constInt(Hrc::APPLY_SET_LONG, false),
                $context->helper->loadValue($arg),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            // Z_PARAM_LONG: declare(strict_types=1) → TypeError; else soft-null DEP+coerce (#30019).
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'http_response_code(): Argument #1 ($response_code) must be of type int, null given'
                );

                return $ptr;
            }
            JitIntdiv::emitNullIntDeprecation($context, 'http_response_code', 1, 'response_code');
            $context->builder->call(
                $context->lookupFunction('__phpc_http_response_code_apply'),
                $i8->constInt(Hrc::APPLY_GET, false),
                $i64->constInt(0, false),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            $boxed = $context->helper->loadValue($arg);
            $context->builder->call(
                $context->lookupFunction('__phpc_http_response_code_apply'),
                $i8->constInt(Hrc::APPLY_BOXED, false),
                $i64->constInt(0, false),
                $boxed,
                $ptr
            );

            return $ptr;
        }

        throw new \LogicException('http_response_code() response_code must be an integer in this compiler build');
    }
}
