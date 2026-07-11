<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * DatePeriod::createFromISO8601String() — static VM (#7296, ext/date/php_date.c).
 */
final class DatePeriodCreateFromISO8601String extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromISO8601String');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::createFromISO8601String() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'DatePeriod::createFromISO8601String() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }

        $spec = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'DatePeriod::createFromISO8601String',
            0,
            'specification'
        );
        $options = 0;
        if (2 === $argc) {
            $options = InternalStrictArg::requireBuiltinTypedInt(
                $frame,
                1,
                'DatePeriod::createFromISO8601String',
                'options'
            )->toInt();
        }

        $period = DatePeriodSupport::createFromISO8601String($frame->vmContext, $spec, $options);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($period): void {
            $ret->object($period);
        });
    }
}
