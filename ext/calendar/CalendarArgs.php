<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Shared VM argument parsing for ext/calendar builtins (php-src ext/calendar/calendar.c). */
final class CalendarArgs
{
    public static function requireInt(Frame $frame, string $fn, int $position, string $name): int
    {
        $var = $frame->calledArgs[$position - 1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $position,
                $name,
                self::vmTypeName($var->type)
            ));
        }

        return $var->toInt();
    }

    public static function optionalInt(Frame $frame, string $fn, int $position, string $name): ?int
    {
        if (\count($frame->calledArgs) < $position) {
            return null;
        }

        return self::requireInt($frame, $fn, $position, $name);
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
