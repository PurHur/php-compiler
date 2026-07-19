<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intlcal_add() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_add extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_add() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_add',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlcal_add', 1, 'field');
        $amount = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'intlcal_add', 2, 'value');
        $ok = VmIntlCalendar::add($cal, $field, $amount);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_add() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
