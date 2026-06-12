<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared helpers for SPL iterator VM builtins (#6593). */
final class SplIteratorSupport
{
    public static function receiver(Frame $frame, string $classLc, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (strtolower($object->class->name) !== $classLc) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    public static function setReturnBool(Frame $frame, bool $value): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($value);
    }

    public static function setReturnNull(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->null();
    }

    public static function copyReturnFrom(Frame $frame, Variable $source): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($source->resolveIndirect());
    }

    public static function requireArrayArg(Variable $var, string $function, int $argIndex): \PHPCompiler\VM\HashTable
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($array) must be of type array, '
                .self::typeLabel($resolved).' given'
            );
        }

        return $resolved->toArray();
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
