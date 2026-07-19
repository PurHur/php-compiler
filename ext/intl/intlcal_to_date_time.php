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
 * intlcal_to_date_time() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_to_date_time extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_to_date_time');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_to_date_time() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_to_date_time',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $dt = VmIntlCalendar::toDateTime($cal, $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $dt) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($dt);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_to_date_time() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
