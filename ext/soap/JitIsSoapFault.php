<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Value;

/**
 * is_soap_fault() JIT — SoapFault instanceof check (php-src ext/soap/soap.c; #26167).
 */
final class JitIsSoapFault
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'is_soap_fault() expects exactly 1 argument, '.$argc.' given'
            );

            return $context->constantFromBool(false);
        }

        $arg = $args[0];
        if (!\in_array($arg->type, [JITVariable::TYPE_OBJECT, JITVariable::TYPE_VALUE], true)) {
            return $context->constantFromBool(false);
        }

        return $context->helper->loadValue(
            ReflectionBuiltinHelper::emitInstanceOf($context, $arg, 'SoapFault')
        );
    }
}
