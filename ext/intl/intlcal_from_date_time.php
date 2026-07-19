<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intlcal_from_date_time() — procedural IntlCalendar::fromDateTime
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_from_date_time extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_from_date_time');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_from_date_time() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg0->type) {
            $datetime = DateTimeSupport::requireDateTime(
                $arg0,
                'intlcal_from_date_time',
                1,
                'datetime',
                $frame->vmContext
            );
        } elseif (Variable::TYPE_STRING === $arg0->type) {
            $datetime = $arg0->toString();
        } else {
            $datetime = VmString::coerceStringBuiltinArg(
                $arg0,
                'intlcal_from_date_time',
                1,
                'datetime'
            );
        }
        $locale = null;
        if ($argc >= 2) {
            $localeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'intlcal_from_date_time',
                    2,
                    'locale'
                );
            }
        }
        $cal = VmIntlCalendar::fromDateTime($frame->vmContext, $datetime, $locale);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $cal) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($cal);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_from_date_time() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
