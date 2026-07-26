<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared procedural mysqli link resolution (php-src ext/mysqli/mysqli_api.c; #21825). */
final class MysqliProceduralLink
{
    public static function requireLink(Frame $frame, string $fn, int $minArgs = 1): ObjectEntry
    {
        if (\count($frame->calledArgs) < $minArgs) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least %d argument%s, %d given',
                $fn,
                $minArgs,
                $minArgs === 1 ? '' : 's',
                \count($frame->calledArgs)
            ));
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($mysql) must be of type mysqli, %s given',
                $fn,
                MysqliClassMethod::typeLabelPublic($link)
            ));
        }

        return $link->toObject();
    }

    public static function optionalIntArg(Frame $frame, int $index, int $default = 0): int
    {
        if (\count($frame->calledArgs) <= $index) {
            return $default;
        }
        $resolved = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return $default;
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
            return (int) $resolved->toString();
        }

        return $default;
    }

    /** Strict int arg for php-src zpp "l" / "Ol" (TypeError on non-int-like). */
    public static function requireIntArg(Frame $frame, int $index, string $fn, string $paramName): int
    {
        if (\count($frame->calledArgs) <= $index) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least %d arguments, %d given',
                $fn,
                $index + 1,
                \count($frame->calledArgs)
            ));
        }
        $resolved = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
            return (int) $resolved->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $fn,
            $index + 1,
            $paramName,
            MysqliClassMethod::typeLabelPublic($resolved)
        ));
    }

    public static function optionalStringArg(Frame $frame, int $index): ?string
    {
        if (\count($frame->calledArgs) <= $index) {
            return null;
        }
        $resolved = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }

    public static function boolArg(Frame $frame, int $index, string $fn): bool
    {
        if (\count($frame->calledArgs) <= $index) {
            throw new \ArgumentCountError(\sprintf('%s() expects at least %d arguments, %d given', $fn, $index + 1, \count($frame->calledArgs)));
        }
        $resolved = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return false;
        }

        return (bool) $resolved->toString();
    }

    public static function setBoolReturn(Frame $frame, bool $value): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($value);
        }
    }

    public static function optionValue(Variable $var): mixed
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_INTEGER => $resolved->toInt(),
            Variable::TYPE_FLOAT => $resolved->toFloat(),
            Variable::TYPE_BOOLEAN => $resolved->toBool(),
            Variable::TYPE_NULL => null,
            Variable::TYPE_STRING => $resolved->toString(),
            default => $resolved->toString(),
        };
    }
}
