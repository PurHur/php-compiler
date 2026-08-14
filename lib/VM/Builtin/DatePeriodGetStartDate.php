<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::getStartDate() — php-src date_period_get_start_date (#16614). */
final class DatePeriodGetStartDate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStartDate');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DatePeriod::getStartDate() called without $this');
        }
        // php-src zim_DatePeriod_getStartDate — ZEND_PARSE_PARAMETERS_NONE (#30934).
        $this->requireExactUserArgCount($frame, 'DatePeriod::getStartDate', 0);
        $receiver = DatePeriodSupport::requireDatePeriod($frame->calledArgs[0], 'DatePeriod::getStartDate()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(DatePeriodSupport::getStartDate($receiver));
    }
}
