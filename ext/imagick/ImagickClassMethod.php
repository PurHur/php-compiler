<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/imagick class methods (php-src ext/imagick/imagick_class.c; #6235). */
abstract class ImagickClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmImagick::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmImagick::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        return VmImagick::coerceIntArg($var, $label, $index, $paramName, $default);
    }

    protected function floatArg(Variable $var, string $label, int $index, string $paramName, float $default = 1.0): float
    {
        return VmImagick::coerceFloatArg($var, $label, $index, $paramName, $default);
    }

    protected function boolArg(Variable $var, string $label, int $index, string $paramName, bool $default = false): bool
    {
        return VmImagick::coerceBoolArg($var, $label, $index, $paramName, $default);
    }
}
