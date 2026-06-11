<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
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
        $month = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'checkdate', 1, 'month');
        $day = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'checkdate', 2, 'day');
        $year = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'checkdate', 3, 'year');
        $valid = VmDate::checkdate($month, $day, $year);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($valid): void {
            $ret->bool($valid);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('checkdate() is not implemented for JIT in this compiler build (issue #3292)');
    }
}
