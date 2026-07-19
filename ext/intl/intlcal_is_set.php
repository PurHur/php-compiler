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
 * intlcal_is_set() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_is_set extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_is_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_is_set() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_is_set',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlcal_is_set', 1, 'field');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isSet($cal, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_is_set() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
