<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;

/** Shared argument parsing for shmop_* builtins (#3344). */
final class ShmopArgs
{
    public static function requireAvailable(string $fn): void
    {
        if (!VmShmop::available()) {
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

    public static function parseMode(Frame $frame, string $fn, int $index = 1): string
    {
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[$index], $fn, $index, 'mode');
        if (!\in_array($mode, ['a', 'w', 'c', 'n'], true)) {
            throw new \ValueError($fn.'(): Argument #'.($index + 1).' ($mode) must be a valid access mode');
        }

        return $mode;
    }

    public static function parsePermissions(Frame $frame, string $fn, int $index = 2): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                'permissions',
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    public static function parseSize(Frame $frame, string $fn, int $index = 3): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                'size',
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    public static function parseOffset(Frame $frame, string $fn, int $index): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                'offset',
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    /** Third arg of shmop_read — php-src stub name is $size (not legacy $count; #24391). */
    public static function parseCount(Frame $frame, string $fn, int $index): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $fn,
                $index + 1,
                'size',
                VmStreamArg::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    public static function parseData(Frame $frame, string $fn, int $index): string
    {
        return VmString::coerceStringBuiltinArg($frame->calledArgs[$index], $fn, $index, 'data');
    }

    public static function parseShmop(Frame $frame, string $fn, int $index = 0): ObjectEntry
    {
        $var = $frame->calledArgs[$index];
        $resolved = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($shmop) must be of type Shmop, %s given',
                $fn,
                $index + 1,
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($shmop) must be of type Shmop, %s given',
                $fn,
                $index + 1,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if (!VmShmop::isShmopObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($shmop) must be of type Shmop, %s given',
                $fn,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function requireHost(ObjectEntry $object, string $fn): object
    {
        $host = VmShmop::hostForObject($object);
        if (null === $host) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($shmop) must be of type Shmop, %s given',
                $fn,
                $object->class->name
            ));
        }

        return $host;
    }
}
