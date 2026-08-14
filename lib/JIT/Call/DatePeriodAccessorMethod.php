<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\DatePeriodIteratorJitHelper;
use PHPLLVM\Value;

/**
 * DatePeriod accessors — getStartDate / getEndDate / getDateInterval / getRecurrences (#27572, #30934).
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
        // php-src ZEND_PARSE_PARAMETERS_NONE (#30934) — $args[0] is $this.
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            $label = match (strtolower($this->method)) {
                'getstartdate' => 'DatePeriod::getStartDate',
                'getenddate' => 'DatePeriod::getEndDate',
                'getdateinterval' => 'DatePeriod::getDateInterval',
                'getrecurrences' => 'DatePeriod::getRecurrences',
                default => 'DatePeriod::'.$this->method,
            };
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    $label,
                    0,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dateperiod_accessor_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
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
