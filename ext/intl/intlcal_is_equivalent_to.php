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
 * intlcal_is_equivalent_to() — procedural IntlCalendar::isEquivalentTo
 * (php-src calendar_methods.c / calendar.stub.php; #20896).
 */
final class intlcal_is_equivalent_to extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_is_equivalent_to');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_is_equivalent_to() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_is_equivalent_to',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type
            || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError(\sprintf(
                'intlcal_is_equivalent_to(): Argument #2 ($other) must be of type IntlCalendar, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isEquivalentTo($cal, $other->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_is_equivalent_to() is not implemented for JIT in this compiler build (issue #20896)');
    }
}