<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** DatePeriod::__set_state — php-src ext/date/php_date.c (#22407). */
final class DatePeriodSetState extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__set_state');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::__set_state() requires VM context');
        }
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DatePeriod::__set_state() expects exactly 1 argument, '
                .\count($frame->calledArgs).' given'
            );
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'DatePeriod::__set_state(): Argument #1 ($array) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        $restored = DatePeriodSupport::restoreFromSetStateHash($frame->vmContext, $arg);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($restored): void {
            $ret->object($restored);
        });
    }
}
