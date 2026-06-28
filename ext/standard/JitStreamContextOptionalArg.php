<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/** Compile-time guard for optional stream-context operands on file builtins (#13248). */
final class JitStreamContextOptionalArg
{
    public static function validate(Context $context, JITVariable $arg, string $function, int $argNum): void
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return;
        }
        if (!self::isObviousNonContextType($arg->type)) {
            return;
        }

        throw new \TypeError(self::typeErrorMessage($function, $argNum, $arg->type));
    }

    private static function isObviousNonContextType(int $type): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $type
            || JITVariable::TYPE_NATIVE_BOOL === $type
            || JITVariable::TYPE_NATIVE_DOUBLE === $type
            || JITVariable::TYPE_STRING === $type
            || JITVariable::TYPE_OBJECT === $type
            || 0 !== ($type & JITVariable::IS_NATIVE_ARRAY);
    }

    private static function typeErrorMessage(string $function, int $argNum, int $type): string
    {
        $given = match ($type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_OBJECT => 'object',
            default => 'array',
        };

        return \sprintf(
            '%s(): Argument #%d ($context) must be of type resource or null, %s given',
            $function,
            $argNum,
            $given
        );
    }
}
