<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\standard\VmCallable;

final class VmPcntlArg
{
    public static function coerceIntArg(Variable $arg, string $function, int $position, string $name): int
    {
        return VmPosix::coerceIntArg($arg, $function, $position, $name);
    }

    public static function requireCallable(Context $context, Variable $callable, string $function, int $position): void
    {
        if (EnumCaseSupport::isEnumCaseVariable($callable->resolveIndirect())) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError());
        }
        if (!VmCallable::isCallable($context, $callable)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError());
        }
    }
}
