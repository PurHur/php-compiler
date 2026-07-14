<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** Shared argument parsing for sem_* builtins (#3704). */
final class SemArgs
{
    public static function requireAvailable(string $fn): void
    {
        if (!VmSem::available()) {
            throw new \Error($fn.'() is not available in this compiler build');
        }
    }

    public static function parseKey(Frame $frame, string $fn, int $index = 0): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                'key',
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    public static function parseOptionalInt(Frame $frame, int $index, string $fn, string $param): ?int
    {
        if (!isset($frame->calledArgs[$index])) {
            return null;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                $param,
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    public static function parseOptionalBool(Frame $frame, int $index, string $fn, string $param): ?bool
    {
        if (!isset($frame->calledArgs[$index])) {
            return null;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_BOOL !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type bool, %s given',
                $fn,
                $index + 1,
                $param,
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toBool();
    }

    public static function parseSemaphore(Frame $frame, string $fn, int $index = 0): ObjectEntry
    {
        $var = $frame->calledArgs[$index];
        $resolved = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($semaphore) must be of type SysvSemaphore, %s given',
                $fn,
                $index + 1,
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($semaphore) must be of type SysvSemaphore, %s given',
                $fn,
                $index + 1,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if (!VmSem::isSysvSemaphoreObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($semaphore) must be of type SysvSemaphore, %s given',
                $fn,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function requireHost(ObjectEntry $object, string $fn): object
    {
        $host = VmSem::hostForObject($object);
        if (null === $host) {
            throw new \ValueError($fn.'(): Argument #1 ($semaphore) has already been detached');
        }

        return $host;
    }
}
