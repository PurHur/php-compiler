<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

abstract class MongodbClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label, string $classLc): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        return VmMongodb::requireReceiver($frame->calledArgs[0], $label, $classLc);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }
}
