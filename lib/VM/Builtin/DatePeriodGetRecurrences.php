<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::getRecurrences() — php-src date_period_get_recurrences (#16614). */
final class DatePeriodGetRecurrences extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRecurrences');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DatePeriod::getRecurrences() called without $this');
        }
        // php-src zim_DatePeriod_getRecurrences — ZEND_PARSE_PARAMETERS_NONE (#30934).
        $this->requireExactUserArgCount($frame, 'DatePeriod::getRecurrences', 0);
        $receiver = DatePeriodSupport::requireDatePeriod($frame->calledArgs[0], 'DatePeriod::getRecurrences()');
        if (null === $frame->returnVar) {
            return;
        }
        $recurrences = DatePeriodSupport::getRecurrences($receiver);
        if (null === $recurrences) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->int($recurrences);
        }
    }
}
