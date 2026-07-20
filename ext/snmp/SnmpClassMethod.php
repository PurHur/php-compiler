<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/snmp class methods (php-src snmp_class.c; #6070). */
abstract class SnmpClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmSnmp::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmSnmp::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName): int
    {
        return VmSnmp::coerceIntArg($var, $label, $index, $paramName);
    }
}