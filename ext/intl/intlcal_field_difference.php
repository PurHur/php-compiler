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
 * intlcal_field_difference() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_field_difference extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_field_difference');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_field_difference() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_field_difference',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $tsArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $tsArg->type) {
            $targetMs = (float) $tsArg->toInt();
        } elseif (Variable::TYPE_FLOAT === $tsArg->type) {
            $targetMs = $tsArg->toFloat();
        } else {
            $targetMs = (float) VmIntlDateFormatter::coerceIntArg(
                $tsArg,
                'intlcal_field_difference',
                1,
                'timestamp'
            );
        }
        $field = VmIntlDateFormatter::coerceIntArg(
            $frame->calledArgs[2],
            'intlcal_field_difference',
            2,
            'field'
        );
        $result = VmIntlCalendar::fieldDifference($cal, $targetMs, $field);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_field_difference() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
