<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/zip class methods (php-src ext/zip/php_zip.c; issue #6414). */
abstract class ZipClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmZipArchive::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmZipArchive::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        return VmZipArchive::coerceIntArg($var, $label, $index, $paramName, $default);
    }

    protected function boolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        return VmZipArchive::coerceBoolArg($var, $label, $index, $paramName);
    }

    protected function floatArg(Variable $var, string $label, int $index, string $paramName): float
    {
        return VmZipArchive::coerceFloatArg($var, $label, $index, $paramName);
    }
}
