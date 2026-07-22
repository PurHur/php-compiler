<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\spl\InternalIteratorBuiltin;
use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * DatePeriod::getIterator() — InternalIterator over period dates (php-src ext/date/php_date.c; #22263).
 *
 * Snapshots the walk at call time (period config is immutable after construct).
 */
final class DatePeriodGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DatePeriod::getIterator() called without $this');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('DatePeriod::getIterator() requires VM context');
        }
        $period = DatePeriodSupport::requireDatePeriod(
            $frame->calledArgs[0],
            'DatePeriod::getIterator()'
        );

        $savedState = $period->datePeriodIterator;
        $savedCurrent = new Variable();
        $savedCurrent->copyFrom($period->getProperty('current')->resolveIndirect());

        $table = new HashTable();
        DatePeriodSupport::iteratorRewind($period);
        while (DatePeriodSupport::iteratorValid($period)) {
            $current = DatePeriodSupport::iteratorCurrent($period);
            $v = new Variable();
            if (null === $current) {
                $v->null();
            } else {
                $v->object($current);
            }
            $table->append($v);
            DatePeriodSupport::iteratorNext($period);
        }

        $period->datePeriodIterator = $savedState;
        $period->getProperty('current')->copyFrom($savedCurrent);

        $frame->returnVar->object(InternalIteratorBuiltin::fromTable($ctx, $table));
    }
}
