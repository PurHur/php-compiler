<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** checkdate() — validate Gregorian calendar date (ext/standard/datetime.c, #3292). */
final class checkdate extends Internal
{
    public function __construct()
    {
        parent::__construct('checkdate');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'checkdate() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        InternalStrictArg::rejectNullInt($frame->calledArgs[0], 'checkdate', 'month', 0);
        InternalStrictArg::rejectNullInt($frame->calledArgs[1], 'checkdate', 'day', 1);
        InternalStrictArg::rejectNullInt($frame->calledArgs[2], 'checkdate', 'year', 2);
        $month = InternalStrictArg::requireBuiltinTypedInt($frame, 0, 'checkdate', 'month')->toInt();
        $day = InternalStrictArg::requireBuiltinTypedInt($frame, 1, 'checkdate', 'day')->toInt();
        $year = InternalStrictArg::requireBuiltinTypedInt($frame, 2, 'checkdate', 'year')->toInt();
        $valid = VmDate::checkdate($month, $day, $year);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($valid): void {
            $ret->bool($valid);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCheckdate::invoke($context, ...$args);
    }
}
