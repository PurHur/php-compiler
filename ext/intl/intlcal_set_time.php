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
 * intlcal_set_time() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_set_time extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_set_time');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_set_time() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_set_time',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $millisArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $millisArg->type) {
            $millis = (float) $millisArg->toInt();
        } elseif (Variable::TYPE_FLOAT === $millisArg->type) {
            $millis = $millisArg->toFloat();
        } else {
            $millis = (float) VmIntlDateFormatter::coerceIntArg(
                $millisArg,
                'intlcal_set_time',
                1,
                'timestamp'
            );
        }
        $ok = VmIntlCalendar::setTime($cal, $millis);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_set_time() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
