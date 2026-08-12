<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSyslog;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for openlog()/syslog()/closelog() (#3676 JIT/AOT). */
final class JitSyslog
{
    public static function openlog(
        Context $context,
        JITVariable $ident,
        JITVariable $option,
        JITVariable $facility
    ): Value {
        StringSyslog::ensureLinked($context);

        // Soft-null outside strict_types; strict → TypeError (#30372).
        // Early return after compile-time null TypeError — no syslog helper after abort
        // (AOT module verify: terminator mid-block; peer getopt #30358).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $ident->type || ($ident->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $ident,
                'openlog',
                0,
                'prefix',
                'string',
                null,
                false
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $identStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $ident,
            'openlog',
            0,
            'prefix',
            'string',
            null,
            false
        );
        $opt = self::lowerI32($context, $option, 'openlog', 1, 'option');
        $fac = self::lowerI32($context, $facility, 'openlog', 2, 'facility');

        $context->builder->call(
            $context->lookupFunction('__compiler_syslog_openlog'),
            $identStr,
            $opt,
            $fac
        );

        return JitReadline::invokeBool($context, true);
    }

    public static function syslog(Context $context, JITVariable $priority, JITVariable $message): Value
    {
        StringSyslog::ensureLinked($context);

        $prio = self::lowerI32($context, $priority, 'syslog', 0, 'priority');
        $msgStr = JitStringBuiltinArg::lower($context, $message, 'syslog', 1, 'message');

        $context->builder->call(
            $context->lookupFunction('__compiler_syslog_write'),
            $prio,
            $msgStr
        );

        return JitReadline::invokeBool($context, true);
    }

    public static function closelog(Context $context): Value
    {
        StringSyslog::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_syslog_closelog'));

        return JitReadline::invokeBool($context, true);
    }

    public static function emitArgumentCountError(Context $context, string $message): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError($context, $message);
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }

    private static function lowerI32(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->builder->trunc($context->helper->loadValue($arg), $i32);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->trunc(
                $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $arg->value
                ),
                $i32
            );
        }

        throw new \LogicException(
            $function.'(): Argument #'.($argIndex + 1).' ($'.$paramName.') must be of type int in this compiler build'
        );
    }
}
