<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/memcached class methods (PECL php-memcached; #6099). */
abstract class MemcachedClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmMemcached::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmMemcached::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        return VmMemcached::coerceIntArg($var, $label, $index, $paramName, $default);
    }
}
