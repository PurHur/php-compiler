<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/sqlite3 class methods (php-src ext/sqlite3/sqlite3.c; issue #3434). */
abstract class Sqlite3ClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmSQLite3::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmSQLite3::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        return VmSQLite3::coerceIntArg($var, $label, $index, $paramName, $default);
    }

    protected function boolArg(Variable $var, string $label, int $index, string $paramName, bool $default = false): bool
    {
        return VmSQLite3::coerceBoolArg($var, $label, $index, $paramName, $default);
    }
}
