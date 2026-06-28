<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureState;
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

    /** Accept $this when it is $rootClassLc or a registered subclass (php-src internal inheritance). */
    public static function receiverIsA(Frame $frame, string $rootClassLc, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (!self::objectIsA($frame, $object, $rootClassLc)) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    private static function objectIsA(Frame $frame, ObjectEntry $object, string $rootClassLc): bool
    {
        $entry = $object->class;
        while (true) {
            if (strtolower(ltrim($entry->name, '\\')) === $rootClassLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc) {
                return false;
            }
            if (null === $frame->vmContext || !isset($frame->vmContext->classes[$parentLc])) {
                return $parentLc === $rootClassLc;
            }
            $entry = $frame->vmContext->classes[$parentLc];
        }
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

    /**
     * Copy a callback for deferred invocation; pin ClosureState when present (#13180).
     *
     * @return array{0: Variable, 1: ?ClosureState}
     */
    public static function pinCallback(Variable $callback): array
    {
        $copy = new Variable();
        $copy->copyFrom($callback->resolveIndirect());
        $closure = VmClosureCall::isClosure($copy) ? VmClosureCall::resolve($copy) : null;

        return [$copy, $closure];
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
