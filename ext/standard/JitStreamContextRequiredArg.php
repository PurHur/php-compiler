<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/** Compile-time guard for required stream-context operands (#19213, ext/standard/streams.c). */
final class JitStreamContextRequiredArg
{
    public static function validate(Context $context, JITVariable $arg, string $function, int $argNum): void
    {
        if (JITVariable::TYPE_NULL !== $arg->type && !$arg->isNullConstant) {
            return;
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            self::typeErrorMessage($function, $argNum, 'null')
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    public static function typeErrorMessage(string $function, int $argNum, string $given): string
    {
        $paramName = VmStreamContext::paramNameForArg($function, $argNum);

        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type resource, %s given',
            $function,
            $argNum,
            $paramName,
            $given
        );
    }
}
