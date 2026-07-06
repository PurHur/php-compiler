<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DatePeriodIteratorJitHelper;
use PHPLLVM\Value;

/** DatePeriod Iterator protocol — JIT/AOT (#14228, #16796, ext/date/php_date.c). */
final class DatePeriodIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DatePeriod::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            'rewind' => DatePeriodIteratorJitHelper::compileRewind($context, $args[0]),
            'valid' => DatePeriodIteratorJitHelper::compileValid($context, $args[0]),
            'current' => DatePeriodIteratorJitHelper::compileCurrent($context, $args[0]),
            'key' => DatePeriodIteratorJitHelper::compileKey($context, $args[0]),
            'next' => DatePeriodIteratorJitHelper::compileNext($context, $args[0]),
            default => throw new \LogicException('DatePeriod iterator JIT lowering missing for '.$this->method.'()'),
        };
    }
}
