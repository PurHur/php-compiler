<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::getDateInterval() — php-src date_period_get_date_interval (#16614). */
final class DatePeriodGetDateInterval extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDateInterval');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DatePeriod::getDateInterval() called without $this');
        }
        // php-src zim_DatePeriod_getDateInterval — ZEND_PARSE_PARAMETERS_NONE (#30934).
        $this->requireExactUserArgCount($frame, 'DatePeriod::getDateInterval', 0);
        $receiver = DatePeriodSupport::requireDatePeriod($frame->calledArgs[0], 'DatePeriod::getDateInterval()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            DatePeriodSupport::getDateInterval($receiver, $frame->vmContext)
        );
    }
}
