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
 * intlcal_before() — procedural IntlCalendar::before
 * (php-src calendar_methods.c / calendar.stub.php; #20897).
 */
final class intlcal_before extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_before');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_before() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_before',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type
            || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError(\sprintf(
                'intlcal_before(): Argument #2 ($other) must be of type IntlCalendar, %s given',
                Variable::TYPE_OBJECT === $other->type
                    ? $other->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::before($receiver->toObject(), $other->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_before() is not implemented for JIT in this compiler build (issue #20897)');
    }
}
