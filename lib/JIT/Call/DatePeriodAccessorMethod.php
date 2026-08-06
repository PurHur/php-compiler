<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DatePeriodIteratorJitHelper;
use PHPLLVM\Value;

/**
 * DatePeriod accessors — getStartDate / getEndDate / getDateInterval / getRecurrences (#27572).
 *
 * php-src: ext/date/php_date.c — date_period_get_*
 */
final class DatePeriodAccessorMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DatePeriod::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            'getstartdate' => DatePeriodIteratorJitHelper::compileGetStartDate($context, $args[0]),
            'getenddate' => DatePeriodIteratorJitHelper::compileGetEndDate($context, $args[0]),
            'getdateinterval' => DatePeriodIteratorJitHelper::compileGetDateInterval($context, $args[0]),
            'getrecurrences' => DatePeriodIteratorJitHelper::compileGetRecurrences($context, $args[0]),
            default => throw new \LogicException(
                'DatePeriod accessor JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
