<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::getEndDate() — php-src date_period_get_end_date (#17495). */
final class DatePeriodGetEndDate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getEndDate');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DatePeriod::getEndDate() called without $this');
        }
        // php-src zim_DatePeriod_getEndDate — ZEND_PARSE_PARAMETERS_NONE (#30934).
        $this->requireExactUserArgCount($frame, 'DatePeriod::getEndDate', 0);
        $receiver = DatePeriodSupport::requireDatePeriod($frame->calledArgs[0], 'DatePeriod::getEndDate()');
        if (null === $frame->returnVar) {
            return;
        }
        $end = DatePeriodSupport::getEndDate($receiver, $frame->vmContext);
        if (null === $end) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($end);
        }
    }
}
