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
 * intlcal_get_error_message() — procedural IntlCalendar::getErrorMessage
 * (php-src calendar_methods.c / calendar.stub.php; #20895).
 */
final class intlcal_get_error_message extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_get_error_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_get_error_message() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_get_error_message',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $msg = VmIntlCalendar::getErrorMessage($cal);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $msg) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($msg);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_get_error_message() is not implemented for JIT in this compiler build (issue #20895)');
    }
}