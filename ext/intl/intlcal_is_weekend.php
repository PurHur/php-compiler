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
 * intlcal_is_weekend() — procedural IntlCalendar::isWeekend
 * (php-src calendar_methods.c / calendar.stub.php; #20895).
 */
final class intlcal_is_weekend extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_is_weekend');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_is_weekend() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_is_weekend',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $timestampMs = null;
        if (2 === $argc) {
            $tsArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tsArg->type) {
                if (Variable::TYPE_INTEGER === $tsArg->type) {
                    $timestampMs = (float) $tsArg->toInt();
                } elseif (Variable::TYPE_FLOAT === $tsArg->type) {
                    $timestampMs = $tsArg->toFloat();
                } else {
                    $timestampMs = (float) VmIntlDateFormatter::coerceIntArg(
                        $tsArg,
                        'intlcal_is_weekend',
                        1,
                        'timestamp'
                    );
                }
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isWeekend($cal, $timestampMs));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_is_weekend() is not implemented for JIT in this compiler build (issue #20895)');
    }
}