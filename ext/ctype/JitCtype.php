<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\JIT\Builtin\CtypeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for ctype_* (php-src ext/ctype/ctype.c; #7253, #9234). */
final class JitCtype
{
    public static function invoke(Context $context, JITVariable $arg, string $function): Value
    {
        $spec = VmCtype::specForFunction($function);
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return self::boolConst(
                $context,
                VmCtype::checkString($literal, $spec['kind'])
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return self::boolConst(
                $context,
                VmCtype::checkInt(
                    (int) $arg->compileTimeLong,
                    $spec['kind'],
                    $spec['allow_digits'],
                    $spec['allow_minus']
                )
            );
        }

        CtypeRuntime::ensureLinked($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $kindConst = $i8->constInt($spec['kind'], false);
        $allowDigits = $i8->constInt($spec['allow_digits'] ? 1 : 0, false);
        $allowMinus = $i8->constInt($spec['allow_minus'] ? 1 : 0, false);

        if (JITVariable::TYPE_STRING === $arg->type) {
            $result = $context->builder->call(
                $context->lookupFunction('__phpc_ctype_check_string'),
                $arg->value,
                $kindConst
            );

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $result = $context->builder->call(
                $context->lookupFunction('__phpc_ctype_check_long'),
                $arg->value,
                $kindConst,
                $allowDigits,
                $allowMinus
            );

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $result = $context->builder->call(
                $context->lookupFunction('__phpc_ctype_from_value'),
                JitValueBox::valuePtrFromVariable($context, $arg),
                $kindConst,
                $allowDigits,
                $allowMinus
            );

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }

        return self::boolConst($context, false);
    }

    private static function boolConst(Context $context, bool $value): Value
    {
        return $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false);
    }
}
