<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\Variable;

/** Shared receiver for DatePeriod Iterator methods (#14228). */
abstract class DatePeriodIteratorMethod extends VmClassMethod
{
    protected static function receiver(Frame $frame): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException(static::class.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException(static::class.' called on non-object');
        }

        return DatePeriodSupport::requireDatePeriod($receiver, static::class.'()');
    }
}
